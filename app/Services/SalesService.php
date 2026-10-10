<?php

namespace App\Services;

use App\Models\BanquetEvent;
use App\Models\Company;
use App\Models\RateContract;
use App\Models\RoomBlock;
use App\Models\SalesActivity;
use App\Models\SalesContact;
use App\Models\SalesOpportunity;
use App\Models\SalesTarget;
use App\Models\Setting;
use App\Models\User;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v6.1 Sales & account management.
 *
 * Accounts are the companies / travel agents master (prospects included, never approved for direct billing until the
 * credit controller does so). Each account has a manager (a salesperson), a tier and a sales status, contacts, a diary
 * of sales activities and a pipeline of opportunities.
 *
 * Opportunities move prospect → qualified → proposal → negotiation → won | lost. Open stages carry a default win
 * probability (settings) that can be overridden; weighted value = total expected revenue × probability. A won
 * corporate / agency / long-stay deal can draft its rate contract; groups and events are linked to the room block or
 * banquet event they became. Lost deals record the reason (and the competitor).
 *
 * Production = what an account actually brought in: room nights of checked-in / checked-out stays in the period and the
 * room revenue posted to their folios in the period, plus on-the-books (reserved) nights × rate from the business date on.
 *
 * Salespeople (sales.edit) change only their own accounts, opportunities and activities; sales.manage can change all,
 * reassign, set targets and settings.
 */
class SalesService
{
    public const DEFAULTS = [
        'sales_prob_prospect' => '10', 'sales_prob_qualified' => '25', 'sales_prob_proposal' => '50', 'sales_prob_negotiation' => '75',
        'sales_stale_days' => '21',     // open opportunities with no completed activity for this long are flagged
        'sales_dormant_days' => '90',   // key / major accounts with no completed activity for this long are flagged
        'sales_followup_days' => '7',   // default follow-up date offered after an activity
        'sales_birthday_days' => '14',  // contact birthdays shown this many days ahead
    ];

    protected static array $memo = [];

    public static function flush(): void
    {
        self::$memo = [];
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
    }

    public static function cfg(): array
    {
        return self::$memo['cfg'] ??= (function () {
            $s = [];
            foreach (self::DEFAULTS as $k => $v) {
                $s[substr($k, 6)] = (int) setting($k, $v);
            }

            return $s;
        })();
    }

