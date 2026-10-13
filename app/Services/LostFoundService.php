<?php

namespace App\Services;

use App\Models\LostFoundItem;
use App\Models\LostReport;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\User;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * v3.5 – lost & found: numbered items with category / valuable flag / storage place / retention date, the probable
 * owner (guest last in the room), guest loss reports matched against the store, ID-checked returns (in person,
 * courier, police) and disposal after the retention period – valuables only with a second member of staff as witness.
 */
class LostFoundService
{
    public const ID_TYPES = ['passport' => 'Passport', 'national_id' => 'National ID / Ghana Card', 'driving' => 'Driving licence', 'voter' => 'Voter ID', 'other' => 'Other'];

    public static function retentionDays(string $category, bool $valuable): int
    {
        $c = FrontDeskOpsService::cfg();

        return $category === 'perishable' ? max(0, $c['lf_retention_perishable']) : ($valuable ? $c['lf_retention_valuable'] : $c['lf_retention']);
    }

    /** the guest who was (or had just been) in the room when the item was found */
    public static function probableStay(?int $roomId, Carbon $found): ?ReservationRoom
    {
        if (! $roomId) {
            return null;
        }

        return ReservationRoom::with('guest', 'reservation.guest')->where('room_id', $roomId)
            ->whereDate('arrival_date', '<=', $found->toDateString())->where('departure_date', '>=', $found->copy()->subDays(3)->toDateString())
            ->whereIn('status', ['checked_in', 'checked_out'])->orderByRaw("status = 'checked_in' desc")->orderByDesc('departure_date')->first();
    }

