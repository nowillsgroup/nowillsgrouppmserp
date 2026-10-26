<?php

namespace App\Services;

use App\Models\KeyCard;
use App\Models\KeyLoan;
use App\Models\MasterKey;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v3.7 – door-lock key cards and the master-key register.
 *
 * Lock semantics follow the common hotel encoders: a **new key** invalidates every earlier card for the room the first
 * time it is used, a **duplicate** works alongside the existing cards. Cards stop working at the configured check-out
 * time (+ grace) on the departure date; an extension re-validates them; a room move or a lost card cancels them.
 */
class KeyCardService
{
    public static function validUntil(ReservationRoom $stay): Carbon
    {
        $c = InterfaceService::cfg();

        $until = Carbon::parse($stay->departure_date->toDateString().' '.$c['lock_checkout_time'])->addMinutes($c['lock_grace_minutes']);
        // guest due out today but still in the room after the check-out time (late check-out) → a short-lived key
        if ($until->isPast() && $stay->departure_date->gte(bdate()->copy()->startOfDay())) {
            $until = now()->addHours(2)->startOfMinute();
        }

        return $until;
    }

    /** @return Collection<KeyCard> */
    public static function activeFor(ReservationRoom $stay): Collection
    {
        return KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->orderBy('id')->get();
    }

    /**
     * Encode cards. $mode 'new' cancels the stay's existing cards; 'duplicate' adds to them.
     *
     * @return Collection<KeyCard>
     */
    public static function issue(ReservationRoom $stay, int $count = 1, string $mode = 'new', ?array $areas = null, ?string $holder = null, ?string $type = null): Collection
    {
        $c = InterfaceService::cfg();
        if ($c['lock_enabled'] !== '1') {
            throw new RuntimeException('The door-lock interface is switched off (Interfaces › Settings).');
        }
        $stay->refresh();
        if ($stay->status !== 'checked_in' || ! $stay->room_id) {
            throw new RuntimeException('Key cards can only be encoded for checked-in guests with a room.');
        }
        if ($count < 1 || $count > $c['lock_max_cards']) {
            throw new RuntimeException("Encode between 1 and {$c['lock_max_cards']} cards.");
        }
        $active = self::activeFor($stay);
        if ($mode === 'duplicate') {
            if ($active->isEmpty()) {
                throw new RuntimeException('There is no working card to duplicate – encode a new key.');
            }
            if ($active->count() + $count > $c['lock_max_cards']) {
                throw new RuntimeException("At most {$c['lock_max_cards']} working cards per room – {$active->count()} already active.");
            }
        } elseif ($mode !== 'new') {
            throw new RuntimeException('Unknown encode mode.');
        }
        $areas = array_values(array_intersect($areas ?? $c['default_areas'], $c['areas']));
        $until = self::validUntil($stay);
        if ($until->isPast()) {
            throw new RuntimeException('The departure time has already passed – extend the stay first.');
        }
        $holder = mb_substr(trim((string) $holder), 0, 80) ?: (($stay->guest ?? $stay->reservation?->guest)?->full_name ?? 'Guest');
        $type ??= $mode === 'duplicate' ? 'duplicate' : 'guest';
        $room = $stay->room;
        $cards = collect();
        for ($i = 0; $i < $count; $i++) {
            // the encoder writes the card first; nothing is recorded as issued if it fails
            $cmd = InterfaceService::send('lock', $i === 0 && $mode === 'new' ? 'issue' : 'duplicate', [
                'room' => $room->room_no, 'from' => now()->format('Y-m-d H:i'), 'until' => $until->format('Y-m-d H:i'), 'areas' => $areas, 'holder' => $holder,
            ], $room->id, $stay->id);
            if ($cmd->status !== 'done') {
                if ($cards->isEmpty()) {
                    throw new RuntimeException('Card not encoded: '.$cmd->response);
                }
                break;   // keep the cards already written
            }
            $card = DB::transaction(function () use ($stay, $room, $mode, $i, $active, $until, $areas, $holder, $type, $cmd) {
                if ($i === 0 && $mode === 'new') {
                    foreach ($active as $old) {
                        $old->update(['status' => 'cancelled', 'status_note' => 'Superseded by a new key', 'closed_at' => now()]);
                    }
                }
                $card = KeyCard::create([
                    'card_no' => Numbering::next('key_card'), 'serial' => $cmd->payload['serial'] ?? strtoupper(bin2hex(random_bytes(4))), 'type' => $i === 0 ? $type : ($type === 'guest' ? 'duplicate' : $type),
                    'reservation_room_id' => $stay->id, 'room_id' => $room->id, 'holder' => $holder, 'valid_from' => now(), 'valid_until' => $until,
                    'areas' => $areas, 'status' => 'active', 'issued_by' => auth()->id(),
                ]);
                $cmd->update(['key_card_id' => $card->id]);

                // v7.55 – push the credential to the room's Robita lock device if one is mapped
                self::pushToRobita($stay, $room, $card, $areas);

                return $card;
            });
            $cards->push($card);
        }
        ActivityLogger::log('keys', $mode, ($mode === 'new' ? 'New key' : 'Duplicate').': '.$cards->count().' card(s) for room '.$room->room_no.' valid until '.$until->format('d M H:i')
            .($mode === 'new' && $active->isNotEmpty() ? " ({$active->count()} earlier card(s) cancelled)" : ''), $stay);

        return $cards;
    }