    public static function saveSettings(array $d): void
    {
        $p = array_map(fn ($s) => (int) ($d['prob_'.$s] ?? 0), SalesOpportunity::OPEN);
        foreach ($p as $v) {
            if ($v < 1 || $v > 99) {
                throw new RuntimeException('Stage probabilities are between 1 and 99 %.');
            }
        }
        for ($i = 1; $i < count($p); $i++) {
            if ($p[$i] <= $p[$i - 1]) {
                throw new RuntimeException('Each stage must have a higher probability than the one before it.');
            }
        }
        foreach (['stale_days' => [3, 365], 'dormant_days' => [14, 730], 'followup_days' => [1, 90], 'birthday_days' => [0, 60]] as $k => [$min, $max]) {
            $v = (int) ($d[$k] ?? -1);
            if ($v < $min || $v > $max) {
                throw new RuntimeException(ucfirst(str_replace('_', ' ', $k))." must be between $min and $max.");
            }
        }
        foreach (self::DEFAULTS as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) (int) $d[substr($k, 6)]]);
        }
        Setting::$version++;
        self::flush();
        ActivityLogger::log('sales', 'settings', 'Sales settings updated');
    }

    public static function probability(string $stage): int
    {
        return match ($stage) {
            'won' => 100,
            'lost' => 0,
            default => self::cfg()['prob_'.$stage] ?? 10,
        };
    }

    protected static function today(): string
    {
        return bdate()->toDateString();
    }

    // ───────────────────────── who may change what ─────────────────────────

    public static function canEdit(?User $u, ?int $ownerId): bool
    {
        if (! $u) {
            return false;
        }
        if ($u->hasPermission('sales.manage')) {
            return true;
        }

        return $u->hasPermission('sales.edit') && ($ownerId === null || $ownerId === $u->id);
    }

    public static function assertCanEdit(User $u, ?int $ownerId, string $what): void
    {
        if (! self::canEdit($u, $ownerId)) {
            throw new RuntimeException("This {$what} belongs to another salesperson – ask them or a sales manager.");
        }
    }

    /** users who can own accounts, opportunities and activities */
    public static function sellers(): Collection
    {
        // this property's salespeople – platform / group administrators only when they actually own business
        return self::$memo['sellers'] ??= (function () {
            $owning = SalesOpportunity::pluck('owner_id')->merge(Company::pluck('account_manager_id'))->filter()->unique()->all();

            return User::where('hotel_id', hotel()->id)->where('is_active', true)->orderBy('name')->get()
                ->filter(fn ($u) => ((! $u->is_super_admin && ! $u->is_group_admin) || in_array($u->id, $owning, true) || $u->id === auth()->id())
                    && ($u->hasPermission('sales.edit') || $u->hasPermission('sales.manage')))->values();
        })();
    }

    protected static function owner(array $d, string $key, User $u, ?int $current = null): int
    {
        $id = ! empty($d[$key]) ? (int) $d[$key] : ($current ?? $u->id);
        $o = User::where('hotel_id', hotel()->id)->where('is_active', true)->find($id) ?? throw new RuntimeException('Choose an active salesperson.');
        if ($o->id !== $u->id && $o->id !== $current && ! $u->hasPermission('sales.manage')) {
            throw new RuntimeException('Only a sales manager can assign work to someone else.');
        }

        return $o->id;
    }

    // ───────────────────────── accounts & contacts ─────────────────────────

    public static function saveAccount(array $d, ?Company $c, User $u): Company
    {
        if ($c) {
            self::assertCanEdit($u, $c->account_manager_id, 'account');
        }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Give the account a name.');
        }
        if (Company::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->when($c, fn ($q) => $q->where('id', '!=', $c->id))->exists()) {
            throw new RuntimeException("An account called {$name} already exists.");
        }
        $type = $d['type'] ?? 'corporate';
        if (! in_array($type, ['corporate', 'travel_agent', 'ota'], true) || (! $c && $type === 'ota')) {
            throw new RuntimeException('Choose corporate or travel agent.');
        }
        $tier = $d['tier'] ?? 'standard';
        $status = $d['sales_status'] ?? ($c ? $c->sales_status : 'prospect');
        if (! isset(Company::TIERS[$tier]) || ! isset(Company::SALES_STATUSES[$status])) {
            throw new RuntimeException('Choose a tier and a sales status.');
        }
        $industry = ($d['industry'] ?? '') ?: null;
        if ($industry && ! isset(Company::INDUSTRIES[$industry])) {
            throw new RuntimeException('Unknown industry.');
        }
        $email = trim((string) ($d['email'] ?? '')) ?: null;
        if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Check the e-mail address.');
        }
        $pot = ($d['potential_nights'] ?? '') === '' || $d['potential_nights'] === null ? null : (int) $d['potential_nights'];
        if ($pot !== null && ($pot < 0 || $pot > 1000000)) {
            throw new RuntimeException('Potential is a number of room nights a year.');
        }
        $data = ['name' => mb_substr($name, 0, 255), 'type' => $type, 'tier' => $tier, 'sales_status' => $status, 'industry' => $industry,
            'contact_person' => trim((string) ($d['contact_person'] ?? '')) ?: null, 'phone' => trim((string) ($d['phone'] ?? '')) ?: null, 'email' => $email,
            'address' => trim((string) ($d['address'] ?? '')) ?: null, 'city' => trim((string) ($d['city'] ?? '')) ?: null,
            'website' => trim((string) ($d['website'] ?? '')) ?: null, 'potential_nights' => $pot, 'sales_notes' => $d['sales_notes'] ?? null,
            'account_manager_id' => self::owner($d, 'account_manager_id', $u, $c?->account_manager_id)];
        if ($c) {
            $c->update($data);
            ActivityLogger::log('sales', 'account', "Account {$c->name} updated", $c);
        } else {
            // prospects are not approved for direct billing – credit control does that when the account is opened
            $c = Company::create($data + ['direct_billing' => false, 'credit_limit' => 0, 'is_active' => true]);
            ActivityLogger::log('sales', 'account', "Account {$c->name} added ({$c->salesStatusLabel()}, {$c->tierLabel()})", $c);
        }
        self::flush();

        return $c;
    }

    public static function saveContact(Company $c, array $d, ?SalesContact $x, User $u): SalesContact
    {
        self::assertCanEdit($u, $c->account_manager_id, 'account');
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Give the contact a name.');
        }
        $email = trim((string) ($d['email'] ?? '')) ?: null;
        if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Check the e-mail address.');
        }
        $bday = ($d['birthday'] ?? '') ?: null;
        if ($bday && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $bday) || $bday >= self::today())) {
            throw new RuntimeException('Check the birthday.');
        }
        $data = ['name' => mb_substr($name, 0, 120), 'title' => trim((string) ($d['title'] ?? '')) ?: null, 'email' => $email,
            'phone' => trim((string) ($d['phone'] ?? '')) ?: null, 'birthday' => $bday, 'notes' => mb_substr((string) ($d['notes'] ?? ''), 0, 500) ?: null,
            'is_primary' => (bool) ($d['is_primary'] ?? false), 'decision_maker' => (bool) ($d['decision_maker'] ?? false), 'is_active' => (bool) ($d['is_active'] ?? true)];

        return DB::transaction(function () use ($c, $data, $x) {
            if ($data['is_primary']) {
                SalesContact::where('company_id', $c->id)->when($x, fn ($q) => $q->where('id', '!=', $x->id))->update(['is_primary' => false]);
                // the primary contact is also the account's contact on folios and statements
                $c->update(['contact_person' => $data['name'], 'phone' => $data['phone'] ?? $c->phone, 'email' => $data['email'] ?? $c->email]);
            }
            if ($x) {
                abort_unless($x->company_id === $c->id, 404);
                $x->update($data);
            } else {
                $x = $c->contacts()->create($data);
            }
            ActivityLogger::log('sales', 'contact', "Contact {$x->name} saved for {$c->name}", $c);

            return $x;
        });
    }

    /** contacts used in the diary or on a deal are deactivated rather than deleted */
    public static function deleteContact(SalesContact $x, User $u): string
    {
        self::assertCanEdit($u, $x->company->account_manager_id, 'account');
        if ($x->activities()->exists() || SalesOpportunity::where('sales_contact_id', $x->id)->exists()) {
            $x->update(['is_active' => false, 'is_primary' => false]);

            return "{$x->name} has history, so the contact was deactivated.";
        }
        $x->delete();

        return "{$x->name} deleted.";
    }

    // ───────────────────────── opportunities ─────────────────────────

    public static function saveOpportunity(array $d, ?SalesOpportunity $o, User $u): SalesOpportunity
    {
        if ($o) {
            self::assertCanEdit($u, $o->owner_id, 'opportunity');
            if (! $o->isOpen()) {
                throw new RuntimeException("{$o->number} is {$o->stageLabel()} – reopen it to change it.");
            }
        }
        $c = Company::find((int) ($d['company_id'] ?? 0)) ?? throw new RuntimeException('Choose the account.');
        $contact = ! empty($d['sales_contact_id']) ? SalesContact::where('company_id', $c->id)->find((int) $d['sales_contact_id']) : null;
        if (! empty($d['sales_contact_id']) && ! $contact) {
            throw new RuntimeException('That contact does not belong to the account.');
        }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Give the opportunity a name.');
        }
        $type = $d['type'] ?? '';
        $source = $d['source'] ?? 'prospecting';
        if (! isset(SalesOpportunity::TYPES[$type]) || ! isset(SalesOpportunity::SOURCES[$source])) {
            throw new RuntimeException('Choose the type and the source.');
        }
        $close = (string) ($d['expected_close'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $close)) {
            throw new RuntimeException('Enter the expected close date.');
        }
        if ($close < self::today() && (! $o || $o->expected_close->toDateString() !== $close)) {
            throw new RuntimeException('The expected close date cannot be in the past.');
        }
        $arr = ($d['arrival'] ?? '') ?: null;
        $dep = ($d['departure'] ?? '') ?: null;
        if (($arr === null) !== ($dep === null)) {
            throw new RuntimeException('Enter both dates (or neither).');
        }
        if ($arr && $dep <= $arr) {
            throw new RuntimeException('The end date must be after the start date.');
        }
        if (in_array($type, ['group', 'mice', 'social'], true) && ! $arr) {
            throw new RuntimeException('Groups and events need their dates.');
        }
        $nights = (int) ($d['room_nights'] ?? 0);
        $money = [];
        foreach (['rooms_revenue', 'fb_revenue', 'other_revenue'] as $k) {
            $money[$k] = round((float) ($d[$k] ?? 0), 2);
            if ($money[$k] < 0 || $money[$k] > 1e11) {
                throw new RuntimeException('Revenue figures cannot be negative.');
            }
        }
        if ($nights < 0 || $nights > 100000) {
            throw new RuntimeException('Room nights must be between 0 and 100,000.');
        }
        if ($money['rooms_revenue'] > 0 && $nights === 0) {
            throw new RuntimeException('Enter the room nights behind the rooms revenue.');
        }
        $stage = $o?->stage ?? ($d['stage'] ?? 'prospect');
        if (! in_array($stage, SalesOpportunity::OPEN, true)) {
            throw new RuntimeException('New opportunities start in an open stage.');
        }
        $prob = ($d['probability'] ?? '') === '' || $d['probability'] === null ? null : (int) $d['probability'];
        if ($prob !== null && ($prob < 1 || $prob > 99)) {
            throw new RuntimeException('Probability is between 1 and 99 % for an open opportunity.');
        }
        $mev = ! empty($d['marketing_event_id']) ? \App\Models\MarketingEvent::find((int) $d['marketing_event_id']) ?? throw new RuntimeException('Marketing event not found.') : null;
        $data = ['company_id' => $c->id, 'sales_contact_id' => $contact?->id, 'marketing_event_id' => $mev?->id, 'owner_id' => self::owner($d, 'owner_id', $u, $o?->owner_id),
            'name' => mb_substr($name, 0, 150), 'type' => $type, 'source' => $source, 'expected_close' => $close, 'arrival' => $arr, 'departure' => $dep,
            'room_nights' => $nights, 'competitors' => trim((string) ($d['competitors'] ?? '')) ?: null, 'notes' => $d['notes'] ?? null,
            'probability' => $prob ?? ($o ? $o->probability : self::probability($stage))] + $money;
        if ($o) {
            $o->update($data);
            ActivityLogger::log('sales', 'opportunity', "{$o->number} {$o->name} updated", $o);
        } else {
            $o = SalesOpportunity::create($data + ['number' => Numbering::next('sales_opportunity'), 'stage' => $stage, 'stage_changed_at' => now(), 'created_by' => $u->id]);
            if ($c->sales_status === 'dormant' || $c->sales_status === 'lost') {
                $c->update(['sales_status' => 'prospect']);
            }
            ActivityLogger::log('sales', 'opportunity', "{$o->number} {$o->name} opened for {$c->name} – ".money($o->total()), $o);
        }
        self::flush();

        return $o;
    }

    public static function moveStage(SalesOpportunity $o, string $stage, User $u, array $x = []): SalesOpportunity
    {
        self::assertCanEdit($u, $o->owner_id, 'opportunity');
        if (! $o->isOpen()) {
            throw new RuntimeException("{$o->number} is {$o->stageLabel()} – reopen it first.");
        }
        if (! isset(SalesOpportunity::STAGES[$stage])) {
            throw new RuntimeException('Unknown stage.');
        }
        if ($stage === $o->stage) {
            throw new RuntimeException("{$o->number} is already at {$o->stageLabel()}.");
        }
        if (in_array($stage, ['proposal', 'negotiation', 'won'], true) && $o->total() <= 0) {
            throw new RuntimeException('Enter the expected revenue before sending a proposal or closing the deal.');
        }
        $from = $o->stageLabel();
        $upd = ['stage' => $stage, 'stage_changed_at' => now(), 'probability' => self::probability($stage)];
        if ($stage === 'lost') {
            $reason = $x['lost_reason'] ?? '';
            if (! isset(SalesOpportunity::LOST_REASONS[$reason])) {
                throw new RuntimeException('Choose why the business was lost.');
            }
            $note = trim((string) ($x['lost_note'] ?? ''));
            if (in_array($reason, ['competitor', 'other'], true) && $note === '') {
                throw new RuntimeException($reason === 'competitor' ? 'Which hotel won the business?' : 'Say why the business was lost.');
            }
            $upd += ['lost_reason' => $reason, 'lost_note' => mb_substr($note, 0, 255) ?: null, 'closed_on' => self::today()];
        } elseif ($stage === 'won') {
            $upd += ['closed_on' => self::today(), 'lost_reason' => null, 'lost_note' => null];
        } elseif (isset($x['probability']) && $x['probability'] !== '') {
            $p = (int) $x['probability'];
            if ($p < 1 || $p > 99) {
                throw new RuntimeException('Probability is between 1 and 99 %.');
            }
            $upd['probability'] = $p;
        }
        DB::transaction(function () use ($o, $upd, $stage) {
            $o->forceFill($upd)->save();
            if ($stage === 'won' && in_array($o->company->sales_status, ['prospect', 'dormant', 'lost'], true)) {
                $o->company->update(['sales_status' => 'active']);
            }
            if ($stage === 'lost') {
                $o->activities()->where('status', 'planned')->update(['status' => 'cancelled', 'outcome' => 'Cancelled – opportunity lost']);
            }
        });
        self::flush();
        ActivityLogger::log('sales', 'stage', "{$o->number} {$o->name}: {$from} → {$o->stageLabel()}".($stage === 'lost' ? ' ('.SalesOpportunity::LOST_REASONS[$o->lost_reason].')' : '')
            .($stage === 'won' ? ' – '.money($o->total()) : ''), $o);

        return $o;
    }

    public static function reopen(SalesOpportunity $o, User $u): SalesOpportunity
    {
        self::assertCanEdit($u, $o->owner_id, 'opportunity');
        if ($o->isOpen()) {
            throw new RuntimeException("{$o->number} is still open.");
        }
        if ($o->rate_contract_id || $o->room_block_id || $o->banquet_event_id) {
            throw new RuntimeException('Unlink the contract / block / event first.');
        }
        $was = $o->stageLabel();
        $o->forceFill(['stage' => 'negotiation', 'probability' => self::probability('negotiation'), 'closed_on' => null, 'lost_reason' => null, 'lost_note' => null,
            'stage_changed_at' => now(), 'expected_close' => max($o->expected_close->toDateString(), self::today())])->save();
        self::flush();
        ActivityLogger::log('sales', 'reopen', "{$o->number} {$o->name} reopened (was {$was})", $o);

        return $o;
    }

    /** link a won opportunity to what it became (null id = unlink) */
    public static function link(SalesOpportunity $o, string $kind, ?int $id, User $u): void
    {
        self::assertCanEdit($u, $o->owner_id, 'opportunity');
        if ($o->stage !== 'won') {
            throw new RuntimeException('Only won business is linked to a contract, block or event.');
        }
        [$col, $model, $label] = match ($kind) {
            'contract' => ['rate_contract_id', RateContract::class, 'rate contract'],
            'block' => ['room_block_id', RoomBlock::class, 'room block'],
            'event' => ['banquet_event_id', BanquetEvent::class, 'banquet event'],
            default => throw new RuntimeException('Unknown link.'),
        };
        if ($id) {
            $rec = $model::find($id) ?? throw new RuntimeException("That {$label} was not found.");
            if ($rec->company_id && (int) $rec->company_id !== (int) $o->company_id) {
                throw new RuntimeException("That {$label} is for another account.");
            }
        }
        $o->forceFill([$col => $id ?: null])->save();
        ActivityLogger::log('sales', 'link', "{$o->number}: {$label} ".($id ? 'linked' : 'unlinked'), $o);
    }

    /** a won corporate / agency / long-stay deal drafts its rate contract (approval and rates happen in Rate Contracts) */
    public static function draftContract(SalesOpportunity $o, User $u): RateContract
    {
        self::assertCanEdit($u, $o->owner_id, 'opportunity');
        if ($o->stage !== 'won') {
            throw new RuntimeException('Win the opportunity first.');
        }
        if (! in_array($o->type, SalesOpportunity::CONTRACT_TYPES, true)) {
            throw new RuntimeException('Groups and events are linked to their room block or banquet event instead.');
        }
        if ($o->rate_contract_id) {
            throw new RuntimeException("{$o->number} already has a contract.");
        }
        $c = $o->company;
        $from = $o->arrival?->toDateString() ?? self::today();
        $to = $o->departure?->toDateString() ?? Carbon::parse($from)->addYear()->subDay()->toDateString();
        $base = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($c->name)), 0, 10) ?: 'ACC';
        $code = $base.'-'.substr($from, 0, 4);
        for ($i = 2; RateContract::where('code', $code)->exists(); $i++) {
            $code = $base.'-'.substr($from, 0, 4).'-'.$i;
        }

        return DB::transaction(function () use ($o, $c, $u, $from, $to, $code) {
            $k = RateContract::create(['company_id' => $c->id, 'code' => $code, 'name' => mb_substr($c->name.' '.substr($from, 0, 4).' '.($o->type === 'agency' ? 'agency' : 'corporate').' rates', 0, 120),
                'valid_from' => $from, 'valid_to' => $to, 'status' => 'draft', 'pricing' => 'discount', 'discount_pct' => (float) $c->discount_pct > 0 ? $c->discount_pct : 10,
                'meal_plan' => 'CP', 'cancel_days' => 1, 'commission_pct' => $o->type === 'agency' ? (float) $c->commission_pct : 0, 'room_night_target' => $o->room_nights ?: null,
                'notes' => "Drafted from {$o->number} {$o->name}. Check the rates, then send for approval.", 'created_by' => $u->id]);
            $o->forceFill(['rate_contract_id' => $k->id])->save();
            ActivityLogger::log('contracts', 'create', "Drafted contract {$k->code} for {$c->name} from sales opportunity {$o->number}", $k);

            return $k;
        });
    }

    // ───────────────────────── activities ─────────────────────────

    public static function saveActivity(array $d, ?SalesActivity $a, User $u): SalesActivity
    {
        if ($a) {
            self::assertCanEdit($u, $a->owner_id, 'activity');
            if ($a->status !== 'planned') {
                throw new RuntimeException('Only planned activities can be changed.');
            }
        }
        if (! isset(SalesActivity::TYPES[$d['type'] ?? ''])) {
            throw new RuntimeException('Choose the type of activity.');
        }
        $subject = trim((string) ($d['subject'] ?? ''));
        if ($subject === '') {
            throw new RuntimeException('Give the activity a subject.');
        }
        $opp = ! empty($d['sales_opportunity_id']) ? SalesOpportunity::find((int) $d['sales_opportunity_id']) ?? throw new RuntimeException('Opportunity not found.') : null;
        $company = ! empty($d['company_id']) ? Company::find((int) $d['company_id']) ?? throw new RuntimeException('Account not found.') : $opp?->company;
        if ($opp && $company && $opp->company_id !== $company->id) {
            throw new RuntimeException('That opportunity belongs to another account.');
        }
        if (! $company && in_array($d['type'], ['visit', 'site_inspection', 'entertainment'], true)) {
            throw new RuntimeException('Choose the account.');
        }
        $contact = ! empty($d['sales_contact_id']) ? SalesContact::where('company_id', $company?->id ?? 0)->find((int) $d['sales_contact_id']) : null;
        if (! empty($d['sales_contact_id']) && ! $contact) {
            throw new RuntimeException('That contact does not belong to the account.');
        }
        $date = (string) ($d['date'] ?? '');
        $time = (string) (($d['time'] ?? '') ?: '09:00');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new RuntimeException('Enter the date and time.');
        }
        $done = ($d['status'] ?? 'planned') === 'done' && ! $a;
        if ($done && $date > self::today()) {
            throw new RuntimeException('A completed activity cannot be in the future – plan it instead.');
        }
        if (! $done && $date < self::today() && (! $a || $a->due_at->toDateString() !== $date)) {
            throw new RuntimeException('Planned activities are for today or later – record past ones as done.');
        }
        $outcome = trim((string) ($d['outcome'] ?? ''));
        if ($done && $outcome === '') {
            throw new RuntimeException('Record the outcome.');
        }
        $cost = round((float) ($d['cost'] ?? 0), 2);
        $dur = ($d['duration_min'] ?? '') === '' || $d['duration_min'] === null ? null : (int) $d['duration_min'];
        if ($cost < 0 || ($dur !== null && ($dur < 0 || $dur > 1440))) {
            throw new RuntimeException('Check the duration and the cost.');
        }
        if ($opp) {
            self::assertCanEdit($u, $opp->owner_id, 'opportunity');
        }
        $data = ['type' => $d['type'], 'subject' => mb_substr($subject, 0, 190), 'company_id' => $company?->id, 'sales_contact_id' => $contact?->id,
            'sales_opportunity_id' => $opp?->id, 'owner_id' => self::owner($d, 'owner_id', $u, $a?->owner_id), 'due_at' => "$date $time:00", 'duration_min' => $dur, 'cost' => $cost];

        return DB::transaction(function () use ($a, $data, $done, $outcome, $d, $u) {
            if ($a) {
                $a->update($data);
            } else {
                $a = SalesActivity::create($data + ['status' => $done ? 'done' : 'planned', 'done_at' => $done ? $data['due_at'] : null, 'outcome' => $done ? $outcome : null, 'created_by' => $u->id]);
            }
            self::followUp($a, $d, $u);
            self::flush();
            ActivityLogger::log('sales', 'activity', "{$a->typeLabel()} ".($done ? 'logged' : 'planned').": {$a->subject}".($a->company ? " ({$a->company->name})" : ''), $a->company ?? $a);

            return $a;
        });
    }

    public static function complete(SalesActivity $a, array $d, User $u): SalesActivity
    {
        self::assertCanEdit($u, $a->owner_id, 'activity');
        if ($a->status !== 'planned') {
            throw new RuntimeException('This activity is already '.strtolower($a->statusLabel()).'.');
        }
        if ($a->due_at->toDateString() > self::today()) {
            throw new RuntimeException('This activity is planned for '.dmy($a->due_at).' – it cannot be done yet.');
        }
        $outcome = trim((string) ($d['outcome'] ?? ''));
        if ($outcome === '') {
            throw new RuntimeException('Record the outcome.');
        }
        $cost = isset($d['cost']) && $d['cost'] !== '' ? round((float) $d['cost'], 2) : (float) $a->cost;
        if ($cost < 0) {
            throw new RuntimeException('The cost cannot be negative.');
        }

        return DB::transaction(function () use ($a, $outcome, $cost, $d, $u) {
            $a->forceFill(['status' => 'done', 'done_at' => now(), 'outcome' => $outcome, 'cost' => $cost])->save();
            self::followUp($a, $d, $u);
            self::flush();
            ActivityLogger::log('sales', 'activity_done', "{$a->typeLabel()} done: {$a->subject}".($a->company ? " ({$a->company->name})" : ''), $a->company ?? $a);

            return $a;
        });
    }

    public static function cancelActivity(SalesActivity $a, string $reason, User $u): void
    {
        self::assertCanEdit($u, $a->owner_id, 'activity');
        if ($a->status !== 'planned') {
            throw new RuntimeException('Only planned activities can be cancelled.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason.');
        }
        $a->forceFill(['status' => 'cancelled', 'outcome' => 'Cancelled: '.mb_substr(trim($reason), 0, 250)])->save();
        self::flush();
        ActivityLogger::log('sales', 'activity_cancel', "{$a->typeLabel()} cancelled: {$a->subject} – {$reason}", $a->company ?? $a);
    }

    /** an optional next step planned from the same form: follow_type + follow_date (+ follow_time, follow_subject) */
    protected static function followUp(SalesActivity $a, array $d, User $u): ?SalesActivity
    {
        if (empty($d['follow_type'])) {
            return null;
        }
        if (! isset(SalesActivity::TYPES[$d['follow_type']])) {
            throw new RuntimeException('Choose the type of follow-up.');
        }
        $date = (string) ($d['follow_date'] ?? '');
        $time = (string) (($d['follow_time'] ?? '') ?: '10:00');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < self::today() || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new RuntimeException('The follow-up needs a date from today on.');
        }

        return SalesActivity::create(['type' => $d['follow_type'], 'subject' => mb_substr(trim((string) ($d['follow_subject'] ?? '')) ?: 'Follow up: '.$a->subject, 0, 190),
            'company_id' => $a->company_id, 'sales_contact_id' => $a->sales_contact_id, 'sales_opportunity_id' => $a->opportunity?->isOpen() ? $a->sales_opportunity_id : null,
            'owner_id' => $a->owner_id, 'due_at' => "$date $time:00", 'status' => 'planned', 'created_by' => $u->id]);
    }

    // ───────────────────────── pipeline, alerts, attention ─────────────────────────

    /** open pipeline by stage: count, value, weighted value, room nights */
    public static function pipeline(?int $ownerId = null): array
    {
        $opps = SalesOpportunity::whereIn('stage', SalesOpportunity::OPEN)->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))->get();
        $out = [];
        foreach (SalesOpportunity::OPEN as $s) {
            $g = $opps->where('stage', $s);
            $out[$s] = ['count' => $g->count(), 'value' => round($g->sum(fn ($o) => $o->total()), 2), 'weighted' => round($g->sum(fn ($o) => $o->weighted()), 2), 'nights' => (int) $g->sum('room_nights')];
        }
        $out['total'] = ['count' => $opps->count(), 'value' => round($opps->sum(fn ($o) => $o->total()), 2), 'weighted' => round($opps->sum(fn ($o) => $o->weighted()), 2), 'nights' => (int) $opps->sum('room_nights')];

        return $out;
    }

    /** company id => last completed activity time */
    public static function lastTouch(): Collection
    {
        return self::$memo['touch'] ??= SalesActivity::where('status', 'done')->whereNotNull('company_id')->groupBy('company_id')
            ->selectRaw('company_id, MAX(done_at) as last_at')->pluck('last_at', 'company_id')->map(fn ($v) => Carbon::parse($v));
    }

    /** company id => next planned activity */
    public static function nextPlanned(): Collection
    {
        return self::$memo['next'] ??= SalesActivity::where('status', 'planned')->whereNotNull('company_id')->orderBy('due_at')->get()->unique('company_id')->keyBy('company_id');
    }

    public static function staleOpportunities(?int $ownerId = null): Collection
    {
        $cut = bdate()->copy()->subDays(self::cfg()['stale_days'])->startOfDay();
        $last = SalesActivity::where('status', 'done')->whereNotNull('sales_opportunity_id')->groupBy('sales_opportunity_id')
            ->selectRaw('sales_opportunity_id, MAX(done_at) as last_at')->pluck('last_at', 'sales_opportunity_id');

        return SalesOpportunity::with('company', 'owner')->whereIn('stage', SalesOpportunity::OPEN)->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))->get()
            ->filter(function ($o) use ($cut, $last) {
                $touch = max(array_filter([$o->stage_changed_at, $o->created_at, isset($last[$o->id]) ? Carbon::parse($last[$o->id]) : null]));

                return $touch->lt($cut);
            })->values();
    }

    public static function dormantAccounts(?int $managerId = null): Collection
    {
        $cut = bdate()->copy()->subDays(self::cfg()['dormant_days'])->startOfDay();
        $touch = self::lastTouch();

        return Company::with('accountManager')->where('is_active', true)->whereIn('tier', ['key', 'major'])->where('sales_status', 'active')
            ->when($managerId, fn ($q) => $q->where('account_manager_id', $managerId))->orderBy('name')->get()
            ->filter(fn ($c) => ! isset($touch[$c->id]) || $touch[$c->id]->lt($cut))->values();
    }

    public static function birthdays(): Collection
    {
        $from = bdate()->copy()->startOfDay();
        $to = $from->copy()->addDays(self::cfg()['birthday_days']);

        return SalesContact::with('company')->where('is_active', true)->whereNotNull('birthday')->get()
            ->map(fn ($x) => ['contact' => $x, 'on' => $x->nextBirthday($from)])->filter(fn ($r) => $r['on']->lte($to))->sortBy(fn ($r) => $r['on']->toDateString())->values();
    }

    /** counts for the dashboard (whole team, or one salesperson) */
    public static function alerts(?int $ownerId = null): array
    {
        $key = 'alerts_'.($ownerId ?? 0);

        return self::$memo[$key] ??= [
            'overdue' => SalesActivity::where('status', 'planned')->where('due_at', '<', self::today().' 00:00:00')->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))->count(),
            'today' => SalesActivity::where('status', 'planned')->whereBetween('due_at', [self::today().' 00:00:00', self::today().' 23:59:59'])->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))->count(),
            'closing_overdue' => SalesOpportunity::whereIn('stage', SalesOpportunity::OPEN)->where('expected_close', '<', self::today())->when($ownerId, fn ($q) => $q->where('owner_id', $ownerId))->count(),
            'stale' => self::staleOpportunities($ownerId)->count(),
            'dormant' => self::dormantAccounts($ownerId)->count(),
        ];
    }

    /** the nav badge: the signed-in salesperson's overdue activities and deals past their expected close date */
    public static function attention(): int
    {
        $u = auth()->user();
        if (! $u) {
            return 0;
        }
        $a = self::alerts($u->id);

        return $a['overdue'] + $a['closing_overdue'];
    }

    // ───────────────────────── production & targets ─────────────────────────

    /**
     * company id => ['stays', 'nights', 'revenue', 'otb_nights', 'otb_revenue'] for stays in [from, to]:
     * actual = checked-in / out stays (room revenue posted in the period); on the books = reserved stays from the
     * business date on (nights × rate).
     */
    public static function production(Carbon $from, Carbon $to, ?array $companyIds = null): Collection
    {
        $F = $from->toDateString();
        $T = $to->toDateString();
        $end = $to->copy()->addDay()->startOfDay();
        $today = bdate()->copy()->startOfDay();
        $rows = DB::table('reservation_rooms as rr')->join('reservations as r', 'r.id', '=', 'rr.reservation_id')
            ->where('rr.hotel_id', hotel()->id)->whereNotNull('r.company_id')->when($companyIds !== null, fn ($q) => $q->whereIn('r.company_id', $companyIds ?: [0]))
            ->whereIn('rr.status', ['checked_in', 'checked_out', 'reserved'])->whereNotIn('r.status', ['cancelled', 'no_show'])
            ->where('rr.arrival_date', '<=', $T)->where('rr.departure_date', '>', $F)
            ->get(['r.company_id', 'rr.status', 'rr.arrival_date', 'rr.departure_date', 'rr.rate']);
        $rev = DB::table('folio_transactions as t')->join('folios as f', 'f.id', '=', 't.folio_id')->join('reservations as r', 'r.id', '=', 'f.reservation_id')
            ->where('t.hotel_id', hotel()->id)->whereNotNull('r.company_id')->when($companyIds !== null, fn ($q) => $q->whereIn('r.company_id', $companyIds ?: [0]))
            ->where('t.type', 'charge')->where('t.category', 'room')->where('t.is_void', false)->whereBetween('t.business_date', [$F, $T])
            ->groupBy('r.company_id')->selectRaw('r.company_id, SUM(t.amount) as amt')->pluck('amt', 'company_id');
        $out = [];
        foreach ($rows as $s) {
            $o = &$out[$s->company_id];
            $o ??= ['stays' => 0, 'nights' => 0, 'revenue' => 0.0, 'otb_nights' => 0, 'otb_revenue' => 0.0];
            $a = Carbon::parse($s->arrival_date)->startOfDay();
            $d = Carbon::parse($s->departure_date)->startOfDay();
            if ($s->status === 'reserved') {
                $start = max($a, $from->copy()->startOfDay(), $today);
                $n = max(0, (int) $start->diffInDays(min($d, $end)));
                $o['otb_nights'] += $n;
                $o['otb_revenue'] += $n * (float) $s->rate;
            } else {
                $n = max(0, (int) max($a, $from->copy()->startOfDay())->diffInDays(min($d, $end)));
                $o['nights'] += $n;
                $o['stays']++;
            }
            unset($o);
        }
        foreach ($rev as $cid => $amt) {
            $out[$cid] ??= ['stays' => 0, 'nights' => 0, 'revenue' => 0.0, 'otb_nights' => 0, 'otb_revenue' => 0.0];
            $out[$cid]['revenue'] = round((float) $amt, 2);
        }

        return collect($out)->map(fn ($o) => ['otb_revenue' => round($o['otb_revenue'], 2)] + $o);
    }

    /** twelve months of production for one account, oldest first: [label, nights, revenue, otb_nights, otb_revenue] */
    public static function monthly(Company $c, int $months = 12): array
    {
        $out = [];
        $start = bdate()->copy()->startOfMonth()->subMonthsNoOverflow($months - 3);   // include two months ahead (on the books)
        for ($i = 0; $i < $months; $i++) {
            $m = $start->copy()->addMonthsNoOverflow($i);
            $p = self::production($m->copy(), $m->copy()->endOfMonth(), [$c->id])->get($c->id, ['nights' => 0, 'revenue' => 0, 'otb_nights' => 0, 'otb_revenue' => 0]);
            $out[] = ['label' => $m->format('M y'), 'month' => $m->format('Y-m')] + $p;
        }

        return $out;
    }

    /** year to date vs the same period last year for one account */
    public static function ytd(Company $c): array
    {
        $t = bdate()->copy();
        $now = self::production($t->copy()->startOfYear(), $t->copy(), [$c->id])->get($c->id, ['nights' => 0, 'revenue' => 0]);
        $ly = self::production($t->copy()->subYear()->startOfYear(), $t->copy()->subYear(), [$c->id])->get($c->id, ['nights' => 0, 'revenue' => 0]);

        return ['nights' => $now['nights'], 'revenue' => $now['revenue'], 'ly_nights' => $ly['nights'], 'ly_revenue' => $ly['revenue']];
    }

    /** one month's targets with actuals: production of the accounts each salesperson manages, activities done, business won */
    public static function targets(string $period): Collection
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new RuntimeException('Choose a month.');
        }
        $from = Carbon::parse($period.'-01')->startOfDay();
        $to = $from->copy()->endOfMonth();
        $prod = self::production($from, $to);
        $accounts = Company::whereNotNull('account_manager_id')->get(['id', 'account_manager_id'])->groupBy('account_manager_id');
        $acts = SalesActivity::where('status', 'done')->whereBetween('done_at', [$from, $to->copy()->endOfDay()])->groupBy('owner_id')->selectRaw('owner_id, COUNT(*) n')->pluck('n', 'owner_id');
        $won = SalesOpportunity::where('stage', 'won')->whereBetween('closed_on', [$from->toDateString(), $to->toDateString()])->get()->groupBy('owner_id');
        $targets = SalesTarget::with('user')->where('period', $period)->get()->keyBy('user_id');
        $users = self::sellers()->keyBy('id')->union($targets->mapWithKeys(fn ($t) => [$t->user_id => $t->user])->filter());

        return $users->map(function ($u) use ($targets, $prod, $accounts, $acts, $won) {
            $t = $targets->get($u->id);
            $mine = collect($accounts->get($u->id, collect()))->pluck('id');
            $p = ['nights' => 0, 'revenue' => 0.0, 'otb_nights' => 0, 'otb_revenue' => 0.0];
            foreach ($mine as $cid) {
                foreach ($p as $k => $v) {
                    $p[$k] += $prod[$cid][$k] ?? 0;
                }
            }
            $rn = $p['nights'] + $p['otb_nights'];
            $rev = round($p['revenue'] + $p['otb_revenue'], 2);
            $n = (int) ($acts[$u->id] ?? 0);
            $pct = fn ($a, $b) => $b > 0 ? round($a * 100 / $b, 1) : null;

            return ['user' => $u, 'target' => $t, 'accounts' => $mine->count(), 'nights' => $p['nights'], 'otb_nights' => $p['otb_nights'], 'revenue' => round($p['revenue'], 2),
                'otb_revenue' => round($p['otb_revenue'], 2), 'activities' => $n, 'won' => round(collect($won->get($u->id, []))->sum(fn ($o) => $o->total()), 2),
                'pct_nights' => $pct($rn, (int) ($t?->room_nights)), 'pct_revenue' => $pct($rev, (float) ($t?->revenue)), 'pct_activities' => $pct($n, (int) ($t?->activities))];
        })->filter(fn ($r) => $r['target'] || $r['accounts'] || $r['activities'])->values();
    }

    public static function saveTargets(string $period, array $rows, User $u): int
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new RuntimeException('Choose a month.');
        }
        $n = 0;
        DB::transaction(function () use ($period, $rows, &$n) {
            foreach ($rows as $uid => $r) {
                $user = User::where('hotel_id', hotel()->id)->find((int) $uid) ?? throw new RuntimeException('Unknown salesperson.');
                $rn = (int) ($r['room_nights'] ?? 0);
                $rev = round((float) ($r['revenue'] ?? 0), 2);
                $acts = (int) ($r['activities'] ?? 0);
                if ($rn < 0 || $rev < 0 || $acts < 0 || $rn > 1000000 || $acts > 5000) {
                    throw new RuntimeException("Check the targets for {$user->name}.");
                }
                if (! $rn && ! $rev && ! $acts) {
                    SalesTarget::where('user_id', $user->id)->where('period', $period)->delete();

                    continue;
                }
                SalesTarget::updateOrCreate(['user_id' => $user->id, 'period' => $period], ['room_nights' => $rn, 'revenue' => $rev, 'activities' => $acts]);
                $n++;
            }
        });
        self::flush();
        ActivityLogger::log('sales', 'targets', "Sales targets for {$period} saved ({$n} salesperson(s))");

        return $n;
    }
}
