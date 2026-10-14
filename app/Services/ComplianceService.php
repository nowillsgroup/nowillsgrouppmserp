<?php

namespace App\Services;

use App\Mail\ComplianceDigestMail;
use App\Models\ComplianceEvent;
use App\Models\ComplianceItem;
use App\Models\Setting;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * v3.9 – licences & statutory compliance register: expiry / next-due tracking with a renewal lead time, renewal
 * application → renewal, recurring inspections, documents, and a daily digest + log-book alert for what is due or lapsed.
 */
class ComplianceService
{
    public const DEFAULTS = ['comp_lead_days' => '60', 'comp_digest_to' => ''];

    /**
     * v4.5 The standard checklist for a hotel in Ghana: [name, category, authority, department, critical, frequency months, lead days].
     * Loaded on demand ("Load standard checklist") – dates and reference numbers are then filled in by the hotel.
     */
    public const TEMPLATE = [
        ['GTA Hotel Operating Licence', 'licence', 'Ghana Tourism Authority (GTA)', 'Management', true, null, 60],
        ['GTA Star Rating / Grading Certificate', 'certificate', 'Ghana Tourism Authority (GTA)', 'Management', false, null, 90],
        ['FDA Food Hygiene Permit', 'licence', 'Food and Drugs Authority (FDA)', 'Kitchen', true, null, 60],
        ['Fire Certificate', 'certificate', 'Ghana National Fire Service (GNFS)', 'Engineering', true, null, 90],
        ['Business Operating Permit', 'licence', 'Metropolitan / Municipal Assembly', 'Management', true, null, 60],
        ['Liquor Licence', 'licence', 'Metropolitan / Municipal Assembly (Liquor Licensing)', 'Bar', true, null, 60],
        ['EPA Environmental Permit', 'certificate', 'Environmental Protection Agency (EPA)', 'Engineering', true, null, 120],
        ['Factories Inspectorate – Registration of Premises', 'licence', 'Department of Factories Inspectorate', 'HR', false, null, 60],
        ['GRA VAT Registration', 'certificate', 'Ghana Revenue Authority (GRA)', 'Finance', false, null, 60],
        ['Data Protection Commission Registration', 'certificate', 'Data Protection Commission (Act 843)', 'Management', true, null, 60],
        ['Lift Inspection Certificate', 'certificate', 'Department of Factories Inspectorate', 'Engineering', true, null, 60],
        ['Boiler / Pressure Vessel Certificate', 'certificate', 'Department of Factories Inspectorate', 'Engineering', true, null, 60],
        ['Electrical Installation Certificate', 'certificate', 'Energy Commission (certified electrical wiring professional)', 'Engineering', false, null, 60],
        ['Music Licence (GHAMRO)', 'licence', 'Ghana Music Rights Organisation (GHAMRO)', 'Food & Beverage', false, null, 30],
        ['Signage / Advertising Permit', 'licence', 'Metropolitan / Municipal Assembly', 'Management', false, null, 30],
        ['Swimming Pool Permit', 'licence', 'Metropolitan / Municipal Assembly (Environmental Health)', 'Engineering', false, null, 60],
        ['Weighing Scale Verification', 'certificate', 'Ghana Standards Authority (GSA)', 'Kitchen', false, null, 30],
        ['Commercial Building Fire Insurance (compulsory)', 'insurance', 'Insurer (NIC-licensed)', 'Finance', true, null, 45],
        ['Public Liability Insurance', 'insurance', 'Insurer (NIC-licensed)', 'Finance', false, null, 45],
        ['Fire Drill & Evacuation Rehearsal', 'inspection', 'Internal – Fire officer', 'Security', false, 3, 15],
        ['Drinking Water Potability Test', 'inspection', 'Accredited laboratory (GSA / Ghana Water)', 'Engineering', true, 1, 7],
        ['Pest Control Service', 'inspection', 'Licensed pest-control agency (EPA)', 'Housekeeping', false, 1, 7],
        ['Electrical Safety Audit', 'inspection', 'Licensed electrical contractor', 'Engineering', false, 12, 30],
        ['Kitchen Exhaust & Duct Cleaning', 'inspection', 'Duct-cleaning contractor', 'Kitchen', false, 3, 14],
        ['Food Handlers – Health Certificates', 'inspection', 'Environmental Health Unit (Municipal Assembly)', 'HR', false, 12, 30],
        ['Fire-Fighting Equipment Servicing & Extinguisher Refilling', 'contract', 'GNFS-certified fire equipment vendor', 'Engineering', false, null, 30],
        ['Lift Maintenance Contract', 'contract', 'Lift manufacturer / agent', 'Engineering', false, null, 30],
    ];

