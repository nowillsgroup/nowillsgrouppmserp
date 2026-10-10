<?php

namespace App\Services;

use App\Models\CallRecord;
use App\Models\CallTariff;
use App\Models\Folio;
use App\Models\PhoneExtension;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomMove;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v3.7 – PABX call accounting.
 *
 *  SMDR line → normalise the dialled number → free? (internal / emergency / toll-free / unanswered) → longest-prefix
 *  tariff → pulses × price per pulse (≥ minimum charge) → which stay was in the room at the time (room moves honoured)
 *  → post to that stay's folio as a Telephone charge, or park the call in the review queue (no guest in the room,
 *  guest already checked out, unknown extension, auto-posting off). Administrative and public extensions are costed
 *  per department but never billed.
 */
class CallAccountingService
{
    /** which call classes each class of service allows */
    public const COS_ALLOWS = [
        'full' => ['local', 'std', 'mobile', 'isd', 'premium', 'free', 'internal'],
        'national' => ['local', 'std', 'mobile', 'free', 'internal'],
        'local' => ['local', 'free', 'internal'],
        'barred' => ['free', 'internal'],
    ];

    protected static array $tariffs = [];

    protected static array $extensions = [];

    public static function flush(): void
    {
        self::$tariffs = self::$extensions = [];
    }

    // ───────────────────────── rating ─────────────────────────

    public static function normalise(string $number): string
    {
        $n = trim($number);
        $plus = str_starts_with($n, '+');
        $n = preg_replace('/\D/', '', $n);
        $strip = InterfaceService::cfg()['pbx_strip_prefix'];
        if ($strip !== '' && str_starts_with($n, $strip) && strlen($n) > strlen($strip) + 4) {
            $n = substr($n, strlen($strip));
        }

        return mb_substr(($plus ? '+' : '').$n, 0, 30);
    }

