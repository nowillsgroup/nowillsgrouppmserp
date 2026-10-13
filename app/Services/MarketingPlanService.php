<?php

namespace App\Services;

use App\Models\MarketingBudget;
use App\Models\MarketingCollateral;
use App\Models\MarketingEvent;
use App\Models\SalesActivity;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v6.1 The marketing plan.
 *
 * Marketing events (trade shows, roadshows, sales blitzes, fam trips, sponsorships, advertising, PR…) are planned with a
 * budget and target leads, confirmed, then closed with the actual cost, leads generated and a results write-up.
 * Opportunities record the marketing event that produced them, so each event shows its pipeline and won revenue:
 * ROI = (won revenue − cost) ÷ cost, cost per lead = cost ÷ leads.
 *
 * The budget is set per year × month × line (event types + client entertainment + digital). Actual spend: closed events
 * by the month they started, client entertainment from the sales diary (completed activity costs), digital from
 * whatever the digital module records (0 until then).
 */
class MarketingPlanService
{
    public const MAX_FILE_KB = 20480;

    public const FILE_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'pptx', 'docx', 'xlsx', 'mp4', 'zip'];

    protected static function today(): string
    {
        return bdate()->toDateString();
    }

    public static function canEdit(?User $u, ?int $ownerId): bool
    {
        return $u && ($u->hasPermission('sales.manage') || ($u->hasPermission('sales.marketing') && ($ownerId === null || $ownerId === $u->id)));
    }

    // ───────────────────────── events ─────────────────────────

    public static function saveEvent(array $d, ?MarketingEvent $e, User $u): MarketingEvent
    {
        if ($e && ! self::canEdit($u, $e->owner_id)) {
            throw new RuntimeException('This event belongs to another marketer – ask them or a sales manager.');
        }
        if ($e && in_array($e->status, ['done', 'cancelled'], true)) {
            throw new RuntimeException("{$e->number} is {$e->statusLabel()} and can no longer be changed.");
        }
        $name = trim((string) ($d['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Give the event a name.');
        }
        if (! isset(MarketingEvent::TYPES[$d['type'] ?? ''])) {
            throw new RuntimeException('Choose the type of marketing activity.');
        }
        foreach (['segment' => MarketingEvent::SEGMENTS, 'market' => MarketingEvent::MARKETS] as $k => $list) {
            if (! empty($d[$k]) && ! isset($list[$d[$k]])) {
                throw new RuntimeException("Unknown {$k}.");
            }
        }
        $from = (string) ($d['start_date'] ?? '');
        $to = (string) (($d['end_date'] ?? '') ?: $from);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new RuntimeException('Enter the dates.');
        }
        if ($to < $from) {
            throw new RuntimeException('The event cannot end before it starts.');
        }
        $budget = round((float) ($d['budget'] ?? 0), 2);
        $target = (int) ($d['target_leads'] ?? 0);
        if ($budget < 0 || $budget > 1e10 || $target < 0 || $target > 100000) {
            throw new RuntimeException('Check the budget and target leads.');
        }
        $ownerId = ! empty($d['owner_id']) ? (int) $d['owner_id'] : ($e?->owner_id ?? $u->id);
        if (! User::where('hotel_id', hotel()->id)->where('is_active', true)->whereKey($ownerId)->exists()) {
            throw new RuntimeException('Choose an active owner.');
        }
        if ($ownerId !== $u->id && $ownerId !== $e?->owner_id && ! $u->hasPermission('sales.manage')) {
            throw new RuntimeException('Only a sales manager can assign an event to someone else.');
        }
        $data = ['name' => mb_substr($name, 0, 150), 'type' => $d['type'], 'segment' => ($d['segment'] ?? '') ?: null, 'market' => ($d['market'] ?? '') ?: null,
            'location' => trim((string) ($d['location'] ?? '')) ?: null, 'start_date' => $from, 'end_date' => $to, 'owner_id' => $ownerId,
            'budget' => $budget, 'target_leads' => $target, 'objectives' => $d['objectives'] ?? null];
        if ($e) {
            $e->update($data);
            ActivityLogger::log('marketing', 'event', "{$e->number} {$e->name} updated", $e);
        } else {
            $e = MarketingEvent::create($data + ['number' => Numbering::next('marketing_event'), 'status' => 'planned', 'created_by' => $u->id]);
            ActivityLogger::log('marketing', 'event', "{$e->number} {$e->name} planned – budget ".money($budget), $e);
        }

        return $e;
    }

    /** planned → confirmed → done (actual cost, leads, results) | cancelled (reason) */
    public static function setStatus(MarketingEvent $e, string $status, array $d, User $u): MarketingEvent
    {
        if (! self::canEdit($u, $e->owner_id)) {
            throw new RuntimeException('This event belongs to another marketer – ask them or a sales manager.');
        }
        $allowed = ['planned' => ['confirmed', 'cancelled', 'done'], 'confirmed' => ['done', 'cancelled', 'planned']][$e->status] ?? [];
        if (! in_array($status, $allowed, true)) {
            throw new RuntimeException("{$e->number} is {$e->statusLabel()} – it cannot be ".(MarketingEvent::STATUSES[$status][0] ?? $status).'.');
        }
        $upd = ['status' => $status];
        if ($status === 'done') {
            if ($e->start_date->toDateString() > self::today()) {
                throw new RuntimeException('The event has not started yet.');
            }
            if (! isset($d['actual_cost']) || $d['actual_cost'] === '' || (float) $d['actual_cost'] < 0) {
                throw new RuntimeException('Enter the actual cost (0 if it was free).');
            }
            $results = trim((string) ($d['results'] ?? ''));
            if ($results === '') {
                throw new RuntimeException('Write up the results – who you met, what came of it.');
            }
            $leads = (int) ($d['leads'] ?? 0);
            if ($leads < 0 || $leads > 100000) {
                throw new RuntimeException('Check the number of leads.');
            }
            $upd += ['actual_cost' => round((float) $d['actual_cost'], 2), 'leads' => $leads, 'results' => $results];
        } elseif ($status === 'cancelled') {
            $reason = trim((string) ($d['reason'] ?? ''));
            if ($reason === '') {
                throw new RuntimeException('Give a reason.');
            }
            $upd += ['results' => 'Cancelled: '.mb_substr($reason, 0, 500), 'actual_cost' => round(max(0, (float) ($d['actual_cost'] ?? 0)), 2)];   // deposits already lost still count
        }
        $e->forceFill($upd)->save();
        ActivityLogger::log('marketing', 'status', "{$e->number} {$e->name}: {$e->statusLabel()}".($status === 'done' ? ' – cost '.money($e->actual_cost).", {$e->leads} leads" : ''), $e);

        return $e;
    }

    /** events by id => ['opps', 'open_value', 'won', 'won_value', 'cost', 'roi', 'cpl'] */
    public static function roi(Collection $events): Collection
    {
        $opps = SalesOpportunity::whereIn('marketing_event_id', $events->pluck('id')->all() ?: [0])->get()->groupBy('marketing_event_id');

        return $events->mapWithKeys(function ($e) use ($opps) {
            $g = $opps->get($e->id, collect());
            $won = $g->where('stage', 'won');
            $cost = $e->status === 'cancelled' ? (float) $e->actual_cost : $e->cost();
            $wonValue = round($won->sum(fn ($o) => $o->total()), 2);

            return [$e->id => ['opps' => $g->count(), 'open_value' => round($g->filter->isOpen()->sum(fn ($o) => $o->total()), 2), 'won' => $won->count(), 'won_value' => $wonValue,
                'cost' => round($cost, 2), 'roi' => $cost > 0 && $e->status === 'done' ? round(($wonValue - $cost) * 100 / $cost, 1) : null,
                'cpl' => $e->leads > 0 && $e->status === 'done' ? round($cost / $e->leads, 2) : null]];
        });
    }

    // ───────────────────────── budget ─────────────────────────

    /** [line => ['label', 'budget' => [1..12], 'actual' => [1..12], 'budget_total', 'actual_total']] for a year */
    public static function budgetVsActual(int $year): array
    {
        $lines = MarketingEvent::budgetLines();
        $out = [];
        foreach ($lines as $k => $l) {
            $out[$k] = ['label' => $l, 'budget' => array_fill(1, 12, 0.0), 'actual' => array_fill(1, 12, 0.0), 'committed' => array_fill(1, 12, 0.0)];
        }
        foreach (MarketingBudget::where('year', $year)->get() as $b) {
            if (isset($out[$b->line])) {
                $out[$b->line]['budget'][$b->month] = (float) $b->amount;
            }
        }
        foreach (MarketingEvent::whereYear('start_date', $year)->whereIn('status', ['done', 'cancelled', 'planned', 'confirmed'])->get() as $e) {
            $m = (int) $e->start_date->month;
            if (in_array($e->status, ['done', 'cancelled'], true)) {
                $out[$e->type]['actual'][$m] += (float) $e->actual_cost;
            } else {
                $out[$e->type]['committed'][$m] += (float) $e->budget;   // planned / confirmed events not yet spent
            }
        }
        $ent = SalesActivity::where('status', 'done')->where('cost', '>', 0)->whereYear('done_at', $year)
            ->selectRaw('MONTH(done_at) m, SUM(cost) s')->groupByRaw('MONTH(done_at)')->pluck('s', 'm');
        foreach ($ent as $m => $s) {
            $out['entertainment']['actual'][(int) $m] += (float) $s;
        }
        foreach (self::digitalSpend($year) as $m => $s) {
            $out['digital']['actual'][(int) $m] += (float) $s;
        }
        foreach ($out as $k => $r) {
            $out[$k]['budget_total'] = round(array_sum($r['budget']), 2);
            $out[$k]['actual_total'] = round(array_sum($r['actual']), 2);
            $out[$k]['committed_total'] = round(array_sum($r['committed']), 2);
        }

        return $out;
    }

    /** month => digital spend for the year (paid media etc., filled in by the digital-marketing module when present) */
    public static function digitalSpend(int $year): array
    {
        if (! class_exists(\App\Services\DigitalMarketingService::class) || ! method_exists(\App\Services\DigitalMarketingService::class, 'spendByMonth')) {
            return [];
        }

        return \App\Services\DigitalMarketingService::spendByMonth($year);
    }

    public static function saveBudget(int $year, array $grid, User $u): float
    {
        if ($year < 2000 || $year > 2100) {
            throw new RuntimeException('Choose a year.');
        }
        $lines = MarketingEvent::budgetLines();
        $total = 0;
        DB::transaction(function () use ($year, $grid, $lines, &$total) {
            foreach ($grid as $line => $months) {
                if (! isset($lines[$line])) {
                    throw new RuntimeException('Unknown budget line.');
                }
                foreach ((array) $months as $m => $amt) {
                    $m = (int) $m;
                    $amt = round((float) $amt, 2);
                    if ($m < 1 || $m > 12 || $amt < 0 || $amt > 1e10) {
                        throw new RuntimeException('Budget amounts cannot be negative.');
                    }
                    if ($amt == 0) {
                        MarketingBudget::where(['year' => $year, 'month' => $m, 'line' => $line])->delete();
                    } else {
                        MarketingBudget::updateOrCreate(['year' => $year, 'month' => $m, 'line' => $line], ['amount' => $amt]);
                    }
                    $total += $amt;
                }
            }
        });
        ActivityLogger::log('marketing', 'budget', "Marketing budget {$year} saved – ".money($total));

        return $total;
    }

    // ───────────────────────── collateral ─────────────────────────

    public static function saveCollateral(array $d, ?UploadedFile $file, ?MarketingCollateral $x, User $u): MarketingCollateral
    {
        $title = trim((string) ($d['title'] ?? ''));
        if ($title === '') {
            throw new RuntimeException('Give it a title.');
        }
        if (! isset(MarketingCollateral::KINDS[$d['kind'] ?? ''])) {
            throw new RuntimeException('Choose the kind of collateral.');
        }
        $lang = $d['language'] ?? 'en';
        if (! isset(MarketingCollateral::LANGUAGES[$lang])) {
            throw new RuntimeException('Choose the language.');
        }
        $url = trim((string) ($d['url'] ?? '')) ?: null;
        if ($url && ! preg_match('#^https?://#i', $url)) {
            throw new RuntimeException('The link must start with http:// or https://');
        }
        $valid = ($d['valid_until'] ?? '') ?: null;
        if ($valid && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valid)) {
            throw new RuntimeException('Check the valid-until date.');
        }
        $data = ['title' => mb_substr($title, 0, 150), 'kind' => $d['kind'], 'language' => $lang, 'version' => trim((string) ($d['version'] ?? '')) ?: null,
            'valid_until' => $valid, 'url' => $url, 'notes' => mb_substr((string) ($d['notes'] ?? ''), 0, 500) ?: null, 'is_active' => (bool) ($d['is_active'] ?? true)];
        if ($file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if (! $file->isValid() || ! in_array($ext, self::FILE_TYPES, true)) {
                throw new RuntimeException('Upload a PDF, image, Office document, MP4 or ZIP file.');
            }
            if ($file->getSize() > self::MAX_FILE_KB * 1024) {
                throw new RuntimeException('The file is larger than '.(self::MAX_FILE_KB / 1024).' MB.');
            }
            $old = $x?->file_path;
            $data += ['file_path' => $file->storeAs('collateral/'.Tenant::id(), Str::random(32).'.'.$ext, 'local'),
                'file_name' => mb_substr(preg_replace('/[^\w.\- ]+/u', '_', $file->getClientOriginalName()), 0, 190), 'file_size' => $file->getSize()];
            if ($old) {
                Storage::disk('local')->delete($old);
            }
        }
        if (! $url && ! $file && ! $x?->file_path) {
            throw new RuntimeException('Upload a file or give a link.');
        }
        if ($x) {
            $x->update($data);
        } else {
            $x = MarketingCollateral::create($data + ['uploaded_by' => $u->id]);
        }
        ActivityLogger::log('marketing', 'collateral', "Collateral “{$x->title}” saved", $x);

        return $x;
    }

    public static function deleteCollateral(MarketingCollateral $x): void
    {
        if ($x->file_path) {
            Storage::disk('local')->delete($x->file_path);
        }
        $x->delete();
        ActivityLogger::log('marketing', 'collateral', "Collateral “{$x->title}” deleted");
    }

    /** marketing events for the opportunity "source" picker: this year and last, not cancelled */
    public static function attributable(): Collection
    {
        return MarketingEvent::where('status', '!=', 'cancelled')->where('start_date', '>=', bdate()->copy()->subMonths(18)->toDateString())
            ->where('start_date', '<=', bdate()->copy()->addMonths(6)->toDateString())->orderByDesc('start_date')->get(['id', 'number', 'name', 'start_date']);
    }

    public static function calendar(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $end = $month->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);
        $events = MarketingEvent::whereDate('start_date', '<=', $end->toDateString())->whereDate('end_date', '>=', $start->toDateString())->orderBy('start_date')->get();
        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $ds = $d->toDateString();
            $days[] = ['date' => $d->copy(), 'in' => $d->month === $month->month, 'events' => $events->filter(fn ($e) => $e->start_date->toDateString() <= $ds && $e->end_date->toDateString() >= $ds)->values()];
        }

        return $days;
    }
}
