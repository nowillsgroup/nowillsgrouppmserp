<?php

namespace App\Services;

use App\Models\Guest;
use App\Models\OtaChannel;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\RoomType;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * iCal channel manager (the lowest common denominator supported by Airbnb, Booking.com,
 * Vrbo, Expedia partner central and most OTAs).
 *
 *  Export: /ical/{token}.ics publishes the dates on which the channel's room type is SOLD OUT
 *          (or stop-sell) as all-day "Not available" events – the OTA closes those dates.
 *  Import: the OTA's calendar URL is fetched; every event becomes (or updates) a reservation
 *          keyed by (ota_channel_id, UID). Events that disappear from the feed are cancelled.
 */
class IcalService
{
    public const UID_DOMAIN = 'nowills-pms';

    public const HORIZON_DAYS = 365;

    // ------------------------------------------------------------------ export

    public static function export(OtaChannel $channel): string
    {
        $type = RoomType::findOrFail($channel->room_type_id);
        $from = bdate()->startOfDay();
        $avail = AvailabilityService::matrix($from, self::HORIZON_DAYS)['matrix'][$type->id] ?? [];
        $rates = RateService::calendar(collect([$type]), $from->copy(), self::HORIZON_DAYS)[$type->id] ?? [];

        // collapse closed dates into contiguous ranges
        $ranges = [];
        $start = null;
        $prev = null;
        foreach ($avail as $date => $free) {
            $closed = $free <= 0 || ! empty($rates[$date]['stop']) || ! $channel->is_active;
            if ($closed && $start === null) {
                $start = $date;
            }
            if (! $closed && $start !== null) {
                $ranges[] = [$start, $date];
                $start = null;
            }
            $prev = $date;
        }
        if ($start !== null) {
            $ranges[] = [$start, Carbon::parse($prev)->addDay()->toDateString()];
        }

        $stamp = now()->utc()->format('Ymd\THis\Z');
        $out = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Nowills PMS//Channel Manager//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            self::fold('X-WR-CALNAME:'.self::esc(hotel()->name.' – '.$type->name))];
        foreach ($ranges as [$a, $b]) {
            $out[] = 'BEGIN:VEVENT';
            $out[] = 'UID:'.md5($channel->id.'|'.$a).'@'.self::UID_DOMAIN;
            $out[] = 'DTSTAMP:'.$stamp;
            $out[] = 'DTSTART;VALUE=DATE:'.str_replace('-', '', $a);
            $out[] = 'DTEND;VALUE=DATE:'.str_replace('-', '', $b);
            $out[] = 'SUMMARY:Not available';
            $out[] = 'TRANSP:OPAQUE';
            $out[] = 'END:VEVENT';
        }
        $out[] = 'END:VCALENDAR';

        return implode("\r\n", $out)."\r\n";
    }

    protected static function esc(string $s): string
    {
        return addcslashes(str_replace(["\r\n", "\n"], '\n', $s), ',;');
    }

    protected static function fold(string $line): string
    {
        return rtrim(chunk_split($line, 73, "\r\n "), "\r\n ");
    }

    // ------------------------------------------------------------------ parse