    /** v4.5 India checklist items on existing properties → their Ghana equivalent [legacy name => [new name, authority]] (pms:upgrade). */
    public const RENAMED = [
        'FSSAI Food Business Licence' => ['FDA Food Hygiene Permit', 'Food and Drugs Authority (FDA)'],
        'Fire NOC / Fire Safety Certificate' => ['Fire Certificate', 'Ghana National Fire Service (GNFS)'],
        'Municipal Trade / Health Licence' => ['Business Operating Permit', 'Metropolitan / Municipal Assembly'],
        'Bar Licence (FL-3, excise)' => ['Liquor Licence', 'Metropolitan / Municipal Assembly (Liquor Licensing)'],
        'Consent to Operate – Air & Water (Pollution Control Board)' => ['EPA Environmental Permit', 'Environmental Protection Agency (EPA)'],
        'Police Lodging-House / Eating-House Licence' => ['GTA Hotel Operating Licence', 'Ghana Tourism Authority (GTA)'],
        'Shops & Establishments Registration' => ['Factories Inspectorate – Registration of Premises', 'Department of Factories Inspectorate'],
        'Lift Licence (annual)' => ['Lift Inspection Certificate', 'Department of Factories Inspectorate'],
        'Boiler Certificate (IBR)' => ['Boiler / Pressure Vessel Certificate', 'Department of Factories Inspectorate'],
        'DG Set Approval / Electrical Inspector Certificate' => ['Electrical Installation Certificate', 'Energy Commission (certified electrical wiring professional)'],
        'Music Licence (PPL / IPRS)' => ['Music Licence (GHAMRO)', 'Ghana Music Rights Organisation (GHAMRO)'],
        'Star Classification (Ministry of Tourism)' => ['GTA Star Rating / Grading Certificate', 'Ghana Tourism Authority (GTA)'],
        'Signage / Hoarding Permission' => ['Signage / Advertising Permit', 'Metropolitan / Municipal Assembly'],
        'Swimming Pool Licence' => ['Swimming Pool Permit', 'Metropolitan / Municipal Assembly (Environmental Health)'],
        'Legal Metrology – Weighing Scale Verification' => ['Weighing Scale Verification', 'Ghana Standards Authority (GSA)'],
        'Fire & Property Insurance (Standard Fire & Special Perils)' => ['Commercial Building Fire Insurance (compulsory)', 'Insurer (NIC-licensed)'],
        'Food Handlers – Medical Fitness Certificates' => ['Food Handlers – Health Certificates', 'Environmental Health Unit (Municipal Assembly)'],
        'Drinking Water Potability Test' => ['Drinking Water Potability Test', 'Accredited laboratory (GSA / Ghana Water)'],
        'Fire-Fighting Equipment AMC & Extinguisher Refilling' => ['Fire-Fighting Equipment Servicing & Extinguisher Refilling', 'GNFS-certified fire equipment vendor'],
        'Lift AMC' => ['Lift Maintenance Contract', 'Lift manufacturer / agent'],
    ];