    /** card reported lost: cancelled on the lock; optionally a fee on the folio and replacement cards for the others */
    public static function markLost(KeyCard $card, bool $chargeFee = false, bool $reissue = true, ?float $fee = null): Collection
    {
        if ($card->status !== 'active') {
            throw new RuntimeException('Only a working card can be reported lost.');
        }
        $stay = $card->stay;
        $fee ??= InterfaceService::cfg()['lock_lost_fee'];
        $others = $stay ? self::activeFor($stay)->where('id', '!=', $card->id)->count() : 0;
        DB::transaction(function () use ($card, $chargeFee, $fee, $stay) {
            $tx = null;
            if ($chargeFee && $fee > 0) {
                $folio = $stay?->folio()->where('status', 'open')->first();
                if (! $folio) {
                    throw new RuntimeException('No open folio to charge the lost-card fee to.');
                }
                $tx = FolioService::charge($folio, 'misc', 'Lost key card fee – '.$card->card_no, $fee, true, ['source_type' => 'key_card', 'source_id' => $card->id, 'reference' => 'Room '.$card->room?->room_no]);
            }
            $card->update(['status' => 'lost', 'status_note' => 'Reported lost'.($tx ? ' – fee '.money($fee) : ''), 'fee_transaction_id' => $tx?->id, 'closed_at' => now()]);
        });
        InterfaceService::send('lock', 'cancel', ['room' => $card->room?->room_no, 'serial' => $card->serial, 'reason' => 'lost'], $card->room_id, $card->reservation_room_id, $card->id);
        self::cancelOnRobita($card);
        ActivityLogger::log('keys', 'lost', "Key card {$card->card_no} (room {$card->room?->room_no}) reported lost".($chargeFee && $fee > 0 ? ' – fee '.money($fee) : ''), $card);
        // a lost card is only locked out once a NEW key is used on the door → re-encode the guest's remaining cards too
        if ($reissue && $stay && $stay->status === 'checked_in') {
            return self::issue($stay, max(1, $others + 1), 'new', $card->areas, $card->holder, 'replacement');
        }

        return collect();
    }

    public static function returnCard(KeyCard $card): KeyCard
    {
        if ($card->status !== 'active') {
            throw new RuntimeException('This card is not active.');
        }
        $card->update(['status' => 'returned', 'status_note' => 'Returned at the desk', 'closed_at' => now()]);

        return $card;
    }