    public static function isFreeNumber(string $n): bool
    {
        foreach (array_filter(explode(',', InterfaceService::cfg()['pbx_free_numbers'])) as $p) {
            if (str_ends_with($p, '*') ? str_starts_with($n, rtrim($p, '*')) : $n === $p) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<CallTariff> active tariffs, longest prefix first */
    public static function tariffs(): Collection
    {
        return self::$tariffs[Tenant::id().':'.\App\Models\Setting::$version] ??= CallTariff::where('is_active', true)->get()->sortByDesc(fn ($t) => strlen($t->prefix))->values();
    }

    public static function tariffFor(string $n): ?CallTariff
    {
        return self::tariffs()->first(fn ($t) => $t->prefix === '' || str_starts_with($n, $t->prefix));
    }

    protected static function extensionMap(): Collection
    {
        return self::$extensions[Tenant::id().':'.\App\Models\Setting::$version] ??= PhoneExtension::where('is_active', true)->get()->keyBy('extension');
    }

    /**
     * Rate a call. Returns class, tariff, pulses, cost (carrier), charge (guest) and – for calls that are never charged –
     * the reason.
     */
    public static function rate(string $number, int $secs): array
    {
        $n = self::normalise($number);
        $out = ['number' => $n, 'class' => null, 'tariff' => null, 'pulses' => 0, 'cost' => 0.0, 'charge' => 0.0, 'free' => null];
        if ($n === '' || (strlen($n) <= 4 && ! str_starts_with($n, '+') && ! self::isFreeNumber($n)) || self::extensionMap()->has($n)) {
            return ['class' => 'internal', 'free' => 'Internal call'] + $out;
        }
        if (self::isFreeNumber($n)) {
            return ['class' => 'free', 'free' => 'Emergency / toll-free number'] + $out;
        }
        $t = self::tariffFor($n);
        if (! $t) {
            return ['class' => 'local', 'free' => null, 'error' => 'No tariff matches this number – add a default tariff (blank prefix)'] + $out;
        }
        $out['class'] = $t->call_class;
        $out['tariff'] = $t;
        if ($t->call_class === 'free') {
            return ['free' => 'Toll-free tariff'] + $out;
        }
        if ($secs < InterfaceService::cfg()['pbx_min_secs']) {
            return ['free' => 'Not answered / too short'] + $out;
        }
        if ($secs <= $t->free_secs) {
            return ['free' => "Within the tariff's free seconds"] + $out;
        }
        $pulses = $t->pulse_secs > 0 ? (int) ceil($secs / $t->pulse_secs) : 1;
        $out['pulses'] = $pulses;
        $out['cost'] = round($pulses * $t->cost_per_pulse, 2);
        $out['charge'] = round(max($t->min_charge, $pulses * $t->pricePerPulse()), 2);

        return $out;
    }

    // ───────────────────────── which stay made the call ─────────────────────────

    /** the stay occupying the room at that moment (a later room move is followed back to the old room) */
    public static function stayAt(int $roomId, Carbon $at): ?ReservationRoom
    {
        $inRoom = fn ($q) => $q->whereNotNull('checked_in_at')->where('checked_in_at', '<=', $at)
            ->where(fn ($q) => $q->where('status', 'checked_in')->orWhere(fn ($q) => $q->where('status', 'checked_out')->where('checked_out_at', '>=', $at)));
        // moved out of this room after the call → that stay made the call
        $moved = RoomMove::where('from_room_id', $roomId)->where('created_at', '>', $at)->orderBy('created_at')->first();
        if ($moved && ($s = ReservationRoom::whereKey($moved->reservation_room_id)->where($inRoom)->first())) {
            return $s;
        }
        $candidates = ReservationRoom::where('room_id', $roomId)->where($inRoom)->orderByDesc('checked_in_at')->get();
        foreach ($candidates as $s) {
            // moved into this room after the call → not them
            if (RoomMove::where('reservation_room_id', $s->id)->where('to_room_id', $roomId)->where('created_at', '>', $at)->exists()) {
                continue;
            }

            return $s;
        }

        return null;
    }

    // ───────────────────────── recording ─────────────────────────

    /**
     * Store one call. $row = [ext, number, started_at (Carbon|string), duration (secs), trunk?].
     * Returns null for a duplicate (same line already received).
     */
    public static function record(array $row, string $source = 'import', ?string $batch = null): ?CallRecord
    {
        $ext = mb_substr(trim((string) ($row['ext'] ?? '')), 0, 10);
        $raw = trim((string) ($row['number'] ?? ''));
        $at = $row['started_at'] instanceof Carbon ? $row['started_at'] : Carbon::parse($row['started_at']);
        $secs = max(0, (int) ($row['duration'] ?? 0));
        $trunk = mb_substr(trim((string) ($row['trunk'] ?? '')), 0, 10) ?: null;
        if ($ext === '' || $raw === '') {
            throw new RuntimeException('Extension and dialled number are required.');
        }
        if ($at->gt(now()->addMinutes(10))) {
            throw new RuntimeException('Call time is in the future.');
        }
        $hash = sha1(implode('|', [Tenant::id(), $ext, preg_replace('/\D/', '', $raw), $at->format('Y-m-d H:i:s'), $secs, $trunk]));
        if (CallRecord::where('raw_hash', $hash)->exists()) {
            return null;
        }
        $r = self::rate($raw, $secs);
        $x = self::extensionMap()->get($ext);
        $call = new CallRecord([
            'extension' => $ext, 'phone_extension_id' => $x?->id, 'dialled' => $r['number'] ?: mb_substr($raw, 0, 30), 'started_at' => $at, 'duration_secs' => $secs,
            'trunk' => $trunk, 'call_class' => $r['class'], 'call_tariff_id' => $r['tariff']?->id, 'pulses' => $r['pulses'], 'cost' => $r['cost'],
            'charge' => $r['charge'], 'room_id' => $x?->room_id, 'source' => $source, 'batch' => $batch, 'raw_hash' => $hash,
        ]);

        if (! $x) {
            $call->fill(['status' => 'pending', 'note' => "Unknown extension {$ext} – add it under Extensions, then re-run the queue", 'charge' => 0]);
        } elseif ($x->type !== 'room') {
            $call->fill(['status' => $r['free'] ? 'free' : 'admin', 'charge' => 0, 'note' => $r['free'] ?? ($x->department ?: PhoneExtension::TYPES[$x->type])]);
        } elseif ($r['free']) {
            $call->fill(['status' => 'free', 'note' => $r['free']]);
            $call->reservation_room_id = $x->room_id ? self::stayAt($x->room_id, $at)?->id : null;
        } elseif (! empty($r['error'])) {
            $call->fill(['status' => 'pending', 'note' => $r['error']]);
        } else {
            $stay = $x->room_id ? self::stayAt($x->room_id, $at) : null;
            $call->reservation_room_id = $stay?->id;
            if (! $stay) {
                $call->fill(['status' => 'pending', 'note' => 'No guest in the room at the time of the call']);
            } elseif ($stay->status !== 'checked_in') {
                $call->fill(['status' => 'pending', 'note' => 'Late call – the guest checked out before the record arrived']);
            } else {
                $call->fill(['status' => 'pending', 'note' => InterfaceService::cfg()['pbx_auto_post'] === '1' ? null : 'Waiting to be posted (auto-posting is off)']);
                if (! in_array($r['class'], self::COS_ALLOWS[$stay->phone_cos ?? 'full'] ?? [], true)) {
                    $call->note = 'Call class '.strtoupper($r['class']).' exceeds the room’s class of service ('.($stay->phone_cos).')';
                }
            }
        }
        $call->save();
        if ($call->status === 'pending' && $call->reservation_room_id && $call->note === null) {
            try {
                self::post($call, null, false);
            } catch (RuntimeException $e) {
                $call->update(['note' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }

        return $call->fresh();
    }

    /** post a call to a stay's folio (default: the stay that was in the room) */
    public static function post(CallRecord $call, ?ReservationRoom $stay = null, bool $manual = true): CallRecord
    {
        if (! in_array($call->status, ['pending'], true)) {
            throw new RuntimeException('Only calls waiting for review can be posted.');
        }
        if ($call->charge <= 0) {
            throw new RuntimeException('This call has no charge – waive it instead.');
        }
        $stay ??= $call->stay;
        if (! $stay) {
            throw new RuntimeException('Choose the in-house stay to charge.');
        }
        if ($stay->status !== 'checked_in') {
            throw new RuntimeException('Room '.($stay->room?->room_no ?? '').' has checked out – charge an in-house stay or waive the call.');
        }
        $folio = $stay->folio()->where('status', 'open')->first() ?? Folio::where('reservation_room_id', $stay->id)->where('status', 'open')->first();
        if (! $folio) {
            throw new RuntimeException('The stay has no open folio.');
        }
        $c = InterfaceService::cfg();

        return DB::transaction(function () use ($call, $stay, $folio, $c, $manual) {
            $num = $c['pbx_mask_digits'] === '1' ? $call->maskedNumber() : $call->dialled;
            $tx = FolioService::charge($folio, 'telephone', 'Call '.$num.' · '.(CallTariff::CLASSES[$call->call_class] ?? strtoupper((string) $call->call_class)).' · '.$call->durationLabel().' · '.$call->started_at->format('d M H:i'),
                $call->charge, true, ['source_type' => 'call', 'source_id' => $call->id, 'reference' => 'Ext '.$call->extension]);
            $call->update(['status' => 'posted', 'reservation_room_id' => $stay->id, 'folio_transaction_id' => $tx->id,
                'note' => $manual ? mb_substr('Posted by '.(auth()->user()?->name ?? 'system').($call->reservation_room_id && $call->reservation_room_id !== $stay->id ? ' to another stay' : ''), 0, 200) : null,
                'handled_by' => $manual ? auth()->id() : null, 'handled_at' => $manual ? now() : null]);
            if ($manual) {
                ActivityLogger::log('calls', 'post', 'Call to '.$call->dialled.' ('.money($call->charge).') posted to room '.$stay->room?->room_no, $call);
            }

            return $call->fresh();
        });
    }

    /** waive a pending call, or reverse a posted one (guest dispute) */
    public static function waive(CallRecord $call, string $reason): CallRecord
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new RuntimeException('Give a reason for waiving the call.');
        }
        if (! in_array($call->status, ['pending', 'posted'], true)) {
            throw new RuntimeException('Only calls under review or posted calls can be waived.');
        }
        DB::transaction(function () use ($call, $reason) {
            if ($call->status === 'posted' && $call->transaction && ! $call->transaction->is_void) {
                FolioService::void($call->transaction, 'Call waived: '.$reason);
            }
            $call->update(['status' => 'waived', 'note' => mb_substr($reason, 0, 200), 'handled_by' => auth()->id(), 'handled_at' => now()]);
        });
        ActivityLogger::log('calls', 'waive', 'Call to '.$call->dialled.' ('.money($call->charge).') waived: '.$reason, $call);

        return $call->fresh();
    }

    /** re-examine the review queue (after adding an extension / tariff, a late check-in, or switching auto-post on) */
    public static function reprocess(): array
    {
        self::flush();
        $done = ['posted' => 0, 'still' => 0];
        foreach (CallRecord::where('status', 'pending')->orderBy('started_at')->get() as $call) {
            $x = self::extensionMap()->get($call->extension);
            $r = self::rate($call->dialled, $call->duration_secs);
            $call->fill(['phone_extension_id' => $x?->id, 'room_id' => $x?->room_id, 'call_class' => $r['class'], 'call_tariff_id' => $r['tariff']?->id,
                'pulses' => $r['pulses'], 'cost' => $r['cost'], 'charge' => $x && $x->type === 'room' ? $r['charge'] : 0]);
            if ($x && $x->type !== 'room') {
                $call->fill(['status' => $r['free'] ? 'free' : 'admin', 'note' => $x->department])->save();

                continue;
            }
            if ($r['free']) {
                $call->fill(['status' => 'free', 'note' => $r['free']])->save();

                continue;
            }
            $stay = $x?->room_id ? self::stayAt($x->room_id, $call->started_at) : null;
            $call->reservation_room_id = $stay?->id;
            $call->save();
            if ($stay && $stay->status === 'checked_in' && $call->charge > 0 && ! empty($r['tariff'])) {
                // a call beyond the room's class of service stays held until someone posts or waives it by hand
                if (! in_array($r['class'], self::COS_ALLOWS[$stay->phone_cos ?? 'full'] ?? [], true)) {
                    $call->update(['note' => 'Call class '.strtoupper($r['class']).' exceeds the room’s class of service ('.$stay->phone_cos.')']);
                    $done['still']++;

                    continue;
                }
                try {
                    self::post($call->fresh(), $stay, false);
                    $done['posted']++;

                    continue;
                } catch (RuntimeException $e) {
                    $call->update(['note' => mb_substr($e->getMessage(), 0, 200)]);
                }
            }
            $done['still']++;
        }

        return $done;
    }

    // ───────────────────────── SMDR import / push ─────────────────────────

    public static function parseDuration(string $v): ?int
    {
        $v = trim($v);
        if (preg_match('/^\d+$/', $v)) {
            return (int) $v;
        }
        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', $v, $m)) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
        }
        if (preg_match('/^(\d+)\'(\d{2})"?$/', $v, $m)) {   // 3'25" style
            return (int) $m[1] * 60 + (int) $m[2];
        }

        return null;
    }

    public static function parseDate(string $date, ?string $time = null): ?Carbon
    {
        $s = trim($date.' '.($time ?? ''));
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/y H:i:s', 'd/m/y H:i', 'd.m.Y H:i', 'Y/m/d H:i:s', 'Y/m/d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $f) {
            try {
                $c = Carbon::createFromFormat('!'.$f, $s);
                if ($c && $c->format($f) === $s) {
                    return $c;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    /** @return array{0: array<int,array>, 1: array<int,string>, 2: int} rows, errors, skipped (blank / header lines) */
    public static function parse(string $text): array
    {
        $cols = explode(',', InterfaceService::cfg()['pbx_format']);
        $rows = $errors = [];
        $skipped = 0;
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        foreach (preg_split('/\r\n|\r|\n/', $text) as $i => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                $skipped++;

                continue;
            }
            $parts = str_contains($line, ',') || str_contains($line, ';') || str_contains($line, "\t")
                ? array_map(fn ($p) => trim($p, " \"'"), preg_split('/[,;\t]/', $line))
                : preg_split('/\s+/', $line);
            if (count($parts) < count($cols)) {
                if ($i === 0 || ! preg_match('/\d/', $line)) {
                    $skipped++;

                    continue;
                }
                $errors[] = 'Line '.($i + 1).': expected '.count($cols).' columns ('.implode(', ', $cols).'), found '.count($parts).'.';

                continue;
            }
            $v = [];
            foreach ($cols as $k => $c) {
                $v[$c] = $parts[$k] ?? '';
            }
            $at = isset($v['datetime']) ? self::parseDate(str_replace('T', ' ', $v['datetime'])) : self::parseDate($v['date'] ?? '', $v['time'] ?? '');
            $dur = self::parseDuration($v['duration'] ?? '');
            if (! $at) {
                if ($i === 0) {
                    $skipped++;   // header row

                    continue;
                }
                $errors[] = 'Line '.($i + 1).': cannot read the date / time.';

                continue;
            }
            if ($dur === null) {
                $errors[] = 'Line '.($i + 1).': cannot read the duration “'.$v['duration'].'”.';

                continue;
            }
            $rows[] = ['line' => $i + 1, 'ext' => $v['ext'], 'number' => $v['number'], 'started_at' => $at, 'duration' => $dur, 'trunk' => $v['trunk'] ?? null];
        }

        return [$rows, $errors, $skipped];
    }

    /** import an SMDR file / text (each line stands alone – good lines are kept, bad ones reported) */
    public static function import(string $text, string $source = 'import'): array
    {
        [$rows, $errors] = self::parse($text);
        $batch = ($source === 'push' ? 'P' : 'I').now()->format('ymdHis');
        $sum = ['received' => count($rows) + count($errors), 'imported' => 0, 'duplicates' => 0, 'posted' => 0, 'pending' => 0, 'free' => 0, 'admin' => 0, 'charge' => 0.0, 'errors' => $errors, 'batch' => $batch];
        self::flush();
        foreach ($rows as $row) {
            try {
                $call = self::record($row, $source, $batch);
            } catch (RuntimeException $e) {
                $sum['errors'][] = 'Line '.$row['line'].': '.$e->getMessage();

                continue;
            }
            if (! $call) {
                $sum['duplicates']++;

                continue;
            }
            $sum['imported']++;
            $sum[$call->status === 'waived' ? 'pending' : $call->status]++;
            if ($call->status === 'posted') {
                $sum['charge'] += $call->charge;
            }
        }
        if ($sum['imported'] || $sum['errors']) {
            ActivityLogger::log('calls', $source, "Call records {$source}: {$sum['imported']} new, {$sum['posted']} posted (".money($sum['charge'])."), {$sum['pending']} for review, {$sum['duplicates']} duplicates, ".count($sum['errors']).' errors');
        }

        return $sum;
    }

    // ───────────────────────── extensions, tariffs, class of service ─────────────────────────

    /** create a room extension (= room number) for every active room that has none */
    public static function syncRoomExtensions(): int
    {
        $have = PhoneExtension::pluck('room_id')->filter()->all();
        $taken = PhoneExtension::pluck('extension')->all();
        $n = 0;
        foreach (Room::where('is_active', true)->whereNotIn('id', $have)->orderBy('room_no')->get() as $room) {
            $ext = mb_substr(preg_replace('/\D/', '', $room->room_no) ?: $room->room_no, 0, 10);
            if (in_array($ext, $taken, true)) {
                continue;
            }
            PhoneExtension::create(['extension' => $ext, 'type' => 'room', 'room_id' => $room->id, 'label' => 'Room '.$room->room_no]);
            $taken[] = $ext;
            $n++;
        }
        self::flush();

        return $n;
    }

    public static function saveExtension(array $d, ?PhoneExtension $x = null): PhoneExtension
    {
        $ext = trim((string) ($d['extension'] ?? ''));
        if (! preg_match('/^\d{2,10}$/', $ext)) {
            throw new RuntimeException('Extension must be 2–10 digits.');
        }
        if (PhoneExtension::where('extension', $ext)->when($x, fn ($q) => $q->whereKeyNot($x->id))->exists()) {
            throw new RuntimeException("Extension {$ext} already exists.");
        }
        $type = array_key_exists($d['type'] ?? '', PhoneExtension::TYPES) ? $d['type'] : 'room';
        $room = $type === 'room' ? (int) ($d['room_id'] ?? 0) : null;
        if ($type === 'room' && ! Room::whereKey($room)->exists()) {
            throw new RuntimeException('Choose the room for a guest-room extension.');
        }
        $data = ['extension' => $ext, 'type' => $type, 'room_id' => $room ?: null, 'department' => $type === 'room' ? null : (mb_substr(trim((string) ($d['department'] ?? '')), 0, 40) ?: null),
            'label' => mb_substr(trim((string) ($d['label'] ?? '')), 0, 60) ?: null, 'is_active' => ! array_key_exists('is_active', $d) || ! empty($d['is_active'])];
        $x ? $x->update($data) : $x = PhoneExtension::create($data);
        self::flush();

        return $x;
    }

    public static function saveTariff(array $d, ?CallTariff $t = null): CallTariff
    {
        $prefix = trim((string) ($d['prefix'] ?? ''));
        if ($prefix !== '' && ! preg_match('/^\+?\d{1,14}$/', $prefix)) {
            throw new RuntimeException('Prefix must be digits (optionally starting with +), or blank for the default tariff.');
        }
        if (CallTariff::where('prefix', $prefix)->when($t, fn ($q) => $q->whereKeyNot($t->id))->exists()) {
            throw new RuntimeException('Another tariff already uses prefix “'.($prefix === '' ? '(default)' : $prefix).'”.');
        }
        $data = [
            'name' => mb_substr(trim((string) ($d['name'] ?? '')), 0, 60) ?: 'Tariff '.$prefix, 'prefix' => $prefix,
            'call_class' => array_key_exists($d['call_class'] ?? '', CallTariff::CLASSES) ? $d['call_class'] : 'local',
            'pulse_secs' => max(1, min(600, (int) ($d['pulse_secs'] ?? 60))), 'cost_per_pulse' => max(0, round((float) ($d['cost_per_pulse'] ?? 0), 2)),
            'markup_pct' => max(0, min(1000, round((float) ($d['markup_pct'] ?? 0), 2))), 'min_charge' => max(0, round((float) ($d['min_charge'] ?? 0), 2)),
            'free_secs' => max(0, min(600, (int) ($d['free_secs'] ?? 0))), 'is_active' => ! array_key_exists('is_active', $d) || ! empty($d['is_active']),
        ];
        $t ? $t->update($data) : $t = CallTariff::create($data);
        self::flush();
        ActivityLogger::log('calls', 'tariff', "Call tariff {$t->name} saved (".money($t->pricePerMinute()).'/min)', $t);

        return $t;
    }

    public static function setCos(ReservationRoom $stay, string $cos): void
    {
        if (! array_key_exists($cos, InterfaceService::COS)) {
            throw new RuntimeException('Unknown class of service.');
        }
        if ($stay->status !== 'checked_in') {
            throw new RuntimeException('Class of service can only be changed for in-house guests.');
        }
        $stay->update(['phone_cos' => $cos]);
        InterfaceService::send('pbx', 'cos', ['extension' => self::roomExtension($stay->room_id), 'cos' => $cos], $stay->room_id, $stay->id);
        ActivityLogger::log('calls', 'cos', 'Room '.$stay->room?->room_no.' phone set to '.InterfaceService::COS[$cos], $stay);
    }

    public static function roomExtension(?int $roomId): ?string
    {
        return $roomId ? PhoneExtension::where('room_id', $roomId)->where('is_active', true)->value('extension') : null;
    }

    // ───────────────────────── PMS → PABX hooks ─────────────────────────

    public static function onCheckIn(ReservationRoom $stay): void
    {
        $cos = InterfaceService::cfg()['pbx_default_cos'];
        $stay->update(['phone_cos' => $cos]);
        $guest = $stay->guest ?? $stay->reservation?->guest;
        InterfaceService::send('pbx', 'checkin', ['extension' => self::roomExtension($stay->room_id), 'name' => $guest?->full_name, 'cos' => $cos, 'language' => 'en'], $stay->room_id, $stay->id);
    }

    public static function onCheckOut(ReservationRoom $stay): void
    {
        InterfaceService::send('pbx', 'checkout', ['extension' => self::roomExtension($stay->room_id), 'cos' => 'barred'], $stay->room_id, $stay->id);
    }

    public static function onRoomMove(ReservationRoom $stay, ?int $fromRoomId): void
    {
        InterfaceService::send('pbx', 'move', ['from' => self::roomExtension($fromRoomId), 'to' => self::roomExtension($stay->room_id), 'cos' => $stay->phone_cos], $stay->room_id, $stay->id);
    }

    // ───────────────────────── summaries ─────────────────────────

    /** calls, minutes, cost, charge per class between two dates */
    public static function summary(string $from, string $to): Collection
    {
        return CallRecord::whereBetween('started_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->selectRaw('call_class, status, count(*) n, sum(duration_secs) secs, sum(cost) cost, sum(charge) charge')->groupBy('call_class', 'status')->get();
    }
}