    /** @return array<int, array{uid:string, start:string, end:string, summary:string, status:string, description:string}> */
    public static function parse(string $ics): array
    {
        $ics = preg_replace("/\r\n|\r/", "\n", $ics);
        $ics = preg_replace("/\n[ \t]/", '', $ics); // unfold continuation lines
        $events = [];
        $cur = null;
        foreach (explode("\n", $ics) as $line) {
            $line = trim($line);
            if ($line === 'BEGIN:VEVENT') {
                $cur = [];

                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($cur !== null && ! empty($cur['UID']) && ! empty($cur['DTSTART'])) {
                    $start = self::date($cur['DTSTART']);
                    $end = ! empty($cur['DTEND']) ? self::date($cur['DTEND']) : Carbon::parse($start)->addDay()->toDateString();
                    if ($end <= $start) {
                        $end = Carbon::parse($start)->addDay()->toDateString();
                    }
                    $events[] = ['uid' => mb_substr($cur['UID'], 0, 190), 'start' => $start, 'end' => $end,
                        'summary' => self::unesc($cur['SUMMARY'] ?? ''), 'status' => strtoupper($cur['STATUS'] ?? 'CONFIRMED'),
                        'description' => self::unesc($cur['DESCRIPTION'] ?? '')];
                }
                $cur = null;

                continue;
            }
            if ($cur === null || ! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $key = strtoupper(explode(';', $name, 2)[0]);
            $cur[$key] = $value;
        }

        return $events;
    }

    protected static function date(string $v): string
    {
        // 20261001 | 20261001T140000Z | 20261001T140000
        if (! preg_match('/^(\d{4})(\d{2})(\d{2})/', trim($v), $m)) {
            throw new RuntimeException("Invalid iCal date: {$v}");
        }

        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    protected static function unesc(string $s): string
    {
        return trim(str_replace(['\n', '\N', '\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $s));
    }

    // ------------------------------------------------------------------ import

    /** Blocks SSRF to internal addresses (the URL is admin-supplied but fetched server-side). */
    public static function assertSafeUrl(string $url): void
    {
        $p = parse_url($url);
        if (! in_array(strtolower($p['scheme'] ?? ''), ['http', 'https']) || empty($p['host'])) {
            throw new RuntimeException('Import URL must be an http(s) address.');
        }
        $ips = @gethostbynamel($p['host']) ?: (filter_var($p['host'], FILTER_VALIDATE_IP) ? [$p['host']] : []);
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Import URL points to a private/internal address.');
            }
        }
    }

    /** Fetch + import. Always records last_sync_* on the channel; returns the stats. */
    public static function sync(OtaChannel $channel): array
    {
        try {
            if (! $channel->import_url) {
                throw new RuntimeException('No import URL configured.');
            }
            self::assertSafeUrl($channel->import_url);
            $res = Http::timeout(20)->withHeaders(['User-Agent' => 'NowillsPMS-iCal/1.0'])->get($channel->import_url);
            if (! $res->successful()) {
                throw new RuntimeException('OTA returned HTTP '.$res->status());
            }
            $body = $res->body();
            if (! str_contains($body, 'BEGIN:VCALENDAR')) {
                throw new RuntimeException('Response is not an iCal calendar.');
            }
            $stats = self::import($channel, self::parse($body));
            $status = "OK – {$stats['created']} new, {$stats['updated']} updated, {$stats['cancelled']} cancelled"
                .($stats['conflicts'] ? ", {$stats['conflicts']} CONFLICT(S): ".implode('; ', array_slice($stats['conflict_list'], 0, 3)) : '');
        } catch (Throwable $e) {
            $stats = ['error' => $e->getMessage()];
            $status = 'ERROR – '.mb_substr($e->getMessage(), 0, 200);
        }
        $channel->forceFill(['last_sync_at' => now(), 'last_sync_status' => mb_substr($status, 0, 255)])->save();
        ActivityLogger::log('channels', 'sync', "iCal sync {$channel->name}: {$status}", $channel);

        return $stats;
    }

    public static function import(OtaChannel $channel, array $events): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'cancelled' => 0, 'unchanged' => 0, 'skipped' => 0, 'conflicts' => 0, 'conflict_list' => []];
        $today = bdate()->toDateString();
        $seen = [];
        $type = RoomType::findOrFail($channel->room_type_id);

        foreach ($events as $e) {
            if (str_ends_with($e['uid'], '@'.self::UID_DOMAIN)) { // our own export fed back – ignore
                $stats['skipped']++;

                continue;
            }
            $seen[] = $e['uid'];
            if ($e['end'] <= $today) {
                $stats['skipped']++;

                continue;
            }
            DB::transaction(function () use ($channel, $e, $type, $today, &$stats) {
                // There is no row to lock for a brand-new UID, so serialize
                // imports on the iCal channel itself before looking it up.
                $lockedChannel = OtaChannel::whereKey($channel->id)->lockForUpdate()->firstOrFail();
                $res = Reservation::with('rooms')->where('ota_channel_id', $lockedChannel->id)->where('ota_uid', $e['uid'])->lockForUpdate()->first();
                if ($e['status'] === 'CANCELLED') {
                    if ($res && self::cancel($res, 'Cancelled on '.$channel->name)) {
                        $stats['cancelled']++;
                    } else {
                        $stats['skipped']++;
                    }

                    return;
                }
                // iCal imports are another reservation writer; serialize their
                // availability check with desk/API/online bookings.
                AvailabilityService::lockRoomInventory([$type->id]);
                if (! $res) {
                    if ($e['start'] < $today) {
                        $stats['skipped']++;

                        return;
                    }
                    if (AvailabilityService::typeAvailable($type->id, $e['start'], $e['end']) < 1) {
                        $stats['conflicts']++;
                        $stats['conflict_list'][] = "{$e['start']}→{$e['end']} no {$type->name} free";

                        return;
                    }
                    self::create($channel, $type, $e);
                    $stats['created']++;

                    return;
                }
                // existing booking: follow date changes while it is still only reserved
                if ($res->status === 'cancelled') {
                    $stats['unchanged']++; // cancelled here by staff – don't resurrect

                    return;
                }
                if ($res->arrival_date->toDateString() === $e['start'] && $res->departure_date->toDateString() === $e['end']) {
                    $stats['unchanged']++;

                    return;
                }
                $stay = $res->rooms->first();
                if (! $stay || $stay->status !== 'reserved' || $res->rooms->where('status', '!=', 'cancelled')->count() !== 1) {
                    $stats['conflicts']++;
                    $stats['conflict_list'][] = "{$res->reservation_no} changed on OTA but is in-house/multi-room";

                    return;
                }
                if (! self::fitsExcluding($type->id, $e['start'], $e['end'], $stay)) {
                    $stats['conflicts']++;
                    $stats['conflict_list'][] = "{$res->reservation_no} new dates {$e['start']}→{$e['end']} not available";

                    return;
                }
                $quote = RateService::quote($type, $e['start'], $e['end']);
                $roomOk = $stay->room_id && AvailabilityService::freeRooms($e['start'], $e['end'], $type->id, $stay->id)->contains('id', $stay->room_id);
                $stay->update(['arrival_date' => $e['start'], 'departure_date' => $e['end'], 'rate' => $quote['average'], 'room_id' => $roomOk ? $stay->room_id : null]);
                $res->update(['arrival_date' => $e['start'], 'departure_date' => $e['end']]);
                ActivityLogger::log('channels', 'update', "{$channel->name} changed {$res->reservation_no} to {$e['start']} → {$e['end']}", $res);
                $stats['updated']++;
            });
        }

        // bookings that vanished from the feed were cancelled on the OTA
        Reservation::with('rooms')->where('ota_channel_id', $channel->id)->whereIn('status', ['confirmed', 'tentative'])
            ->whereNotIn('ota_uid', $seen ?: ['-'])->whereDate('departure_date', '>', $today)->get()
            ->each(function ($r) use ($channel, &$stats) {
                if (self::cancel($r, 'Removed from '.$channel->name.' calendar')) {
                    $stats['cancelled']++;
                }
            });

        return $stats;
    }

    protected static function fitsExcluding(int $typeId, string $from, string $to, ReservationRoom $own): bool
    {
        $m = AvailabilityService::matrix($from, max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to))))['matrix'][$typeId] ?? [];
        foreach ($m as $date => $free) {
            $mine = $own->room_type_id === $typeId && $own->arrival_date->toDateString() <= $date && $own->departure_date->toDateString() > $date;
            if ($free + ($mine ? 1 : 0) < 1) {
                return false;
            }
        }

        return (bool) $m;
    }

    protected static function create(OtaChannel $channel, RoomType $type, array $e): Reservation
    {
        [$first, $last] = self::guestName($e['summary'], $channel->name);
        $guest = Guest::create(['first_name' => $first, 'last_name' => $last, 'notes' => 'Imported from '.$channel->name.' (iCal)']);
        $quote = RateService::quote($type, $e['start'], $e['end']);
        $res = Reservation::create([
            'reservation_no' => Numbering::next('reservation'), 'guest_id' => $guest->id, 'business_source_id' => $channel->business_source_id,
            'arrival_date' => $e['start'], 'departure_date' => $e['end'], 'adults' => 1, 'status' => 'confirmed',
            'special_request' => trim(($e['summary'] ? $e['summary']."\n" : '').mb_substr($e['description'], 0, 800)) ?: null,
            'ota_channel_id' => $channel->id, 'ota_uid' => $e['uid'], 'purpose' => 'Leisure',
        ]);
        $res->rooms()->create(['room_type_id' => $type->id, 'guest_id' => $guest->id, 'arrival_date' => $e['start'], 'departure_date' => $e['end'],
            'rate' => $quote['average'], 'adults' => 1, 'status' => 'reserved']);
        ActivityLogger::log('channels', 'create', "{$channel->name} booking {$res->reservation_no} ({$e['start']} → {$e['end']})", $res);

        return $res;
    }

    /** OTAs put either the guest name or a generic label ("Reserved", "Airbnb (Not available)") in SUMMARY. */
    protected static function guestName(string $summary, string $channel): array
    {
        $s = trim(preg_replace('/\s*\(.*?\)\s*/', ' ', $summary));
        $generic = $s === '' || preg_match('/^(reserved|booked|blocked|not available|unavailable|closed|airbnb|booking\.com|vrbo|expedia)\b/i', $s);
        if ($generic) {
            return [mb_substr($channel, 0, 40), 'Guest'];
        }
        $s = preg_replace('/^(reservation|booking)\s*[:\-–]\s*/i', '', $s);
        $parts = preg_split('/\s+/', $s, 2);

        return [mb_substr($parts[0], 0, 80), isset($parts[1]) ? mb_substr($parts[1], 0, 80) : null];
    }

    protected static function cancel(Reservation $r, string $reason): bool
    {
        if (! in_array($r->status, ['confirmed', 'tentative']) || $r->rooms()->where('status', 'checked_in')->exists()) {
            return false;
        }
        $n = $r->rooms()->where('status', 'reserved')->update(['status' => 'cancelled']);
        if (! $r->rooms()->where('status', '!=', 'cancelled')->exists()) {
            $r->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_at' => now()]);
        }
        ActivityLogger::log('channels', 'cancel', "{$reason}: {$r->reservation_no}", $r);

        return $n > 0;
    }
}