    public static function read(string $serial): ?KeyCard
    {
        $serial = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $serial));
        InterfaceService::send('lock', 'read', ['serial' => $serial]);

        return $serial === '' ? null : KeyCard::with('stay.guest', 'room')->where('serial', $serial)->latest('id')->first();
    }

    /** expire cards past their validity (also runs from the scheduler) */
    public static function expireDue(): int
    {
        return KeyCard::where('status', 'active')->where('valid_until', '<', now())->update(['status' => 'expired', 'status_note' => 'Validity ended', 'closed_at' => now()]);
    }

    // ───────────────────────── stay hooks ─────────────────────────

    public static function onCheckIn(ReservationRoom $stay): void
    {
        $n = InterfaceService::cfg()['lock_auto_issue'];
        if ($n > 0 && InterfaceService::cfg()['lock_enabled'] === '1') {
            self::issue($stay, $n, 'new');
        }
    }

    public static function onCheckOut(ReservationRoom $stay): int
    {
        $cards = KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->get();
        foreach ($cards as $card) {
            $card->update(['status' => 'expired', 'status_note' => 'Checked out', 'closed_at' => now()]);
        }
        if ($cards->isNotEmpty()) {
            InterfaceService::send('lock', 'checkout', ['room' => $stay->room?->room_no, 'cards' => $cards->count()], $stay->room_id, $stay->id);
        }

        return $cards->count();
    }

    public static function onRoomMove(ReservationRoom $stay, ?int $fromRoomId): int
    {
        $cards = KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->where('room_id', '!=', $stay->room_id)->get();
        foreach ($cards as $card) {
            $card->update(['status' => 'cancelled', 'status_note' => 'Room move', 'closed_at' => now()]);
        }
        if ($cards->isEmpty()) {
            return 0;
        }
        InterfaceService::send('lock', 'cancel', ['room_id' => $fromRoomId, 'reason' => 'room move', 'cards' => $cards->count()], $fromRoomId, $stay->id);
        try {
            return self::issue($stay->fresh(), min($cards->count(), InterfaceService::cfg()['lock_max_cards']), 'new', $cards->first()->areas, $cards->first()->holder)->count();
        } catch (RuntimeException $e) {
            ActivityLogger::log('keys', 'move_failed', 'Room move: new cards could not be encoded – '.$e->getMessage(), $stay);

            return 0;
        }
    }

    public static function onExtend(ReservationRoom $stay): int
    {
        $until = self::validUntil($stay->fresh());
        $cards = KeyCard::where('reservation_room_id', $stay->id)->where('status', 'active')->get();
        foreach ($cards as $card) {
            $card->update(['valid_until' => $until]);
        }
        if ($cards->isNotEmpty()) {
            InterfaceService::send('lock', 'extend', ['room' => $stay->room?->room_no, 'until' => $until->format('Y-m-d H:i'), 'cards' => $cards->count()], $stay->room_id, $stay->id);
        }

        return $cards->count();
    }

    // ───────────────────────── master keys ─────────────────────────

    public static function saveKey(array $d, ?MasterKey $k = null): MasterKey
    {
        $no = strtoupper(trim((string) ($d['key_no'] ?? '')));
        if (! preg_match('/^[A-Z0-9-]{2,20}$/', $no)) {
            throw new RuntimeException('Key number: 2–20 letters, digits or dashes.');
        }
        if (MasterKey::where('key_no', $no)->when($k, fn ($q) => $q->whereKeyNot($k->id))->exists()) {
            throw new RuntimeException("Key {$no} already exists.");
        }
        $data = ['key_no' => $no, 'name' => mb_substr(trim((string) ($d['name'] ?? '')), 0, 80) ?: $no,
            'level' => array_key_exists($d['level'] ?? '', MasterKey::LEVELS) ? $d['level'] : 'section',
            'scope' => mb_substr(trim((string) ($d['scope'] ?? '')), 0, 120) ?: null, 'department' => mb_substr(trim((string) ($d['department'] ?? '')), 0, 40) ?: null];
        if ($k) {
            if (isset($d['status']) && in_array($d['status'], ['available', 'retired'], true) && $k->status !== 'out') {
                $data['status'] = $d['status'];
            }
            $k->update($data);
        } else {
            $k = MasterKey::create($data + ['status' => 'available']);
        }

        return $k;
    }

    public static function signOut(MasterKey $key, ?int $userId, ?string $holder, ?string $purpose, ?int $hours = null): KeyLoan
    {
        return DB::transaction(function () use ($key, $userId, $holder, $purpose, $hours) {
            $key = MasterKey::lockForUpdate()->findOrFail($key->id);
            if ($key->status !== 'available') {
                throw new RuntimeException("{$key->key_no} is not available ({$key->status}).");
            }
            $user = $userId ? User::find($userId) : null;
            $holder = mb_substr(trim((string) $holder), 0, 80) ?: $user?->name;
            if (! $holder) {
                throw new RuntimeException('Who is taking the key?');
            }
            if ($key->level === 'grand' && mb_strlen(trim((string) $purpose)) < 3) {
                throw new RuntimeException('A grand master key needs a stated purpose.');
            }
            $hours = max(1, min(24, $hours ?: InterfaceService::cfg()['lock_master_hours']));
            $loan = KeyLoan::create(['master_key_id' => $key->id, 'user_id' => $user?->id, 'holder' => $holder, 'purpose' => mb_substr(trim((string) $purpose), 0, 150) ?: null,
                'out_at' => now(), 'due_at' => now()->addHours($hours), 'status' => 'out', 'issued_by' => auth()->id()]);
            $key->update(['status' => 'out']);
            ActivityLogger::log('keys', 'master_out', "{$key->key_no} {$key->name} signed out to {$holder} until ".$loan->due_at->format('d M H:i'), $loan);

            return $loan;
        });
    }

    public static function signIn(KeyLoan $loan, ?string $note = null): KeyLoan
    {
        if ($loan->status !== 'out') {
            throw new RuntimeException('This key has already been signed in.');
        }
        DB::transaction(function () use ($loan, $note) {
            $late = $loan->due_at->isPast();
            $loan->update(['status' => 'returned', 'in_at' => now(), 'received_by' => auth()->id(),
                'note' => mb_substr(trim(($note ?? '').($late ? ' (returned late)' : '')), 0, 150) ?: null]);
            $loan->key->update(['status' => 'available']);
        });
        ActivityLogger::log('keys', 'master_in', "{$loan->key->key_no} signed in from {$loan->holder}", $loan);

        return $loan->fresh();
    }

    public static function reportLost(KeyLoan $loan, string $note): KeyLoan
    {
        if ($loan->status !== 'out') {
            throw new RuntimeException('Only a key that is signed out can be reported lost.');
        }
        if (mb_strlen(trim($note)) < 5) {
            throw new RuntimeException('Describe what happened (at least a few words).');
        }
        DB::transaction(function () use ($loan, $note) {
            $loan->update(['status' => 'lost', 'in_at' => now(), 'received_by' => auth()->id(), 'note' => mb_substr(trim($note), 0, 150)]);
            $loan->key->update(['status' => 'lost']);
        });
        ActivityLogger::log('keys', 'master_lost', "SECURITY: master key {$loan->key->key_no} ({$loan->key->scope}) lost by {$loan->holder} – re-key / cancel on the lock system. {$note}", $loan);
        try {
            LogBookService::system("Master key {$loan->key->key_no} reported LOST", "{$loan->key->name} ({$loan->key->scope}) lost by {$loan->holder}. {$note} Re-key or cancel it on the lock system.", 'security', 'urgent', ['department' => 'security'], true);
        } catch (\Throwable $e) {
            report($e);
        }
        InterfaceService::send('lock', 'cancel', ['master_key' => $loan->key->key_no, 'reason' => 'lost']);

        return $loan->fresh();
    }

    // ───────────────────────── v7.55 Robita lock integration ─────────────────────────

    /**
     * Push a newly-issued card credential to the room's Robita door-lock device.
     * Silent no-op if no device is mapped to the room or Robita is disabled.
     */
    private static function pushToRobita(ReservationRoom $stay, \App\Models\Room $room, KeyCard $card, array $areas): void
    {
        try {
            if (! class_exists(\App\Services\RobitaService::class)) {
                return;
            }
            \App\Services\RobitaService::ensureDefaults();
            if (\App\Services\RobitaService::cfg()['robita_cloud_url'] === '' && \App\Services\RobitaService::cfg()['robita_default_model'] === '') {
                return;
            }
            $device = \App\Models\RobitaDevice::where('hotel_id', $stay->hotel_id ?? \App\Support\Tenant::id())
                ->where('room_id', $room->id)
                ->where('status', '!=', 'decommissioned')
                ->first();
            if (! $device) {
                return;
            }
            \App\Services\RobitaService::issueCredential($device, $stay, $card, $areas);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Cancel the Robita credential for a lost card (called from markLost). */
    public static function cancelOnRobita(KeyCard $card): void
    {
        try {
            if (! class_exists(\App\Services\RobitaService::class)) {
                return;
            }
            $credential = \App\Models\RobitaCredential::where('hotel_id', $card->hotel_id ?? \App\Support\Tenant::id())
                ->where('key_card_id', $card->id)
                ->where('status', 'active')
                ->first();
            if ($credential) {
                \App\Services\RobitaService::cancelCredential($credential);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