    /** Rename India checklist items to the Ghana equivalent (idempotent; skips when the Ghana name already exists). Returns the count. */
    public static function ghanaRename(): int
    {
        $n = 0;
        foreach (self::RENAMED as $old => [$new, $auth]) {
            $item = ComplianceItem::where('name', $old)->first();
            if (! $item || ($item->name === $new && $item->authority === $auth)) {
                continue;
            }
            if ($old !== $new && ComplianceItem::where('name', $new)->exists()) {
                continue;
            }
            $item->forceFill(['name' => $new, 'authority' => $auth])->saveQuietly();
            $n++;
        }

        return $n;
    }

    public static function cfg(): array
    {
        return ['lead_days' => max(1, min(365, (int) setting('comp_lead_days', self::DEFAULTS['comp_lead_days']))),
            'digest_to' => trim((string) setting('comp_digest_to', ''))];
    }

    public static function saveSettings(array $d): void
    {
        $to = collect(preg_split('/[\s,;]+/', (string) ($d['digest_to'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))->unique();
        foreach ($to as $e) {
            if (! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException("\"{$e}\" is not a valid e-mail address.");
            }
        }
        Setting::updateOrCreate(['key' => 'comp_lead_days'], ['value' => (string) max(1, min(365, (int) ($d['lead_days'] ?? 60)))]);
        Setting::updateOrCreate(['key' => 'comp_digest_to'], ['value' => $to->implode(', ')]);
        Setting::$version++;
        ActivityLogger::log('compliance', 'settings', 'Compliance settings updated');
    }

    protected static function event(ComplianceItem $i, string $type, array $d = []): ComplianceEvent
    {
        return $i->events()->create(['type' => $type, 'event_date' => $d['event_date'] ?? bdate()->toDateString(), 'user_id' => auth()->id()]
            + array_intersect_key($d, array_flip(['new_expiry', 'result', 'fee', 'reference', 'note', 'document_path', 'document_name'])));
    }

    protected static function store(ComplianceItem $i, ?UploadedFile $file): ?array
    {
        if (! $file) {
            return null;
        }
        $ext = strtolower($file->guessExtension() ?: 'bin');
        if (! in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            throw new RuntimeException('Upload a PDF or an image.');
        }

        return ['document_path' => $file->storeAs('compliance/'.$i->hotel_id.'/'.$i->id, Str::random(32).'.'.$ext, 'local'),
            'document_name' => mb_substr(basename($file->getClientOriginalName()), 0, 190)];
    }

    public static function save(array $d, ?ComplianceItem $i = null): ComplianceItem
    {
        if (! isset(ComplianceItem::CATEGORIES[$d['category'] ?? ''])) {
            throw new RuntimeException('Choose a category.');
        }
        if (! empty($d['issued_on']) && ! empty($d['expires_on']) && $d['expires_on'] < $d['issued_on']) {
            throw new RuntimeException('The expiry date is before the issue date.');
        }
        $freq = $d['category'] === 'inspection' && ! empty($d['frequency_months']) ? max(1, min(120, (int) $d['frequency_months'])) : null;
        $data = ['name' => trim($d['name']), 'category' => $d['category'], 'authority' => $d['authority'] ?? null, 'reference_no' => $d['reference_no'] ?? null,
            'department' => $d['department'] ?? null, 'owner_id' => $d['owner_id'] ?? null, 'issued_on' => $d['issued_on'] ?? null,
            'expires_on' => $d['expires_on'] ?? null, 'frequency_months' => $freq, 'lead_days' => max(1, min(365, (int) ($d['lead_days'] ?? self::cfg()['lead_days']))),
            'fee' => max(0, round((float) ($d['fee'] ?? 0), 2)), 'is_critical' => ! empty($d['is_critical']), 'notes' => $d['notes'] ?? null];
        $new = ! $i;
        DB::transaction(function () use (&$i, $data, $new) {
            if ($new) {
                $i = ComplianceItem::create($data);
                self::event($i, 'created', ['new_expiry' => $data['expires_on'], 'reference' => $data['reference_no']]);
            } else {
                // a changed expiry re-arms the reminders
                if ($i->expires_on?->toDateString() !== ($data['expires_on'] ?: null)) {
                    $data += ['alerted_for' => null, 'lapsed_alerted_for' => null];
                }
                $i->update($data);
            }
        });
        ActivityLogger::log('compliance', $new ? 'create' : 'update', "Compliance item \"{$i->name}\" ".($new ? 'added' : 'updated'), $i);

        return $i;
    }

    public static function applyRenewal(ComplianceItem $i, array $d): ComplianceItem
    {
        if ($i->status === 'archived') {
            throw new RuntimeException('Restore the item first.');
        }
        $on = $d['applied_on'] ?? bdate()->toDateString();
        if ($on > bdate()->toDateString()) {
            throw new RuntimeException('The application date cannot be in the future.');
        }
        DB::transaction(function () use ($i, $d, $on) {
            $i->update(['status' => 'renewing', 'applied_on' => $on, 'application_ref' => $d['reference'] ?? null]);
            self::event($i, 'applied', ['event_date' => $on, 'reference' => $d['reference'] ?? null, 'fee' => (float) ($d['fee'] ?? 0), 'note' => $d['note'] ?? null]);
        });
        ActivityLogger::log('compliance', 'applied', "Renewal applied for \"{$i->name}\"".(! empty($d['reference']) ? " (ref {$d['reference']})" : ''), $i);

        return $i;
    }

    public static function renew(ComplianceItem $i, array $d, ?UploadedFile $file = null): ComplianceItem
    {
        if ($i->status === 'archived') {
            throw new RuntimeException('Restore the item first.');
        }
        $on = $d['renewed_on'] ?? bdate()->toDateString();
        $exp = $d['new_expiry'] ?? null;
        if ($on > bdate()->toDateString()) {
            throw new RuntimeException('The renewal date cannot be in the future.');
        }
        if ($exp && $exp <= $on) {
            throw new RuntimeException('The new expiry must be after the renewal date.');
        }
        if (! $exp && ! empty($i->expires_on)) {
            throw new RuntimeException('Enter the new expiry date.');
        }
        DB::transaction(function () use ($i, $d, $on, $exp, $file) {
            $doc = self::store($i, $file);
            $i->update(['status' => 'active', 'issued_on' => $on, 'expires_on' => $exp, 'reference_no' => ($d['reference_no'] ?? null) ?: $i->reference_no,
                'applied_on' => null, 'application_ref' => null, 'alerted_for' => null, 'lapsed_alerted_for' => null] + ($doc ?? []));
            self::event($i, 'renewed', ['event_date' => $on, 'new_expiry' => $exp, 'reference' => ($d['reference_no'] ?? null) ?: $i->reference_no,
                'fee' => (float) ($d['fee'] ?? 0), 'note' => $d['note'] ?? null] + ($doc ?? []));
        });
        ActivityLogger::log('compliance', 'renewed', "\"{$i->name}\" renewed".($exp ? ' until '.Carbon::parse($exp)->format('d M Y') : ''), $i);

        return $i->fresh();
    }

    /** a recurring inspection / test was carried out – a pass moves the next due date on, a failure raises an urgent log-book item */
    public static function inspect(ComplianceItem $i, array $d, ?UploadedFile $file = null): ComplianceItem
    {
        if ($i->status === 'archived') {
            throw new RuntimeException('Restore the item first.');
        }
        $on = $d['done_on'] ?? bdate()->toDateString();
        if ($on > bdate()->toDateString()) {
            throw new RuntimeException('The inspection date cannot be in the future.');
        }
        $pass = ($d['result'] ?? 'pass') === 'pass';
        $next = $d['next_due'] ?? ($i->frequency_months ? Carbon::parse($on)->addMonthsNoOverflow($i->frequency_months)->toDateString() : null);
        if ($pass && $next && $next <= $on) {
            throw new RuntimeException('The next due date must be after the inspection date.');
        }
        DB::transaction(function () use ($i, $d, $on, $pass, $next, $file) {
            $doc = self::store($i, $file);
            if ($pass) {
                $i->update(['status' => 'active', 'issued_on' => $on, 'expires_on' => $next, 'alerted_for' => null, 'lapsed_alerted_for' => null] + ($doc ?? []));
            } elseif ($doc) {
                $i->update($doc);
            }
            self::event($i, 'inspected', ['event_date' => $on, 'result' => $pass ? 'pass' : 'fail', 'new_expiry' => $pass ? $next : null, 'fee' => (float) ($d['fee'] ?? 0),
                'reference' => $d['reference'] ?? null, 'note' => $d['note'] ?? null] + ($doc ?? []));
        });
        if (! $pass) {
            LogBookService::system("Failed: {$i->name}", 'Inspection on '.Carbon::parse($on)->format('d M Y').' failed'.(! empty($d['note']) ? ': '.$d['note'] : '').'. Rectify and re-inspect.',
                'maintenance', $i->is_critical ? 'urgent' : 'high', ['department' => 'engineering'], true);
        }
        ActivityLogger::log('compliance', 'inspected', "\"{$i->name}\" inspection ".($pass ? 'passed' : 'FAILED').' on '.Carbon::parse($on)->format('d M Y'), $i);

        return $i->fresh();
    }

    public static function upload(ComplianceItem $i, UploadedFile $file, ?string $note = null): ComplianceEvent
    {
        $doc = self::store($i, $file);
        $i->update($doc);
        $e = self::event($i, 'document', ['note' => $note] + $doc);
        ActivityLogger::log('compliance', 'document', "Document uploaded for \"{$i->name}\"", $i);

        return $e;
    }

    public static function archive(ComplianceItem $i, bool $on, ?string $note = null): void
    {
        $i->update(['status' => $on ? 'archived' : 'active']);
        self::event($i, $on ? 'archived' : 'restored', ['note' => $note]);
        ActivityLogger::log('compliance', $on ? 'archive' : 'restore', "\"{$i->name}\" ".($on ? 'archived' : 'restored'), $i);
    }

    /** create the standard checklist items the hotel does not have yet (matched by name) */
    public static function loadTemplate(): int
    {
        $have = ComplianceItem::pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $n = 0;
        foreach (self::TEMPLATE as [$name, $cat, $auth, $dept, $crit, $freq, $lead]) {
            if (in_array(mb_strtolower($name), $have, true)) {
                continue;
            }
            self::save(['name' => $name, 'category' => $cat, 'authority' => $auth, 'department' => $dept, 'is_critical' => $crit, 'frequency_months' => $freq, 'lead_days' => $lead]);
            $n++;
        }

        return $n;
    }

    public static function active()
    {
        return ComplianceItem::where('status', '!=', 'archived');
    }

    /** nav badge: expired items + items inside their renewal window without an application */
    public static function attentionCount(): int
    {
        $d = bdate()->toDateString();

        $renewing = DB::getDriverName() === 'sqlite'
            ? fn ($w) => $w->where('status', 'active')->whereRaw("expires_on <= date(?, '+' || lead_days || ' days')", [$d])
            : fn ($w) => $w->where('status', 'active')->whereRaw('expires_on <= DATE_ADD(?, INTERVAL lead_days DAY)', [$d]);

        return ComplianceItem::where('status', '!=', 'archived')->whereNotNull('expires_on')
            ->where(fn ($q) => $q->whereDate('expires_on', '<', $d)->orWhere($renewing))
            ->count();
    }

    /** counts per computed state + the items that need attention */
    public static function snapshot(): array
    {
        $items = self::active()->with('owner')->get();
        $by = $items->groupBy(fn ($i) => $i->state());
        $attention = $items->filter(fn ($i) => in_array($i->state(), ['expired', 'due', 'renewing'], true))->sortBy(fn ($i) => $i->expires_on)->values();

        return ['total' => $items->count(), 'counts' => collect(ComplianceItem::STATES)->map(fn ($s, $k) => ($by[$k] ?? collect())->count())->all(),
            'attention' => $attention, 'critical_expired' => $items->filter(fn ($i) => $i->is_critical && $i->state() === 'expired')->count(),
            'undated' => $items->filter(fn ($i) => ! $i->expires_on && $i->category !== 'certificate' && $i->state() === 'perpetual')->count()];
    }

    /**
     * Daily: items that entered their renewal window or lapsed since the last alert → one digest e-mail per owner (and to the
     * configured compliance mailbox) and a log-book entry for the duty managers. Each expiry date is alerted once per stage.
     */
    public static function alerts(): array
    {
        $due = collect();
        $expired = collect();
        foreach (self::active()->whereNotNull('expires_on')->with('owner')->get() as $i) {
            $st = $i->state();
            $exp = $i->expires_on->toDateString();
            if ($st === 'expired' && $i->lapsed_alerted_for?->toDateString() !== $exp) {
                $expired->push($i);
                $i->forceFill(['lapsed_alerted_for' => $exp, 'alerted_for' => $exp])->save();
            } elseif ($st === 'due' && $i->alerted_for?->toDateString() !== $exp) {
                $due->push($i);
                $i->forceFill(['alerted_for' => $exp])->save();
            }
        }
        if ($due->isEmpty() && $expired->isEmpty()) {
            return ['due' => 0, 'expired' => 0, 'mails' => 0];
        }
        $hotel = Tenant::hotel();
        $mails = 0;
        $targets = [];
        foreach ($due->merge($expired) as $i) {
            if ($i->owner?->email) {
                $targets[$i->owner->email][] = $i->id;
            }
        }
        // the compliance mailbox (Settings) – or the hotel's own address when none is configured
        foreach (preg_split('/[\s,;]+/', self::cfg()['digest_to'] ?: (string) $hotel?->email, -1, PREG_SPLIT_NO_EMPTY) as $e) {
            $targets[$e] = $due->merge($expired)->pluck('id')->all();
        }
        foreach ($targets as $email => $ids) {
            MailService::send($email, new ComplianceDigestMail($hotel, $due->whereIn('id', $ids)->values(), $expired->whereIn('id', $ids)->values()), 'compliance_digest');
            $mails++;
        }
        $crit = $expired->where('is_critical', true);
        $body = collect([
            $expired->count() ? 'Expired: '.$expired->map(fn ($i) => $i->name.' ('.$i->expires_on->format('d M').')')->implode('; ') : null,
            $due->count() ? 'Due for renewal: '.$due->map(fn ($i) => $i->name.' – '.$i->expires_on->format('d M'))->implode('; ') : null,
        ])->filter()->implode("\n");
        LogBookService::system(($expired->count() ? $expired->count().' licence(s) expired · ' : '').$due->count().' due for renewal', $body, 'maintenance',
            $crit->count() ? 'urgent' : ($expired->count() ? 'high' : 'normal'), ['department' => 'management'], $expired->count() > 0);
        ActivityLogger::log('compliance', 'alerts', "Compliance alerts: {$due->count()} due, {$expired->count()} expired, {$mails} e-mail(s)");

        return ['due' => $due->count(), 'expired' => $expired->count(), 'mails' => $mails];
    }

    /** register as of a date (for the report / print) */
    public static function register(?string $asOf = null, ?string $state = null): Collection
    {
        $at = $asOf ? Carbon::parse($asOf) : bdate();

        return ComplianceItem::with('owner')->where('status', '!=', 'archived')->get()
            ->map(fn ($i) => ['item' => $i, 'state' => $i->state($at), 'days' => $i->daysLeft($at)])
            ->when($state, fn ($c) => $c->where('state', $state))
            ->sortBy(fn ($r) => [$r['days'] === null ? 1 : 0, $r['days'] ?? 0])->values();
    }

    public static function documentResponse(?string $path, string $name)
    {
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Content-Disposition' => 'inline; filename="'.addslashes($name).'"', 'Cache-Control' => 'private, no-store']);
    }
}