    public static function log(array $d): LostFoundItem
    {
        $name = trim((string) ($d['item_name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Describe the item.');
        }
        $found = Carbon::parse($d['found_date'] ?? bdate());
        if ($found->gt(bdate()->copy()->endOfDay())) {
            throw new RuntimeException('The found date cannot be in the future.');
        }
        $cat = array_key_exists($d['category'] ?? '', LostFoundItem::CATEGORIES) ? $d['category'] : 'other';
        $value = isset($d['est_value']) && $d['est_value'] !== '' ? max(0, round((float) $d['est_value'], 2)) : null;
        $threshold = FrontDeskOpsService::cfg()['lf_valuable_amount'];
        $valuable = ! empty($d['valuable']) || LostFoundItem::CATEGORIES[$cat][1] || ($value !== null && $threshold > 0 && $value >= $threshold);
        $roomId = ! empty($d['room_id']) ? (int) $d['room_id'] : null;
        $room = $roomId ? Room::find($roomId) : null;
        $stay = self::probableStay($roomId, $found);
        $finder = trim((string) ($d['found_by'] ?? '')) ?: auth()->user()?->name;

        $item = DB::transaction(fn () => LostFoundItem::create([
            'item_no' => Numbering::next('lost_item'), 'item_name' => mb_substr($name, 0, 150), 'category' => $cat, 'valuable' => $valuable, 'est_value' => $value,
            'description' => trim((string) ($d['description'] ?? '')) ?: null, 'room_id' => $room?->id,
            'reservation_room_id' => $stay?->id, 'guest_id' => $stay ? ($stay->guest_id ?? $stay->reservation?->guest_id) : null,
            'location' => trim((string) ($d['location'] ?? '')) ?: ($room ? 'Room '.$room->room_no : null),
            'storage_location' => trim((string) ($d['storage_location'] ?? '')) ?: ($valuable ? 'Safe' : 'Lost & found store'),
            'found_date' => $found->toDateString(), 'retain_until' => $found->copy()->addDays(self::retentionDays($cat, $valuable))->toDateString(),
            'found_by' => $finder, 'found_by_user_id' => auth()->id(), 'status' => 'stored',
        ]));
        ActivityLogger::log('lostfound', 'create', "Found {$item->item_no}: {$item->item_name}".($room ? " in room {$room->room_no}" : '').($valuable ? ' (valuable)' : ''), $item);

        if ($stay && FrontDeskOpsService::cfg()['lf_notify_guest'] === '1' && $stay->status === 'checked_out') {
            $g = $stay->guest ?? $stay->reservation?->guest;
            if ($g?->phone) {
                MessageService::send($g->phone, 'Dear '.($g->first_name ?: $g->full_name).', '.hotel()->name.' found an item after your stay that may belong to you. Please contact us quoting '.$item->item_no.'.',
                    MessageService::defaultChannel(), 'lost_found', $item);
            }
        }

        return $item;
    }

    public static function update(LostFoundItem $item, array $d): LostFoundItem
    {
        if (! $item->isStored()) {
            throw new RuntimeException('Only items in store can be edited.');
        }
        $cat = array_key_exists($d['category'] ?? '', LostFoundItem::CATEGORIES) ? $d['category'] : $item->category;
        $valuable = ! empty($d['valuable']) || LostFoundItem::CATEGORIES[$cat][1];
        $item->fill([
            'item_name' => trim((string) ($d['item_name'] ?? $item->item_name)) ?: $item->item_name, 'category' => $cat, 'valuable' => $valuable,
            'description' => trim((string) ($d['description'] ?? '')) ?: null, 'storage_location' => trim((string) ($d['storage_location'] ?? '')) ?: $item->storage_location,
            'est_value' => isset($d['est_value']) && $d['est_value'] !== '' ? max(0, round((float) $d['est_value'], 2)) : null,
        ]);
        if ($item->isDirty('valuable') || $item->isDirty('category')) {
            $item->retain_until = $item->found_date->copy()->addDays(self::retentionDays($cat, $valuable));
        }
        $item->save();

        return $item;
    }

    /** hand the item back – valuables need the claimant's ID and a user allowed to release them */
    public static function returnItem(LostFoundItem $item, array $d): LostFoundItem
    {
        if (! $item->isStored()) {
            throw new RuntimeException("{$item->item_no} is no longer in store.");
        }
        $claimant = trim((string) ($d['returned_to'] ?? ''));
        if ($claimant === '') {
            throw new RuntimeException('Who is the item being returned to?');
        }
        $method = array_key_exists($d['return_method'] ?? '', LostFoundItem::RETURN_METHODS) ? $d['return_method'] : 'in_person';
        $idType = trim((string) ($d['claimant_id_type'] ?? '')) ?: null;
        $idNo = trim((string) ($d['claimant_id_no'] ?? '')) ?: null;
        if ($item->valuable) {
            if (! can_do('lostfound.release')) {
                throw new RuntimeException('Valuables can only be released by a supervisor (lost & found release permission).');
            }
            if ($method === 'in_person' && (! $idType || ! $idNo)) {
                throw new RuntimeException('Record the claimant\'s photo ID before releasing a valuable.');
            }
        }
        if ($method === 'courier' && (trim((string) ($d['courier'] ?? '')) === '' || trim((string) ($d['tracking_no'] ?? '')) === '')) {
            throw new RuntimeException('Enter the courier and the tracking / AWB number.');
        }
        $item->forceFill([
            'status' => 'returned', 'returned_to' => mb_substr($claimant, 0, 150), 'claimant_phone' => trim((string) ($d['claimant_phone'] ?? '')) ?: null,
            'claimant_id_type' => $idType, 'claimant_id_no' => $idNo, 'return_method' => $method,
            'courier' => $method === 'courier' ? trim($d['courier']) : null, 'tracking_no' => $method === 'courier' ? trim($d['tracking_no']) : null,
            'courier_charge' => $method === 'courier' ? max(0, round((float) ($d['courier_charge'] ?? 0), 2)) : 0,
            'returned_date' => bdate()->toDateString(), 'released_by' => auth()->id(),
        ])->save();
        LostReport::where('lost_found_item_id', $item->id)->whereIn('status', ['open', 'matched'])->update(['status' => 'closed', 'close_note' => 'Item returned']);
        ActivityLogger::log('lostfound', 'return', "{$item->item_no} returned to {$claimant} (".LostFoundItem::RETURN_METHODS[$method].')', $item);

        return $item;
    }

    /** dispose after the retention period (police hand-over any time); valuables need a witness who confirms with their password */
    public static function dispose(LostFoundItem $item, array $d): LostFoundItem
    {
        if (! $item->isStored()) {
            throw new RuntimeException("{$item->item_no} is no longer in store.");
        }
        $method = $d['disposal_method'] ?? '';
        if (! array_key_exists($method, LostFoundItem::DISPOSAL)) {
            throw new RuntimeException('Choose how the item is disposed of.');
        }
        if ($method !== 'police' && ! $item->isDue()) {
            throw new RuntimeException("{$item->item_no} must be kept until ".$item->retain_until?->format('d M Y').' (only a police hand-over is allowed earlier).');
        }
        if ($item->category === 'documents' && $method !== 'police') {
            throw new RuntimeException('Passports, IDs and documents may only be handed to the police / issuing authority.');
        }
        if (in_array($item->category, ['cash']) && $method === 'finder') {
            throw new RuntimeException('Cash cannot be given to the finder – hand it to the police or account for it.');
        }
        $witness = null;
        if ($item->valuable) {
            if (! can_do('lostfound.release')) {
                throw new RuntimeException('Valuables can only be disposed of by a supervisor (lost & found release permission).');
            }
            $witness = User::where('hotel_id', hotel()->id)->where('username', trim((string) ($d['witness_username'] ?? '')))->where('is_active', true)->first();
            if (! $witness || $witness->id === auth()->id() || ! Hash::check((string) ($d['witness_password'] ?? ''), $witness->password)) {
                throw new RuntimeException('A second member of staff must witness the disposal of a valuable (their username and password).');
            }
        }
        $item->forceFill(['status' => 'disposed', 'disposal_method' => $method, 'disposed_date' => bdate()->toDateString(), 'disposed_by' => auth()->id(),
            'disposal_witness_id' => $witness?->id, 'disposal_note' => trim((string) ($d['disposal_note'] ?? '')) ? mb_substr(trim($d['disposal_note']), 0, 190) : null,
            'returned_date' => null])->save();
        ActivityLogger::log('lostfound', 'dispose', "{$item->item_no} disposed: ".LostFoundItem::DISPOSAL[$method].($witness ? " (witness {$witness->name})" : ''), $item);

        return $item;
    }

    // ───────────────────────── loss reports ─────────────────────────

    public static function report(array $d): LostReport
    {
        $name = trim((string) ($d['name'] ?? ''));
        $what = trim((string) ($d['item'] ?? ''));
        if ($name === '' || $what === '') {
            throw new RuntimeException('Enter the guest\'s name and what was lost.');
        }
        if (empty($d['phone']) && empty($d['email'])) {
            throw new RuntimeException('Enter a phone number or e-mail so the guest can be contacted.');
        }
        $stay = ! empty($d['reservation_room_id']) ? ReservationRoom::find($d['reservation_room_id']) : null;
        $r = LostReport::create([
            'report_no' => Numbering::next('lost_report'), 'guest_id' => $d['guest_id'] ?? $stay?->guest_id, 'reservation_room_id' => $stay?->id,
            'name' => mb_substr($name, 0, 120), 'phone' => trim((string) ($d['phone'] ?? '')) ?: null, 'email' => trim((string) ($d['email'] ?? '')) ?: null,
            'item' => mb_substr($what, 0, 150), 'category' => array_key_exists($d['category'] ?? '', LostFoundItem::CATEGORIES) ? $d['category'] : 'other',
            'description' => trim((string) ($d['description'] ?? '')) ?: null, 'lost_date' => ! empty($d['lost_date']) ? $d['lost_date'] : null,
            'where_lost' => trim((string) ($d['where_lost'] ?? '')) ?: ($stay?->room ? 'Room '.$stay->room->room_no : null),
            'status' => 'open', 'created_by' => auth()->id(),
        ]);
        ActivityLogger::log('lostfound', 'report', "Loss report {$r->report_no}: {$r->item} ({$r->name})", $r);

        return $r;
    }

    /** stored items that could be the reported one, best first */
    public static function candidates(LostReport $r, int $limit = 8): Collection
    {
        $words = collect(preg_split('/[^a-z0-9]+/', mb_strtolower($r->item.' '.$r->description)))->filter(fn ($w) => mb_strlen($w) >= 3)
            ->reject(fn ($w) => in_array($w, ['the', 'and', 'with', 'black', 'white', 'small', 'large', 'left', 'room', 'lost', 'found']))->unique()->values();
        $roomId = $r->stay?->room_id;

        return LostFoundItem::with('room')->where('status', 'stored')
            ->when($r->lost_date, fn ($q) => $q->where('found_date', '>=', $r->lost_date->copy()->subDays(2)->toDateString()))
            ->get()->map(function (LostFoundItem $i) use ($r, $words, $roomId) {
                $hay = mb_strtolower($i->item_name.' '.$i->description);
                $score = ($i->category === $r->category && $r->category !== 'other' ? 3 : 0)
                    + ($roomId && $i->room_id === $roomId ? 4 : 0)
                    + ($r->guest_id && $i->guest_id === $r->guest_id ? 4 : 0)
                    + $words->filter(fn ($w) => str_contains($hay, $w))->count() * 2;
                $i->setAttribute('match_score', $score);

                return $i;
            })->filter(fn ($i) => $i->match_score >= 3)->sortByDesc('match_score')->take($limit)->values();
    }

    public static function match(LostReport $r, LostFoundItem $item): LostReport
    {
        if ($r->status !== 'open') {
            throw new RuntimeException("{$r->report_no} is already {$r->status}.");
        }
        if (! $item->isStored()) {
            throw new RuntimeException("{$item->item_no} is no longer in store.");
        }
        if (LostReport::where('lost_found_item_id', $item->id)->where('status', 'matched')->exists()) {
            throw new RuntimeException("{$item->item_no} is already matched to another report.");
        }
        DB::transaction(function () use ($r, $item) {
            $r->forceFill(['status' => 'matched', 'lost_found_item_id' => $item->id])->save();
            $item->forceFill(['lost_report_id' => $r->id, 'guest_id' => $item->guest_id ?? $r->guest_id])->save();
        });
        ActivityLogger::log('lostfound', 'match', "Loss report {$r->report_no} matched to {$item->item_no}", $r);

        return $r;
    }

    public static function closeReport(LostReport $r, string $note): LostReport
    {
        if ($r->status === 'closed') {
            throw new RuntimeException("{$r->report_no} is already closed.");
        }
        if (trim($note) === '') {
            throw new RuntimeException('Add a closing note (e.g. "not found – guest informed").');
        }
        if ($r->lost_found_item_id) {
            LostFoundItem::whereKey($r->lost_found_item_id)->where('lost_report_id', $r->id)->update(['lost_report_id' => null]);
        }
        $r->forceFill(['status' => 'closed', 'close_note' => mb_substr(trim($note), 0, 190)])->save();

        return $r;
    }
}
