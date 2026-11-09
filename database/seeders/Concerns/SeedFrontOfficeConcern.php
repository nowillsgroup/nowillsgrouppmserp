<?php

namespace Database\Seeders\Concerns;

use App\Models;
use App\Services\FolioService;
use App\Services\HotelProvisioner;
use App\Services\ApService;
use App\Services\ForexService;
use App\Services\LedgerService;
use App\Services\StayService;
use App\Services\TaxService;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Demo-data seeders — split out of DatabaseSeeder (one file per domain).
 * Composed into DatabaseSeeder via `use`; every method keeps its original visibility and body.
 */
trait SeedFrontOfficeConcern
{
    protected function seedFrontDesk(bool $rich, Models\User $user): void
    {
        \App\Services\FrontDeskOpsService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $prevUser = auth()->user();
        $h = hotel()->id;
        $u = fn (string $n) => Models\User::where('hotel_id', $h)->where('username', $n)->first() ?? $user;
        $desk = $u('frontdesk');
        $fom = $u('fom');
        $hk = $u('housekeeping');
        $eng = $u('engineer');
        auth()->setUser($desk);
        $bd = bdate()->copy()->startOfDay();
        $now = now();
        $inHouse = Models\ReservationRoom::with('room', 'guest', 'reservation.guest')->where('status', 'checked_in')->whereNotNull('room_id')->orderBy('id')->get()->values();
        $arrival = Models\ReservationRoom::with('guest', 'reservation.guest')->where('status', 'reserved')->where('arrival_date', '>=', $bd->toDateString())
            ->whereHas('reservation', fn ($q) => $q->whereIn('status', ['confirmed', 'tentative']))->orderBy('arrival_date')->first();
        $departed = Models\ReservationRoom::with('room', 'guest', 'reservation.guest')->where('status', 'checked_out')->whereNotNull('room_id')->orderByDesc('checked_out_at')->first();
        $gName = fn ($st) => \App\Services\GuestMessageService::stayGuest($st)?->full_name ?? 'Guest';
        $back = function ($model, Carbon $at, array $extra = []) {
            $model->forceFill(['created_at' => $at, 'updated_at' => $at] + $extra)->saveQuietly();

            return $model;
        };
        $L = \App\Services\LogBookService::class;

        // ── log book ──
        $y = $bd->copy()->subDay();
        $e = $L::create(['title' => 'Night audit closed for '.$y->copy()->subDay()->format('D d M Y'), 'body' => 'All rooms posted, no-shows released. Cash drawer balanced. Two early arrivals expected.', 'category' => 'handover', 'department' => 'front_office'], 'system');
        $back($e, $y->copy()->setTime(2, 10), ['business_date' => $y->toDateString(), 'shift' => 'night', 'created_by' => null]);
        if ($st = $inHouse->get(0)) {
            auth()->setUser($desk);
            $e = $L::create(['title' => 'Room '.$st->room->room_no.' – guest complained about corridor noise', 'body' => 'A group returned late and was loud near the lift. Apologised and offered a move; guest preferred to stay. Security asked to do an extra round.',
                'category' => 'complaint', 'department' => 'front_office', 'priority' => 'normal', 'reservation_room_id' => $st->id]);
            $back($e, $y->copy()->setTime(22, 40), ['business_date' => $y->toDateString(), 'shift' => 'evening']);
            auth()->setUser($fom);
            $L::resolve($e, 'Called the guest in the morning – slept well after the security round. Complimentary breakfast offered and accepted.');
            $e->forceFill(['resolved_at' => $bd->copy()->setTime(9, 15)])->saveQuietly();
            $L::markRead(collect([$e]), $fom->id);
        }
        auth()->setUser($desk);
        $e = $L::create(['title' => 'Cash float counted – GH₵10,000 handed over', 'body' => 'Float in the front-desk drawer counted with the night auditor: GH₵10,000 in notes, GH₵420 coins. Two foreign-currency envelopes in the safe (see forex position).', 'category' => 'cash']);
        $back($e, $bd->copy()->setTime(7, 5), ['shift' => 'morning']);
        $L::markRead(collect([$e]), $fom->id);
        if ($arrival) {
            auth()->setUser($fom);
            $e = $L::create(['title' => 'VIP arrival: '.$gName($arrival).' – fruit basket & GM welcome', 'body' => 'Repeat guest. Upgrade if available, welcome card signed by the GM, fruit basket and still water in the room before arrival. Inform the GM when the guest checks in.',
                'category' => 'vip', 'department' => 'all', 'priority' => 'high', 'reservation_room_id' => $arrival->id, 'needs_action' => true,
                'follow_up_at' => $bd->copy()->addDay()->setTime(12, 0)->toDateTimeString(), 'pinned' => true, 'pinned_until' => $arrival->arrival_date->toDateString()]);
            $back($e, $bd->copy()->setTime(8, 30), ['shift' => 'morning']);
        }
        if ($st = $inHouse->get(1)) {
            auth()->setUser($hk);
            $e = $L::create(['title' => 'Room '.$st->room->room_no.' – water leaking under the bathroom sink', 'body' => 'Room attendant found water on the bathroom floor. Towels placed, engineering called. Guest informed and apologised to.',
                'category' => 'maintenance', 'department' => 'engineering', 'priority' => 'high', 'reservation_room_id' => $st->id, 'follow_up_at' => $now->copy()->subMinutes(30)->toDateTimeString()], 'system');
            $back($e, $now->copy()->subHours(3), ['source' => 'manual', 'created_by' => $hk->id]);
            auth()->setUser($eng);
            $c = $L::comment($e, 'Checked – the waste pipe seal has perished. Temporary fix done, spare part expected this evening.');
            $back($c, $now->copy()->subHours(2));
            auth()->setUser($fom);
            $c = $L::comment($e, 'Please confirm once the part is fitted – offer the guest a room move if it is not done by 20:00.');
            $back($c, $now->copy()->subMinutes(80));
        }
        auth()->setUser($desk);
        $e = $L::create(['title' => 'Lift 2 out of service 14:00–16:00 (annual inspection)', 'body' => 'Please guide guests with luggage to lift 1 and the service lift.', 'category' => 'info', 'department' => 'all']);
        $back($e, $now->copy()->subMinutes(50));
        $e = $L::create(['title' => 'Unattended bag in the lobby – checked by security', 'body' => 'Grey backpack near the entrance for 20 min. Security checked CCTV – it belonged to a guest waiting for a taxi. Returned.', 'category' => 'security', 'department' => 'security']);
        $back($e, $now->copy()->subMinutes(25));

        // ── wake-up calls ──
        $W = \App\Services\WakeupService::class;
        $tomorrow = $bd->copy()->addDay();
        $staying = $inHouse->filter(fn ($st) => $st->departure_date->gt($bd))->values(); // guests still here tomorrow morning
        if ($st = $staying->get(0)) {
            if ($st->departure_date->gt($tomorrow)) {
                $W::schedule($st, $tomorrow->copy()->setTime(6, 0), true, 'Call twice – heavy sleeper', 'phone');
            } else {
                $W::schedule($st, $tomorrow->copy()->setTime(6, 0), false, 'Call twice – heavy sleeper', 'phone');
            }
            $y1 = Models\WakeupCall::create(['reservation_room_id' => $st->id, 'room_id' => $st->room_id, 'call_at' => $bd->copy()->setTime(6, 0), 'status' => 'done', 'attempts' => 1, 'source' => 'phone',
                'last_attempt_at' => $bd->copy()->setTime(6, 1), 'completed_at' => $bd->copy()->setTime(6, 1), 'completed_by' => $desk->id, 'outcome_note' => 'Answered', 'created_by' => $desk->id]);
            Models\WakeupAttempt::create(['wakeup_call_id' => $y1->id, 'outcome' => 'answered', 'user_id' => $desk->id, 'attempted_at' => $bd->copy()->setTime(6, 1)]);
        }
        if (($st = $staying->get(1)) && \App\Services\GuestPortalService::enabled()) {
            try {
                \App\Services\GuestPortalService::raise($st, ['category' => 'wakeup', 'preferred_at' => $tomorrow->copy()->setTime(5, 30)->toDateTimeString(), 'notes' => 'Early flight – please also send a pot of tea'], 'portal');
            } catch (\Throwable $ex) {
                $this->command?->warn('Portal wake-up seed skipped: '.$ex->getMessage());
            }
        }
        if ($st = $staying->get(2)) {
            $W::schedule($st, $now->copy()->addMinutes(10)->second(0), false, null, 'phone');
            $na = Models\WakeupCall::create(['reservation_room_id' => $st->id, 'room_id' => $st->room_id, 'call_at' => $bd->copy()->setTime(7, 0), 'status' => 'no_answer', 'attempts' => 3, 'source' => 'desk',
                'last_attempt_at' => $bd->copy()->setTime(7, 11), 'completed_at' => $bd->copy()->setTime(7, 11), 'completed_by' => $desk->id, 'outcome_note' => 'No answer after 3 attempts',
                'escalated_at' => $bd->copy()->setTime(7, 11), 'created_by' => $desk->id]);
            foreach ([0, 5, 11] as $m) {
                Models\WakeupAttempt::create(['wakeup_call_id' => $na->id, 'outcome' => 'no_answer', 'user_id' => $desk->id, 'attempted_at' => $bd->copy()->setTime(7, $m)]);
            }
            $e = $L::system('Room '.$st->room->room_no.' did not answer the 07:00 wake-up call – send someone to knock', 'Guest: '.$gName($st).'.', 'guest', 'urgent', ['reservation_room_id' => $st->id], true);
            $back($e, $bd->copy()->setTime(7, 11), ['shift' => 'morning']);
            auth()->setUser($fom);
            $L::resolve($e, 'Bell captain knocked at 07:15 – guest was in the shower, thanked us.');
            $e->forceFill(['resolved_at' => $bd->copy()->setTime(7, 20)])->saveQuietly();
            auth()->setUser($desk);
        }

        // ── messages & parcels ──
        $M = \App\Services\GuestMessageService::class;
        if ($st = $inHouse->get(0)) {
            $m = $M::take($st, ['kind' => 'message', 'channel' => 'phone', 'from_name' => 'Rahul Verma', 'from_company' => 'Infosys Ltd', 'from_phone' => '+91 98450 12345',
                'body' => 'Tomorrow\'s meeting moved to 11:00 at the Electronic City office. Please call back to confirm.', 'notify' => true]);
            $back($m, $now->copy()->subMinutes(95));
        }
        if ($st = $inHouse->get(1)) {
            $m = $M::take($st, ['kind' => 'parcel', 'channel' => 'courier', 'from_name' => 'Amazon', 'courier' => 'Blue Dart', 'tracking_no' => '71234598761', 'pieces' => 1,
                'storage_location' => 'Front desk – shelf 2', 'body' => 'Small box, approx. 1 kg', 'notify' => false]);
            $back($m, $now->copy()->subMinutes(40));
            $d = $M::take($st, ['kind' => 'message', 'channel' => 'visitor', 'from_name' => 'Anand (driver)', 'body' => 'Car will be at the porch at 09:30 tomorrow.', 'notify' => false]);
            $M::deliver($d, 'Handed to the guest');
            $back($d, $bd->copy()->setTime(10, 5), ['delivered_at' => $bd->copy()->setTime(10, 20)]);
        }
        if ($arrival) {
            $m = $M::take($arrival, ['kind' => 'message', 'channel' => 'email', 'from_name' => 'Kerala Holidays (travel agent)', 'from_phone' => '+91 484 234 5678',
                'body' => 'Akwaaba to Accra! Your guide Suresh will meet you in the lobby at 09:00 on the day after arrival.', 'urgent' => false]);
            $back($m, $now->copy()->subHours(5));
        }
        if ($departed) {
            $m = Models\GuestMessage::create(['message_no' => Numbering::next('guest_message'), 'kind' => 'parcel', 'reservation_room_id' => $departed->id, 'guest_id' => $departed->guest_id ?? $departed->reservation?->guest_id,
                'channel' => 'courier', 'from_name' => 'DTDC Express', 'courier' => 'DTDC', 'tracking_no' => 'D45012987', 'pieces' => 1, 'storage_location' => 'Luggage room',
                'status' => 'pending', 'taken_by' => $desk->id]);
            $back($m, $now->copy()->subHours(7));
        }

        // ── traces ──
        $T = \App\Services\TraceService::class;
        if ($st = $inHouse->get(0)) {
            $T::create(['trace_date' => $bd->toDateString(), 'due_time' => '11:00', 'department' => 'front_office', 'reservation_room_id' => $st->id, 'text' => 'Confirm late check-out until 14:00 with the guest on departure day']);
        }
        $T::create(['trace_date' => $bd->toDateString(), 'department' => 'reservations', 'text' => 'Chase the rooming list for next week\'s wedding block']);
        if ($st = $inHouse->get(2)) {
            $t = $T::create(['trace_date' => $bd->toDateString(), 'department' => 'housekeeping', 'reservation_room_id' => $st->id, 'text' => 'Extra rollaway bed and baby cot for room '.$st->room->room_no]);
            $t->forceFill(['carried_from' => $y->toDateString()])->saveQuietly();
        }
        if ($arrival) {
            $T::create(['trace_date' => $arrival->arrival_date->toDateString(), 'due_time' => '10:00', 'department' => 'concierge', 'reservation_room_id' => $arrival->id, 'text' => 'Confirm the airport pick-up time with the guest']);
        }
        $t = $T::create(['trace_date' => $bd->toDateString(), 'department' => 'accounts', 'text' => 'Collect the company PO before the TechCorp group checks out']);
        auth()->setUser($u('accounts'));
        $T::complete($t, 'PO received by e-mail and attached to the folio');
        auth()->setUser($desk);

        // ── lost & found ──
        $LF = \App\Services\LostFoundService::class;
        $rooms = Models\Room::where('is_active', true)->orderBy('room_no')->get()->values();
        $wallet = $LF::log(['item_name' => 'Black leather wallet', 'category' => 'cash', 'description' => 'Contents counted with a witness: GH₵2,300 cash, two cards (not recorded), a business card.',
            'room_id' => $departed?->room_id ?? $rooms[3]->id, 'found_date' => $bd->copy()->subDays(1)->toDateString(), 'found_by' => 'Ramesh Gowda', 'storage_location' => 'Safe – envelope 14']);
        if ($st = $inHouse->get(3) ?? $inHouse->get(0)) {
            $LF::log(['item_name' => 'Kindle e-reader in a red cover', 'category' => 'electronics', 'est_value' => 9000, 'room_id' => $st->room_id, 'location' => 'Pool deck lounger',
                'found_date' => $bd->toDateString(), 'found_by' => 'Pool attendant']);
        }
        $LF::log(['item_name' => 'Phone charger (USB-C, white)', 'category' => 'electronics', 'room_id' => $rooms[5]->id ?? null, 'found_date' => $bd->copy()->subDays(6)->toDateString(), 'found_by' => 'Ramesh Gowda']);
        $LF::log(['item_name' => 'Blue denim jacket', 'category' => 'clothing', 'location' => 'Restaurant', 'found_date' => $bd->copy()->subDays(96)->toDateString(), 'found_by' => 'Arjun Menon']);
        $LF::log(['item_name' => 'Gold ring with a small stone', 'category' => 'jewellery', 'room_id' => $rooms[2]->id ?? null, 'found_date' => $bd->copy()->subDays(190)->toDateString(), 'found_by' => 'Ramesh Gowda', 'storage_location' => 'Safe – envelope 3']);
        $sun = $LF::log(['item_name' => 'Ray-Ban sunglasses', 'category' => 'other', 'location' => 'Lobby sofa', 'found_date' => $bd->copy()->subDays(9)->toDateString(), 'found_by' => 'Priya Sharma']);
        $LF::returnItem($sun, ['returned_to' => 'Karan Malhotra', 'claimant_phone' => '+91 99001 22334', 'claimant_id_type' => 'driving', 'claimant_id_no' => 'KA0120190034567', 'return_method' => 'in_person']);
        $sun->forceFill(['returned_date' => $bd->copy()->subDays(7)->toDateString()])->saveQuietly();
        $umb = $LF::log(['item_name' => 'Black umbrella', 'category' => 'other', 'location' => 'Porch', 'found_date' => $bd->copy()->subDays(120)->toDateString(), 'found_by' => 'Doorman']);
        auth()->setUser($fom);
        $LF::dispose($umb, ['disposal_method' => 'donated', 'disposal_note' => 'Donated with 6 other umbrellas to the Asha Foundation']);
        $umb->forceFill(['disposed_date' => $bd->copy()->subDays(20)->toDateString()])->saveQuietly();
        auth()->setUser($desk);
        $LF::report(['name' => $departed ? $gName($departed) : 'Rohan Gupta', 'phone' => ($departed ? \App\Services\GuestMessageService::stayGuest($departed)?->phone : null) ?? '+91 98860 55555',
            'item' => 'Black wallet', 'category' => 'cash', 'description' => 'Leather wallet with some cash and cards', 'lost_date' => $bd->copy()->subDays(1)->toDateString(),
            'where_lost' => $departed?->room ? 'Room '.$departed->room->room_no : 'Room', 'reservation_room_id' => $departed?->id]);
        $LF::report(['name' => 'Sophie Laurent', 'email' => 'sophie.laurent@example.fr', 'item' => 'Silver bracelet', 'category' => 'jewellery', 'description' => 'Thin silver bracelet with a heart charm',
            'lost_date' => $bd->copy()->subDays(12)->toDateString(), 'where_lost' => 'Spa changing room']);

        $prevUser ? auth()->setUser($prevUser) : Auth::forgetUser();
    }

    protected function seedForeign(bool $rich, Models\User $user): void
    {
        $FC = \App\Services\FormCService::class;
        $FX = \App\Services\ForexService::class;
        $FC::ensureDefaults();
        $FX::ensureDefaults();
        if (! $rich) {
            return;
        }
        $prevUser = auth()->user();
        $desk = Models\User::where('username', 'frontdesk')->where('hotel_id', hotel()->id)->first() ?? $user;
        $acct = Models\User::where('username', 'accounts')->where('hotel_id', hotel()->id)->first() ?? $user;
        auth()->setUser($desk);
        $now = now();
        $bd = bdate()->copy()->startOfDay();

        // ── scans on the guest profiles ──
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $doc = function (Models\Guest $g, string $type) use ($png, $desk) {
            $path = 'guest-docs/'.$g->hotel_id.'/'.$g->id.'/'.\Illuminate\Support\Str::random(32).'.png';
            \Illuminate\Support\Facades\Storage::disk('local')->put($path, $png);
            $g->documents()->create(['type' => $type, 'path' => $path, 'original_name' => $type.'.png', 'mime' => 'image/png', 'size' => strlen($png), 'uploaded_by' => $desk->id]);
        };
        $place = ['ghana' => ['Accra', 'High Commission of India, Accra', 'Accra, Ghana'], 'india' => ['Mumbai', 'e-Visa (online)', 'Mumbai, India'], 'united kingdom' => ['London', 'VFS London (e-Visa)', 'London, UK'],
            'japan' => ['Tokyo', 'Embassy of India, Tokyo', 'Tokyo, Japan'], 'germany' => ['Frankfurt', 'e-Visa (online)', 'Frankfurt, Germany'],
            'spain' => ['Madrid', 'e-Visa (online)', 'Madrid, Spain']];
        $ports = ['Bengaluru (BLR) airport', 'Mumbai (BOM) airport', 'Delhi (IGI) airport', 'Chennai (MAA) airport'];
        $next = ['Mysuru, Karnataka', 'Goa', 'Chennai, Tamil Nadu', 'Kochi, Kerala', 'Mumbai, Maharashtra', 'Return home'];
        $details = function (Models\Guest $g, Models\ReservationRoom $st, int $i) use ($place, $ports, $next) {
            [$city, $vplace, $from] = $place[mb_strtolower((string) $g->nationality)] ?? ['Capital city', 'e-Visa (online)', $g->country ?: 'Abroad'];
            $in = $st->checked_in_at->copy()->startOfDay();
            $h = abs(crc32($g->last_name.$g->first_name));

            return ['gender' => ['M', 'F'][$h % 2], 'dob' => $g->dob?->toDateString() ?? $in->copy()->subYears(28 + $h % 30)->subDays($h % 300)->toDateString(),
                'passport_place' => $city, 'passport_issued_on' => $in->copy()->subYears(1 + $h % 6)->subDays($h % 200)->toDateString(),
                'passport_expires_on' => $in->copy()->addYears(3 + $h % 5)->addDays($h % 200)->toDateString(),
                'visa_no' => sprintf('%s%07d', ['EV', 'VT', 'EB'][$h % 3], $h % 10000000), 'visa_type' => $i % 4 === 3 ? 'e_business' : 'e_tourist', 'visa_place' => $vplace,
                'visa_issued_on' => $in->copy()->subDays(20 + $h % 40)->toDateString(), 'visa_expires_on' => $g->visa_expiry?->toDateString() ?? $in->copy()->addDays(60)->toDateString(),
                'arrived_india_on' => $in->copy()->subDays($i % 3)->toDateString(), 'port_of_arrival' => $ports[$h % count($ports)],
                'arrived_from' => $i % 3 ? 'Bengaluru airport' : $from, 'next_destination' => $next[($h + $i) % count($next)], 'purpose' => $i % 4 === 3 ? 'business' : 'tourism'];
        };

        // ── Form C for foreign stays of the last 3 weeks ──
        $stays = Models\ReservationRoom::with('reservation.guest', 'guest', 'room')->whereIn('status', ['checked_in', 'checked_out'])
            ->where('checked_in_at', '>=', $bd->copy()->subDays(21))->orderBy('checked_in_at')->orderBy('id')->get()
            ->filter(fn ($st) => $FC::isForeign($FC::stayGuest($st)))->values();
        $scanned = [];
        $inHouse = $stays->where('status', 'checked_in')->values();
        $lastOut = $stays->where('status', 'checked_out')->sortBy('checked_out_at')->last();
        $lateOne = $stays->where('status', 'checked_out')->values()->get(3);
        $i = 0;
        foreach ($stays as $st) {
            $g = $FC::stayGuest($st);
            $f = $FC::createForStay($st, $g);
            $in = $st->checked_in_at;
            $x = $details($g, $st, $i++);
            $name = $g->first_name.' '.$g->last_name;
            $role = $st->status === 'checked_out' ? 'out' : match ($name) {
                'Sneha Kapoor' => 'filed', 'Hiroshi Tanaka' => 'overdue', 'Rahul Verma' => 'incomplete', default => 'due',
            };
            if ($role === 'incomplete') { // back with a new visa – not captured / scanned yet
                $x = array_diff_key($x, array_flip(['visa_no', 'visa_place', 'visa_issued_on', 'arrived_india_on', 'next_destination']));
            }
            $f->forceFill($x + ['created_at' => $in, 'updated_at' => $in])->save();
            if (! isset($scanned[$g->id])) {
                $doc($g, 'passport');
                if ($role !== 'incomplete') {
                    $doc($g, 'visa');
                }
                $scanned[$g->id] = true;
            }
            $ref = fn (Carbon $at, int $n) => 'KA'.$at->format('ymd').sprintf('%06d', (crc32($f->form_no) + $n) % 1000000);
            if ($role === 'out' || $role === 'filed') {
                $late = $lateOne && $st->id === $lateOne->id;
                $sub = $in->copy()->addHours($late ? 31 : 2 + $i % 7)->addMinutes(($i * 13) % 60);
                $upd = ['status' => 'submitted', 'submitted_at' => $sub, 'submitted_by' => $desk->id, 'frro_ref' => $ref($sub, 1),
                    'late_reason' => $late ? 'FRRO portal was down overnight (maintenance) – filed as soon as it came back' : null, 'updated_at' => $sub];
                if ($role === 'out' && ! ($lastOut && $st->id === $lastOut->id)) {
                    $dep = $st->checked_out_at->copy()->addHours(2 + $i % 5);
                    $upd += ['departure_submitted_at' => $dep, 'departure_by' => $desk->id, 'departure_ref' => $i % 2 ? $ref($dep, 7) : null, 'updated_at' => $dep];
                }
                if ($role === 'out') {
                    $upd['alert_due_at'] = null;
                }
                $f->forceFill($upd)->save();
            }
            if ($role === 'overdue') {
                $f->forceFill(['alert_due_at' => $f->deadline_at->copy()->subHours(5), 'alert_overdue_at' => $f->deadline_at->copy()->addMinutes(20)])->save();
            }
            if ($role === 'due' && $name === 'Emily Clarke') { // travelling with her husband – a second Form C, no profile of his own
                $FC::createForStay($st, null, true, ['given_name' => 'James', 'surname' => 'Clarke', 'nationality' => $g->nationality, 'gender' => 'M',
                    'dob' => $in->copy()->subYears(41)->subDays(77)->toDateString(), 'passport_no' => '5398827'.($g->id % 10).'1', 'passport_place' => 'London',
                    'passport_issued_on' => $in->copy()->subYears(4)->toDateString(), 'passport_expires_on' => $in->copy()->addYears(6)->toDateString(),
                    'visa_no' => 'EV'.sprintf('%07d', 4410000 + $g->id), 'visa_type' => 'e_tourist', 'visa_place' => 'VFS London (e-Visa)',
                    'visa_issued_on' => $in->copy()->subDays(30)->toDateString(), 'visa_expires_on' => $x['visa_expires_on'], 'arrived_india_on' => $x['arrived_india_on'],
                    'port_of_arrival' => $x['port_of_arrival'], 'arrived_from' => $x['arrived_from'], 'next_destination' => $x['next_destination'], 'purpose' => 'tourism',
                    'address_abroad' => $f->address_abroad])->forceFill(['created_at' => $in, 'updated_at' => $in])->save();
            }
        }

        // ── forex: 14 days of rates ──
        $cur = Models\Currency::all()->keyBy('code');
        foreach ($FX::CURRENCIES as $k => [$code, , , , $ref, $buy]) {
            for ($d = 13; $d >= 0; $d--) {
                $f = 1 + 0.006 * sin($d * 0.9 + $k) - 0.0004 * $d;
                Models\ForexRate::create(['currency_id' => $cur[$code]->id, 'date' => $bd->copy()->subDays($d)->toDateString(), 'reference_rate' => round($ref * $f, 4),
                    'buy_rate' => round($buy * $f, 4), 'carried' => $d === 0 && $code === 'CHF', 'set_by' => $d === 0 && $code === 'CHF' ? null : $acct->id]);
            }
        }
        $rateOn = fn (string $code, Carbon $day) => Models\ForexRate::where('currency_id', $cur[$code]->id)->whereDate('date', $day->toDateString())->first();
        $pref = ['japan' => 'JPY', 'united kingdom' => 'GBP', 'germany' => 'EUR', 'spain' => 'EUR', 'india' => 'USD'];
        $foreignStays = Models\ReservationRoom::with('reservation.guest', 'guest', 'room')->whereIn('status', ['checked_in', 'checked_out'])
            ->where('checked_in_at', '>=', $bd->copy()->subDays(20))->orderBy('id')->get()->filter(fn ($st) => $FC::isForeign($FC::stayGuest($st)));
        $stayOn = function (Carbon $day, string $code) use ($foreignStays, $FC, $pref) {
            $on = $foreignStays->filter(fn ($st) => $st->checked_in_at->copy()->startOfDay()->lte($day) && (! $st->checked_out_at || $st->checked_out_at->copy()->startOfDay()->gt($day)));

            return $on->first(fn ($st) => ($pref[mb_strtolower((string) $FC::stayGuest($st)->nationality)] ?? 'USD') === $code) ?? $on->first();
        };
        $buyCash = function (int $ago, string $code, float $fc, array $x = []) use ($FX, $FC, $cur, $rateOn, $stayOn, $bd, $desk) {
            $day = $bd->copy()->subDays($ago);
            $st = $stayOn($day, $code);
            $r = $rateOn($code, $day);
            if (! $st || ! $r) {
                return null;
            }
            $g = $FC::stayGuest($st);
            $c = $cur[$code];
            $inr = round($fc * (float) $r->buy_rate / $c->unit, 2);
            $gv = 0.0; // v4.3: money changing is VAT-exempt in Ghana
            $gst = 0.0;
            $usd = $code === 'USD' ? $fc : round($inr / (float) $rateOn('USD', $day)->reference_rate, 2);
            $at = $day->copy()->setTime(9 + $ago % 9, (7 * $ago) % 60);
            $e = Models\ForexExchange::create(['exchange_no' => Numbering::next('forex'), 'business_date' => $day->toDateString(), 'reservation_room_id' => $st->id, 'guest_id' => $g->id,
                'guest_name' => trim($g->first_name.' '.$g->last_name), 'passport_no' => $g->passport_no, 'nationality' => $g->nationality, 'currency_id' => $c->id, 'instrument' => $x['instrument'] ?? 'notes',
                'fc_amount' => $fc, 'rate' => $r->buy_rate, 'unit' => $c->unit, 'inr_amount' => $inr, 'fee' => 0, 'gst_value' => $gv, 'gst' => $gst, 'net_amount' => round($inr - $gst, 2),
                'usd_equiv' => $usd, 'payout' => 'cash', 'certificate' => true, 'certificate_prints' => 1, 'notes' => $x['notes'] ?? null, 'created_by' => $desk->id]
                + (isset($x['void']) ? ['voided_at' => $at->copy()->addMinutes(25), 'voided_by' => $desk->id, 'void_reason' => $x['void']] : []));
            $e->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

            return $e;
        };
        foreach ([[12, 'USD', 500], [11, 'EUR', 300], [9, 'JPY', 50000], [8, 'GBP', 200], [7, 'USD', 800], [6, 'EUR', 150]] as [$ago, $code, $fc]) {
            $buyCash($ago, $code, $fc);
        }
        auth()->setUser($acct);
        try {
            $day = $bd->copy()->subDays(5);
            $held = $FX::position()->keyBy('code');
            $lines = [];
            foreach (['USD', 'EUR', 'GBP'] as $code) {
                if (($held[$code]['held'] ?? 0) > 0 && ($r = $rateOn($code, $day))) {
                    $lines[$cur[$code]->id] = ['fc_amount' => $held[$code]['held'], 'rate' => round((float) $r->reference_rate * 0.9985, 4)];
                }
            }
            if ($lines) {
                $sv = $FX::surrender(['date' => $day->toDateString(), 'counterparty' => 'GCB Bank – Treasury (Forex), High Street, Accra', 'reference' => 'GCB/FX/'.$day->format('ymd').'/0412',
                    'payment_mode_id' => Models\PaymentMode::where('type', 'bank')->value('id'), 'notes' => 'Weekly surrender – JPY kept back (bank had no yen buyer that day)'], $lines);
                $sv->forceFill(['created_at' => $day->copy()->setTime(15, 40), 'updated_at' => $day->copy()->setTime(15, 40)])->save();
            }
        } catch (\Throwable $e) {
            $this->command?->warn('Forex surrender seed skipped: '.$e->getMessage());
        }
        auth()->setUser($desk);
        $buyCash(3, 'USD', 300, ['void' => 'Wrong amount keyed – re-entered as the next exchange']);
        foreach ([[3, 'USD', 400], [2, 'AED', 1500], [1, 'GBP', 250, 'tc']] as $row) {
            $buyCash($row[0], $row[1], $row[2], isset($row[3]) ? ['instrument' => $row[3]] : []);
        }

        // ── a bill credit in US dollars through the service (the first foreign guest in house) ──
        $john = $inHouse->first(fn ($st) => $FC::stayGuest($st)->nationality !== 'India') ?? $inHouse->first();
        if ($john) {
            try {
                $folio = \App\Services\FolioService::openForStay($john);
                // The business date can move during the rich seed. Use today's rate or the
                // latest available rate instead of dereferencing a missing date row.
                $usd = $rateOn('USD', $bd) ?? Models\ForexRate::where('currency_id', $cur['USD']->id)
                    ->whereDate('date', '<=', $bd->toDateString())->orderByDesc('date')->first();
                $usdRate = (float) ($usd?->buy_rate ?? 0);
                $fc = $usdRate > 0 ? min(200, floor(max(0, $folio->balance()) / $usdRate)) : 0;
                if ($fc >= 10) {
                    $FX::exchange(['reservation_room_id' => $john->id, 'currency_id' => $cur['USD']->id, 'fc_amount' => $fc, 'instrument' => 'notes', 'payout' => 'folio',
                        'certificate' => true, 'notes' => 'Settled part of the bill in dollars']);
                }
            } catch (\Throwable $e) {
                $this->command?->warn('Forex bill-credit seed skipped: '.$e->getMessage());
            }
        }
        LedgerService::sync(true); // back-dated purchases & the surrender are outside the incremental window
        $prevUser ? auth()->setUser($prevUser) : Auth::forgetUser();
    }

    protected function seedLaundry(Models\Hotel $hotel, bool $rich, Carbon $today): void
    {
        foreach (['laundry_express_pct' => '50', 'laundry_normal_hours' => '24', 'laundry_express_hours' => '6', 'linen_par_levels' => '4'] as $k => $v) {
            Models\Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Models\Setting::$version++;
        \App\Services\LaundryService::ensureDefaults();
        \App\Services\LinenService::ensureDefaults();
        $L = \App\Services\LaundryService::class;
        $N = \App\Services\LinenService::class;
        $hk = Models\User::where('hotel_id', $hotel->id)->where('username', 'housekeeping')->first();
        $cashier = Models\User::where('hotel_id', $hotel->id)->where('username', 'cashier')->first();
        $store = Models\User::where('hotel_id', $hotel->id)->where('username', 'laundry')->first() ?? $hk;
        if ($store ?? $hk) {
            Auth::login($store ?? $hk);
        }

        // ---------- linen stock: bought two months ago, most of it issued to the floors ----------
        $rooms = Models\Room::count();
        $types = Models\LinenType::orderBy('id')->get()->keyBy('code');
        $bought = ['BS-D' => 180, 'BS-S' => 40, 'DC' => 90, 'PC' => 400, 'BT' => 170, 'HT' => 196, 'FT' => 150, 'BM' => 70, 'BR' => 150, 'PT' => 60, 'TC' => 40, 'NP' => 240];
        $scale = $rich ? 1 : 0.4;
        foreach ($types as $code => $t) {
            $q = max(10, (int) round(($bought[$code] ?? 50) * $scale));
            $N::move($t, 'purchase', $q, ['date' => $today->copy()->subDays(60)->toDateString(), 'reason' => 'Opening linen stock']);
            $issue = (int) round($q * 0.55);
            $N::move($t->fresh(), 'issue', $issue, ['date' => $today->copy()->subDays(59)->toDateString(), 'reason' => 'Room sets / outlets']);
        }
        if ($rich) {
            Models\LinenType::whereIn('code', ['PT', 'TC'])->update(['par_override' => 60]);
            Models\LinenType::where('code', 'NP')->update(['par_override' => 200]);
        }
        $T = fn (string $c) => Models\LinenType::where('code', $c)->first();
        if (! $rich) {
            foreach (['BS-D' => 20, 'PC' => 30, 'BT' => 20] as $c => $q) {
                $N::move($T($c), 'collect', $q, ['date' => $today->toDateString()]);
            }
            Auth::forgetUser();

            return;
        }

        $vendor = Models\Supplier::create(['wht_exempt_ref' => 'GRA/WHT/EXM/2026/0142', 'wht_exempt_until' => $today->copy()->endOfYear()->toDateString(), // v4.4 exempt from WHT
            'name' => 'SparkleWash Laundry Services', 'contact_person' => 'Priya Nair', 'phone' => '+91 98860 41122', 'email' => 'accounts@sparklewash.example',
            'payment_terms_days' => 15, 'tax_number' => 'C0045778812', 'expense_head_id' => Models\AccountHead::sys('laundry_linen')]);
        Models\Setting::updateOrCreate(['key' => 'linen_default_supplier_id'], ['value' => (string) $vendor->id]);
        Models\Setting::$version++;
        $d = fn (int $ago) => $today->copy()->subDays($ago)->toDateString();
        $collect = function (int $ago, array $q) use ($N, $T, $d) {
            foreach ($q as $c => $n) {
                if (($issue = min($n, (int) $T($c)->qty_store)) > 0) { // clean sets go up as the soiled ones come down
                    $N::move($T($c), 'issue', $issue, ['date' => $d($ago), 'reason' => 'Replacement sets']);
                }
                $N::move($T($c), 'collect', $n, ['date' => $d($ago), 'reason' => 'Soiled from rooms']);
            }
        };
        $ids = fn (array $q) => collect($q)->mapWithKeys(fn ($n, $c) => [$T($c)->id => $n])->all();
        $backdate = function (Models\LinenBatch $b, ?int $closedAgo = null) use ($d) {
            if ($closedAgo !== null) {
                $b->forceFill(['closed_date' => $d($closedAgo)])->saveQuietly();
                Models\LinenMovement::where('linen_batch_id', $b->id)->where('type', 'lost')->update(['date' => $d($closedAgo)]);
            }
        };
        $lineMap = fn (Models\LinenBatch $b, array $q) => $b->fresh('lines.linen')->lines->mapWithKeys(fn ($l) => [$l->id => ['returned' => $q[$l->linen->code][0] ?? 0, 'damaged' => $q[$l->linen->code][1] ?? 0]])->all();

        // LB1: 9 days ago to SparkleWash, back 8 days ago with a few damaged → closed and billed
        $sent1 = ['BS-D' => 48, 'PC' => 96, 'BT' => 44, 'HT' => 40, 'FT' => 30, 'BM' => 20];
        $collect(9, $sent1);
        $b1 = $N::send(['supplier_id' => $vendor->id, 'sent_date' => $d(9), 'expected_date' => $d(8), 'notes' => 'Weekly room linen'], $ids($sent1));
        $N::receive($b1, $lineMap($b1, ['BS-D' => [46, 2], 'PC' => [96, 0], 'BT' => [43, 1], 'HT' => [40, 0], 'FT' => [30, 0], 'BM' => [20, 0]]), $d(8));
        $N::bill($b1->fresh(), ['supplier_ref' => 'SW/2026/0412', 'tax_rate' => 20, 'doc_date' => $d(8)]);
        // LB2: 6 days ago, part back 5 days ago, rest confirmed missing 3 days ago → closed short (lost), still to bill
        $sent2 = ['BS-D' => 40, 'DC' => 20, 'PC' => 80, 'BT' => 40, 'BR' => 10];
        $collect(6, $sent2);
        $b2 = $N::send(['supplier_id' => $vendor->id, 'sent_date' => $d(6), 'expected_date' => $d(5)], $ids($sent2));
        $N::receive($b2, $lineMap($b2, ['BS-D' => [38, 0], 'DC' => [20, 0], 'PC' => [77, 1], 'BT' => [40, 0], 'BR' => [9, 0]]), $d(5));
        $N::close($b2->fresh(), 'SparkleWash confirmed 2 sheets, 2 pillow covers and a bathrobe missing');
        $backdate($b2->fresh(), 3);
        // LB3: 2 days ago, part back yesterday, due yesterday → late
        $sent3 = ['BS-D' => 44, 'PC' => 88, 'BT' => 40, 'HT' => 36, 'FT' => 30];
        $collect(2, $sent3);
        $b3 = $N::send(['supplier_id' => $vendor->id, 'sent_date' => $d(2), 'expected_date' => $d(1)], $ids($sent3));
        $N::receive($b3, $lineMap($b3, ['BS-D' => [30, 0], 'PC' => [88, 0], 'BT' => [22, 0], 'HT' => [36, 0]]), $d(1));
        // LB4: in-house laundry yesterday – F&B linen, back the same day
        $sent4 = ['TC' => 18, 'NP' => 90];
        $collect(1, $sent4);
        $b4 = $N::send(['supplier_id' => null, 'sent_date' => $d(1), 'expected_date' => $d(1), 'notes' => 'Banquet linen – own washer'], $ids($sent4));
        $N::receive($b4, $lineMap($b4, ['TC' => [18, 0], 'NP' => [88, 2]]), $d(1));
        // LB5: today, open
        $sent5 = ['BS-D' => 36, 'PC' => 72, 'BT' => 34, 'BM' => 16];
        $collect(0, $sent5);
        $N::send(['supplier_id' => $vendor->id, 'sent_date' => $d(0), 'expected_date' => $today->copy()->addDay()->toDateString()], $ids($sent5));
        // soiled waiting today, a few write-offs and a count
        $collect(0, ['BS-D' => 14, 'PC' => 26, 'BT' => 12, 'HT' => 10, 'FT' => 8]);
        $N::move($T('BT'), 'discard', 3, ['from' => 'floor', 'date' => $d(4), 'reason' => 'Stained with hair dye – room 305']);
        $N::move($T('BR'), 'lost', 1, ['from' => 'floor', 'date' => $d(2), 'reason' => 'Missing after check-out, room 402 (charged to guest)']);
        $N::count($T('HT'), 'store', max(0, $T('HT')->qty_store - 4), 'Monthly linen count');
        Models\LinenMovement::where('type', 'adjust')->update(['date' => $d(0)]);
        Models\LinenMovement::whereNull('created_at')->orWhere('created_at', '>', now())->update(['created_at' => now()]);
        foreach (Models\LinenMovement::all() as $m) {
            $m->forceFill(['created_at' => Carbon::parse($m->date)->setTime(10, 0)->addMinutes($m->id % 300)])->saveQuietly();
        }

        // ---------- laundry orders ----------
        Auth::login($hk);
        $stays = Models\ReservationRoom::with('guest', 'room')->where('status', 'checked_in')->whereNotNull('room_id')->orderBy('room_id')->get()->values();
        if ($stays->count() < 6) {
            Auth::forgetUser();

            return;
        }
        $item = fn (string $name) => Models\LaundryItem::where('name', $name)->value('id');
        $lines = fn (array $spec) => collect($spec)->map(fn ($x) => ['laundry_item_id' => $item($x[0]), 'service' => $x[1], 'qty' => $x[2], 'remark' => $x[3] ?? null])->all();
        $at = function (Models\LaundryOrder $o, Carbon $collected, ?Carbon $ready = null, ?Carbon $delivered = null) use ($L) {
            $o = $o->fresh();
            $o->forceFill(['collected_at' => $collected, 'promised_at' => $collected->copy()->addHours($L::hours($o->speed)), 'created_at' => $collected,
                'ready_at' => $ready ?? $o->ready_at, 'delivered_at' => $delivered ?? $o->delivered_at, 'updated_at' => $delivered ?? $ready ?? $collected])->saveQuietly();
            if ($delivered && $o->folio_transaction_id) {
                Models\FolioTransaction::whereKey($o->folio_transaction_id)->update(['business_date' => $delivered->toDateString(), 'created_at' => $delivered, 'updated_at' => $delivered]);
                if ($o->type === 'walk_in') {
                    Models\FolioTransaction::where('folio_id', $o->folio_id)->update(['business_date' => $delivered->toDateString(), 'created_at' => $delivered, 'updated_at' => $delivered]);
                    Models\Folio::whereKey($o->folio_id)->update(['created_at' => $delivered, 'closed_at' => $delivered]);
                }
            }
        };
        $now = now();
        $day = fn (int $ago, int $h, int $m = 0) => $now->copy()->subDays($ago)->setTime($h, $m);
        $shirts = [['Shirt', 'wash', 3], ['Trousers', 'wash', 2], ['T-shirt', 'wash', 2]];

        // delivered to rooms (3, 2 and 1 days ago)
        foreach ([[0, 3, 'normal', [['Shirt', 'wash', 4], ['Trousers', 'wash', 2], ['Undergarment', 'wash', 3]]],
            [1, 2, 'express', [['Suit (2 pc)', 'dryclean', 1], ['Shirt', 'press', 2]]],
            [2, 1, 'normal', [['Saree', 'dryclean', 1, 'Silk – hand finish'], ['Kurta', 'wash', 2], ['Dress', 'wash', 1]]]] as [$i, $ago, $speed, $spec]) {
            $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[$i]->id, 'speed' => $speed, 'bag_no' => 'B-'.(100 + $i), 'lines' => $lines($spec)]);
            $L::markReady($o->fresh());
            $L::deliver($o->fresh());
            $c = $day($ago, 9, 30);
            $at($o, $c, $c->copy()->addHours($speed === 'express' ? 5 : 20), $c->copy()->addHours($speed === 'express' ? 6 : 22));
        }
        // walk-in customers – paid at the counter (cashier has the open shift)
        Auth::login($cashier);
        $cash = Models\PaymentMode::where('type', 'cash')->value('id');
        $card = Models\PaymentMode::where('type', 'card')->value('id') ?? $cash;
        foreach ([['Kofi Mensah', '+233 24 555 0142', 1, $cash, [['Suit (2 pc)', 'dryclean', 2], ['Tie', 'dryclean', 3]]],
            ['Neha Kapoor', '+91 98450 77120', 0, $card, [['Salwar suit (3 pc)', 'dryclean', 2], ['Sweater', 'dryclean', 1]]]] as [$name, $ph, $ago, $mode, $spec]) {
            $o = $L::create(['type' => 'walk_in', 'guest_name' => $name, 'phone' => $ph, 'speed' => 'normal', 'lines' => $lines($spec)]);
            $L::markReady($o->fresh());
            $L::deliver($o->fresh(), $mode);
            $c = $ago ? $day(2, 11) : $day(1, 10, 15);
            $at($o, $c, $c->copy()->addHours(20), $ago ? $day(1, 12) : $now->copy()->subHours(1));
        }
        Auth::login($hk);
        // ready to deliver, processing (one overdue), express in progress, collected but not itemised
        $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[3]->id, 'speed' => 'normal', 'bag_no' => 'B-114', 'lines' => $lines($shirts)]);
        $L::markReady($o->fresh());
        $at($o, $day(1, 8, 45), $now->copy()->subHours(2));
        $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[4]->id, 'speed' => 'normal', 'bag_no' => 'B-115', 'lines' => $lines([['Trousers', 'wash', 2], ['Shirt', 'wash', 3, 'Collar stain'], ['Socks (pair)', 'wash', 4]])]);
        $at($o, $now->copy()->subHours(27));
        $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[5]->id, 'speed' => 'express', 'bag_no' => 'B-116', 'notes' => 'Needed for a 6 pm meeting', 'lines' => $lines([['Jacket / blazer', 'dryclean', 1], ['Shirt', 'press', 2]])]);
        $at($o, $now->copy()->subHours(2));
        $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[0]->id, 'speed' => 'normal', 'bag_no' => 'B-117', 'bag_pieces' => 9]);
        $at($o, $now->copy()->subMinutes(40));
        // from a guest-portal request (in progress) and one request still waiting for pick-up
        if ($stays->count() > 7) {
            $req = \App\Services\GuestPortalService::raise($stays[6], ['category' => 'laundry', 'item' => 'Express laundry (same day)', 'notes' => 'Two shirts and a dress, please']);
            $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[6]->id, 'speed' => 'express', 'guest_request_id' => $req->id, 'bag_no' => 'B-118',
                'lines' => $lines([['Shirt', 'wash', 2], ['Dress', 'wash', 1]])]);
            $at($o, $now->copy()->subHours(1));
            \App\Services\GuestPortalService::raise($stays[7], ['category' => 'laundry', 'item' => 'Pick up my laundry', 'notes' => 'Bag is on the door hook']);
        }
        // cancelled
        $o = $L::create(['type' => 'guest', 'reservation_room_id' => $stays[1]->id, 'speed' => 'normal', 'bag_no' => 'B-109', 'bag_pieces' => 3]);
        $L::cancel($o->fresh(), 'Guest took the bag back – will use the express service tomorrow');
        $at($o, $day(2, 18));
        Auth::forgetUser();
    }

    protected function seedClub(bool $rich, Carbon $today): void
    {
        \App\Services\ClubService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $u = Auth::user();
        $plan = fn ($code) => Models\ClubPlan::where('code', $code)->first();
        $momo = Models\PaymentMode::where('type', 'wallet')->value('id');
        $card = Models\PaymentMode::where('type', 'card')->value('id');
        $d = fn (int $n) => $today->copy()->addDays($n)->toDateString();
        $CS = \App\Services\ClubService::class;
        $CB = \App\Services\ClubBillingService::class;
        $payAll = function ($m, string $date, int $mode, ?string $ref = null) use ($CB, $u) {
            $bal = $CB::balance($m);
            if ($bal > 0.004) {
                $CB::pay($m, $bal, $mode, $ref ?? 'TXN'.random_int(100000, 999999), $u, $date);
            }
        };
        $enrol = fn (string $code, string $holder, int $startOffset, array $persons = [], array $extra = []) => $CS::enrol(array_merge([
            'club_plan_id' => $plan($code)->id, 'holder_name' => $holder, 'phone' => '024'.random_int(1000000, 9999999), 'email' => strtolower(str_replace(' ', '.', $holder)).'@example.com',
            'start_date' => $d($startOffset), 'auto_renew' => true, 'persons' => $persons ?: [['name' => $holder, 'health' => 1, 'gender' => 'M']],
        ], $extra), $u, $d($startOffset));
        // renew on the member's schedule until the term runs past today
        $keepRenewed = function ($m, int $mode) use ($CS, $payAll, $today, $u) {
            while ($m->end_date->lt($today->copy()->addDays(5))) {
                $asOf = $m->end_date->copy()->subDays(4)->toDateString();
                $CS::renew($m, $u, $asOf);
                $m->refresh();
                $payAll($m, $asOf, $mode);
            }
        };

        $kwabena = $enrol('FULL-Y', 'Kwabena Asante', -200);
        $payAll($kwabena, $d(-200), $card);

        $ama = $enrol('FULL-M', 'Ama Serwaa', -75, [['name' => 'Ama Serwaa', 'gender' => 'F', 'dob' => '1991-04-12', 'health' => 1]]);
        $payAll($ama, $d(-75), $momo);
        $keepRenewed($ama, $momo);

        $kofi = $enrol('GYM-M', 'Kofi Mensah', -40, [['name' => 'Kofi Mensah', 'gender' => 'M', 'dob' => '1988-11-02', 'health' => 1]]);
        $payAll($kofi, $d(-40), $momo);
        $CS::renew($kofi, $u, $d(-16)); // renewal never paid → in arrears
        $kofi->refresh();

        $owusu = $enrol('FAM-Y', 'Owusu Family', -120, [
            ['name' => 'Emmanuel Owusu', 'gender' => 'M', 'dob' => '1982-06-20', 'health' => 1], ['name' => 'Gifty Owusu', 'relation' => 'spouse', 'gender' => 'F', 'dob' => '1985-02-14', 'health' => 1],
            ['name' => 'Kwame Owusu', 'relation' => 'child', 'gender' => 'M', 'dob' => $today->copy()->subYears(12)->subDays(40)->toDateString(), 'health' => 1],
            ['name' => 'Adwoa Owusu', 'relation' => 'child', 'gender' => 'F', 'dob' => $today->copy()->subYears(8)->subDays(100)->toDateString(), 'health' => 1]]);
        $payAll($owusu, $d(-120), $card);

        $company = Models\Company::orderBy('id')->first();
        $corp = $enrol('CORP-Y', 'Yaa Frimpong', -100, [['name' => 'Yaa Frimpong', 'relation' => 'primary', 'gender' => 'F', 'health' => 1]], ['company_id' => $company?->id, 'source' => 'corporate']);
        $payAll($corp, $d(-95), $card, 'Company cheque via card');

        $efua = $enrol('FULL-Q', 'Efua Darko', -50, [['name' => 'Efua Darko', 'gender' => 'F', 'dob' => '1994-09-30', 'health' => 1]]);
        $payAll($efua, $d(-50), $momo);
        $efua->update(['status' => 'frozen', 'frozen_from' => $d(-3), 'frozen_to' => $d(11), 'frozen_days' => 15, 'freeze_reason' => 'Working in Kumasi for two weeks', 'end_date' => $efua->end_date->copy()->addDays(15)->toDateString()]);

        $yaw = $enrol('GYM-M', 'Yaw Boakye', -70, [['name' => 'Yaw Boakye', 'gender' => 'M', 'health' => 1]], ['auto_renew' => false]);
        $payAll($yaw, $d(-70), $momo);

        $abena = $enrol('FULL-Q', 'Abena Kyei', -30, [['name' => 'Abena Kyei', 'gender' => 'F', 'health' => 1]]);
        $payAll($abena, $d(-30), $card);
        $CS::cancel($abena, 'immediate', 'Relocating to Takoradi', null, $u, $d(-5)); // credit note for the unused part stays on account

        $nana = $enrol('OFF-M', 'Nana Adjei', 4 - 30, [['name' => 'Nana Adjei', 'gender' => 'M']], ['auto_renew' => false, 'start_date' => $today->copy()->addDays(5)->subMonthNoOverflow()->toDateString()]);
        $payAll($nana, $d(-26), $momo);

        $esiStart = $today->copy()->addDays(3)->subMonthNoOverflow()->toDateString();
        $esi = $CS::enrol(['club_plan_id' => $plan('FULL-M')->id, 'holder_name' => 'Esi Quaye', 'phone' => '0551234567', 'start_date' => $esiStart, 'auto_renew' => true, 'persons' => [['name' => 'Esi Quaye', 'gender' => 'F', 'health' => 1]]], $u, $esiStart);
        $payAll($esi, $esiStart, $card);

        $stay = Models\ReservationRoom::with('guest')->where('status', 'checked_in')->orderBy('id')->first();
        if ($stay?->guest) {
            $gm = $enrol('FULL-M', $stay->guest->full_name, -10, [['name' => $stay->guest->full_name, 'health' => 1]], ['guest_id' => $stay->guest_id, 'source' => 'guest']);
            $payAll($gm, $d(-10), $card);
        }

        $cpl = $enrol('CPL-Y', 'Daniel Boateng', -150, [['name' => 'Daniel Boateng', 'gender' => 'M', 'health' => 1], ['name' => 'Akua Boateng', 'relation' => 'spouse', 'gender' => 'F', 'health' => 1]]);
        $payAll($cpl, $d(-150), $card);

        $selorm = $enrol('FULL-M', 'Selorm Agbeko', -20, [['name' => 'Selorm Agbeko', 'gender' => 'M', 'health' => 1]]);
        $payAll($selorm, $d(-20), $momo);
        $CS::cancel($selorm, 'term_end', 'Joining a gym nearer to work', null, $u, $d(-2));

        $stu = $enrol('STU-M', 'Kojo Antwi', -3, [['name' => 'Kojo Antwi', 'gender' => 'M', 'dob' => '2004-03-08', 'health' => 1]]);
        $CB::pay($stu, 121.00, $momo, 'TXN'.random_int(100000, 999999), $u, $d(-3)); // joining fee only – subscription still due

        $CS::expiryRun($today->toDateString()); // Yaw's term lapses

        // ---- visits: two weeks of history, refusals, an override, and people in the club right now
        $now = now();
        $active = Models\ClubMember::with('membership.plan')->whereHas('membership', fn ($q) => $q->where('status', 'active'))->where('is_active', true)->get()
            ->reject(fn ($p) => $p->club_membership_id === $kofi->id);
        foreach (range(13, 1) as $back) {
            foreach ($active as $p) {
                if (random_int(1, 100) > 35 || $p->membership->start_date->gt($now->copy()->subDays($back))) {
                    continue;
                }
                $fac = collect($p->membership->plan->facilities)->reject(fn ($f) => in_array($f, ['classes', 'kids']))->values();
                $in = $now->copy()->subDays($back)->setTime(random_int(6, 19), random_int(0, 59));
                if ($p->membership->plan->access_to && $in->format('H:i:s') > $p->membership->plan->access_to) {
                    $in->setTime(random_int(6, 14), random_int(0, 59));
                }
                Models\ClubVisit::create(['visitor_type' => 'member', 'club_member_id' => $p->id, 'name' => $p->name, 'facility' => $fac->random(), 'checked_in_at' => $in, 'checked_out_at' => $in->copy()->addMinutes(random_int(40, 110)), 'user_id' => $u->id]);
            }
        }
        $kofiCard = $kofi->members()->first();
        [$od, $odSince] = $CB::overdue($kofi);
        foreach ([[3, 'override'], [1, 'deny'], [0, 'deny']] as [$back, $kind]) {
            $in = $back ? $now->copy()->subDays($back)->setTime(18, 10) : $now->copy()->subMinutes(95);
            Models\ClubVisit::create(['visitor_type' => 'member', 'club_member_id' => $kofiCard->id, 'name' => $kofiCard->name, 'facility' => 'gym', 'checked_in_at' => $in,
                'checked_out_at' => $kind === 'deny' ? $in : $in->copy()->addMinutes(70), 'denied' => $kind === 'deny', 'user_id' => $u->id,
                'deny_reason' => $kind === 'deny' ? 'Overdue balance '.money($od).' (due since '.dmy($odSince).').' : null,
                'override_reason' => $kind === 'override' ? 'Promised to pay by MoMo tonight' : null, 'override_by' => $kind === 'override' ? $u->id : null]);
        }
        $amaCard = $ama->members()->first();
        Models\ClubVisit::create(['visitor_type' => 'guest', 'host_member_id' => $amaCard->id, 'name' => 'Afia Nyarko', 'facility' => 'pool', 'checked_in_at' => $now->copy()->startOfMonth()->addHours(10)->min($now->copy()->subHours(5)), 'checked_out_at' => $now->copy()->startOfMonth()->addHours(11)->min($now->copy()->subHours(4)), 'user_id' => $u->id]);
        $inNow = [[$kwabena->members()->first(), 'gym', 25], [$amaCard, 'pool', 40], [$owusu->members()->where('relation', 'child')->first(), 'pool', 55], [$cpl->members()->first(), 'tennis', 30], [$cpl->members()->skip(1)->first(), 'tennis', 30]];
        foreach ($inNow as [$p, $fac, $min]) {
            Models\ClubVisit::create(['visitor_type' => 'member', 'club_member_id' => $p->id, 'name' => $p->name, 'facility' => $fac, 'checked_in_at' => $now->copy()->subMinutes($min), 'user_id' => $u->id]);
        }
        Models\ClubVisit::create(['visitor_type' => 'guest', 'host_member_id' => $amaCard->id, 'name' => 'Linda Owusu-Ansah', 'facility' => 'pool', 'checked_in_at' => $now->copy()->subMinutes(38), 'user_id' => $u->id]); // 2nd free pass → none left
        if ($stay) {
            Models\ClubVisit::create(['visitor_type' => 'resident', 'reservation_room_id' => $stay->id, 'name' => ($stay->guest?->full_name ?? 'Guest').' (Rm '.$stay->room?->room_no.')', 'facility' => 'pool', 'pax' => 2, 'checked_in_at' => $now->copy()->subMinutes(20), 'user_id' => $u->id]);
            foreach ([2, 5, 9] as $back) {
                $in = $now->copy()->subDays($back)->setTime(7, 15);
                Models\ClubVisit::create(['visitor_type' => 'resident', 'reservation_room_id' => $stay->id, 'name' => ($stay->guest?->full_name ?? 'Guest').' (Rm '.$stay->room?->room_no.')', 'facility' => 'gym', 'checked_in_at' => $in, 'checked_out_at' => $in->copy()->addHour(), 'user_id' => $u->id]);
            }
        }

        // ---- day passes: two weeks of walk-ins, one charged to a room today, one cancelled today, one in the pool now
        $names = ['Samuel Tetteh', 'Joyce Amankwah', 'Peter Addo', 'Mavis Ofori', 'Isaac Nkrumah', 'Comfort Asare', 'Richard Lamptey', 'Grace Adomako', 'Eric Sarpong', 'Linda Badu', 'Patrick Ayew', 'Rita Quansah', 'Francis Osei', 'Doris Mensah', 'George Appiah', 'Beatrice Kumi'];
        foreach ($names as $i => $n) {
            $back = 13 - intdiv($i * 13, count($names));
            $dp = $CS::sellDayPass(['name' => $n, 'phone' => '020'.random_int(1000000, 9999999), 'adults' => random_int(1, 2), 'children' => $i % 4 === 0 ? 1 : 0, 'payment_mode_id' => $i % 2 ? $card : $momo, 'reference' => 'DP'.random_int(1000, 9999)], $u, null, false, $d(-$back));
            $in = \Carbon\Carbon::parse($d(-$back))->setTime(9 + $i % 8, 15);
            Models\ClubVisit::create(['visitor_type' => 'day_pass', 'club_day_pass_id' => $dp->id, 'name' => $dp->name, 'facility' => $i % 3 ? 'pool' : 'gym', 'pax' => $dp->adults + $dp->children, 'checked_in_at' => $in, 'checked_out_at' => $in->copy()->addMinutes(150), 'user_id' => $u->id]);
        }
        if ($stay) {
            $CS::sellDayPass(['name' => 'Visitor of Rm '.$stay->room?->room_no, 'adults' => 1, 'room_no' => $stay->room?->room_no], $u, null, false);
        }
        $x = $CS::sellDayPass(['name' => 'Kwesi Arthur', 'adults' => 1, 'payment_mode_id' => $momo], $u, null, false);
        $CS::cancelDayPass($x, 'Visitor changed his mind before entering', $u);
        $CS::sellDayPass(['name' => 'Harriet Nyamekye', 'adults' => 1, 'children' => 1, 'payment_mode_id' => $card, 'reference' => 'POS 55621'], $u, 'pool', false);

        // ---- classes: staff instructor, past attendance (Kofi: 3 no-shows), upcoming bookings, a full session with a waitlist
        if ($emp = Models\Employee::where('hotel_id', Tenant::id())->where('is_active', true)->whereHas('department', fn ($q) => $q->where('name', 'like', 'Spa%'))->first()) {
            Models\ClubClass::whereIn('name', ['Aqua Aerobics', 'Kids Swim School'])->update(['employee_id' => $emp->id, 'instructor' => null]);
        }
        $pool = Models\ClubMember::with('membership')->where('is_active', true)->whereHas('membership', fn ($q) => $q->whereIn('status', ['active', 'frozen']))->get()
            ->reject(fn ($p) => $p->club_membership_id === $kofi->id)->values();
        $kofiNoShows = 0;
        $spinDone = false;
        foreach (Models\ClubClass::where('is_active', true)->get() as $c) {
            for ($back = 14; $back >= -7; $back--) {
                $date = $today->copy()->addDays(-$back);
                if ((int) $date->isoWeekday() !== (int) $c->weekday) {
                    continue;
                }
                $start = Carbon::parse($date->toDateString().' '.$c->start_time);
                $past = $start->lt($now);
                $spin = ! $past && $c->name === 'Spin Express' && ! $spinDone;
                $spinDone = $spinDone || $spin;
                $take = $spin ? $pool->count() : random_int(3, min(7, $pool->count()));
                foreach ($pool->shuffle()->take($take)->values() as $i => $p) {
                    $wait = $spin && $i >= $c->capacity;
                    Models\ClubBooking::create(['club_class_id' => $c->id, 'date' => $date->toDateString(), 'club_member_id' => $p->id, 'name' => $p->name,
                        'status' => $past ? (random_int(1, 100) <= 82 ? 'attended' : 'no_show') : ($wait ? 'waitlist' : 'booked'), 'position' => $wait ? $i - $c->capacity + 1 : null, 'user_id' => $u->id]);
                }
                if ($past && $kofiNoShows < 3 && $back <= 20) {
                    Models\ClubBooking::create(['club_class_id' => $c->id, 'date' => $date->toDateString(), 'club_member_id' => $kofiCard->id, 'name' => $kofiCard->name, 'status' => 'no_show', 'user_id' => $u->id]);
                    $kofiNoShows++;
                }
            }
        }
    }

    protected function seedLeases(bool $rich, Carbon $today): void
    {
        \App\Services\LeaseService::ensureDefaults();
        if (! $rich) {
            return;
        }
        $u = Auth::user() ?? Models\User::where('username', 'admin')->first();
        $LS = \App\Services\LeaseService::class;
        $LB = \App\Services\LeaseBillingService::class;
        $momo = Models\PaymentMode::where('type', 'wallet')->value('id');
        $card = Models\PaymentMode::where('type', 'card')->value('id');
        $d = fn (int $n) => $today->copy()->addDays($n)->toDateString();
        $ahead = (int) $LS::cfg('lease_bill_ahead_days');
        $blk = 'Nowills Residences – Block A';
        $unit = fn (array $a) => $LS::saveUnit($a + ['block' => $blk, 'furnished' => 1, 'is_active' => 1]);
        $units = [
            'A-101' => $unit(['code' => 'A-101', 'name' => 'Studio apartment', 'type' => 'studio', 'floor' => '1', 'bedrooms' => 0, 'size_sqm' => 38, 'tax_class' => 'serviced', 'list_rent' => 4500, 'service_charge' => 300, 'elec_meter' => 'ECG-P1101', 'amenities' => 'AC, Wi-Fi, kitchenette, weekly housekeeping']),
            'A-102' => $unit(['code' => 'A-102', 'name' => 'One-bedroom apartment', 'type' => '1bed', 'floor' => '1', 'bedrooms' => 1, 'size_sqm' => 62, 'tax_class' => 'serviced', 'list_rent' => 7500, 'service_charge' => 500, 'elec_meter' => 'ECG-P1102', 'amenities' => 'AC, Wi-Fi, full kitchen, pool & gym, twice-weekly housekeeping']),
            'A-103' => $unit(['code' => 'A-103', 'name' => 'Studio apartment', 'type' => 'studio', 'floor' => '1', 'bedrooms' => 0, 'size_sqm' => 36, 'tax_class' => 'serviced', 'list_rent' => 4200, 'service_charge' => 300, 'elec_meter' => 'ECG-P1103']),
            'A-201' => $unit(['code' => 'A-201', 'name' => 'Two-bedroom executive apartment', 'type' => '2bed', 'floor' => '2', 'bedrooms' => 2, 'size_sqm' => 96, 'tax_class' => 'serviced', 'list_rent' => 11000, 'service_charge' => 800, 'elec_meter' => 'ECG-P1201', 'water_meter' => 'GWL-2201', 'amenities' => 'AC, Wi-Fi, 2 parking bays, pool & gym, daily housekeeping']),
            'A-202' => $unit(['code' => 'A-202', 'name' => 'Three-bedroom family apartment', 'type' => '3bed', 'floor' => '2', 'bedrooms' => 3, 'size_sqm' => 128, 'furnished' => 0, 'tax_class' => 'residential', 'list_rent' => 9000, 'service_charge' => 0, 'elec_meter' => 'ECG-P1202', 'amenities' => 'Unfurnished, prepaid ECG meter, 2 parking bays']),
            'A-301' => $unit(['code' => 'A-301', 'name' => 'Penthouse with roof terrace', 'type' => 'penthouse', 'floor' => '3', 'bedrooms' => 3, 'size_sqm' => 180, 'tax_class' => 'serviced', 'list_rent' => 18000, 'service_charge' => 1200, 'elec_meter' => 'ECG-P1301', 'amenities' => 'Roof terrace, AC, Wi-Fi, butler service, 3 parking bays']),
            'A-302' => $unit(['code' => 'A-302', 'name' => 'Two-bedroom apartment', 'type' => '2bed', 'floor' => '3', 'bedrooms' => 2, 'size_sqm' => 92, 'tax_class' => 'serviced', 'list_rent' => 10500, 'service_charge' => 800, 'status' => 'maintenance', 'amenities' => 'Kitchen & bathroom refurbishment until next month']),
            'B-G01' => $unit(['code' => 'B-G01', 'name' => 'Ground-floor office suite', 'type' => 'office', 'block' => 'Nowills Business Wing', 'floor' => 'G', 'bedrooms' => 0, 'size_sqm' => 110, 'furnished' => 0, 'tax_class' => 'commercial', 'list_rent' => 14000, 'service_charge' => 1200, 'elec_meter' => 'ECG-C0001']),
            'B-G02' => $unit(['code' => 'B-G02', 'name' => 'Lobby retail kiosk', 'type' => 'shop', 'block' => 'Nowills Business Wing', 'floor' => 'G', 'bedrooms' => 0, 'size_sqm' => 12, 'furnished' => 0, 'tax_class' => 'commercial', 'list_rent' => 2500, 'service_charge' => 0]),
        ];
        // invoice each period on its bill-ahead date until the next period is beyond the window
        $catchUp = function ($l) use ($LS, $u, $today, $ahead) {
            while (true) {
                $l->refresh();
                $ps = $l->billed_to ? $l->billed_to->copy()->addDay() : $l->start_date->copy();
                if ($ps->gt($l->lastDay()) || $ps->copy()->subDays($ahead)->gt($today)) {
                    break;
                }
                $LS::bill($l, $u, max($ps->copy()->subDays($ahead)->toDateString(), $l->start_date->toDateString()));
            }
        };
        // pay every rent period whose invoice date is on or before $until, a few days after it was raised
        $payPeriods = function ($l, string $until, int $mode, bool $wht = false) use ($LB, $u) {
            $rents = Models\LeaseCharge::where('lease_id', $l->id)->where('type', 'rent')->whereNull('cancelled_at')->where('date', '<=', $until)->orderBy('period_start')->get();
            foreach ($rents as $r) {
                $group = Models\LeaseCharge::where('lease_id', $l->id)->whereNull('cancelled_at')->where('period_start', $r->period_start)->whereIn('type', ['rent', 'service_charge'])->get();
                $total = round((float) $group->sum('total') - (float) $group->sum('paid'), 2);
                if ($total <= 0.004) {
                    continue;
                }
                $w = $wht ? round((float) $r->amount * (float) $l->wht_pct / 100, 2) : 0;
                $LB::pay($l, 'payment', $total - $w, $mode, 'TXN'.random_int(100000, 999999), $u, min($until, $r->due_date->copy()->addDays(random_int(0, 3))->toDateString()), $w, $w ? 'WHT/'.$r->due_date->format('ym').'/'.random_int(100, 999) : null);
            }
        };
        $mk = fn (array $a, int $startOffset) => $LS::create($a + ['start_date' => $d($startOffset)], $u, $d($startOffset));
        $moveIn = function ($l, array $readings = []) use ($LS, $u) {
            $LS::sign($l, $l->company ? 'For '.$l->company->name.': '.$l->tenant_name : $l->tenant_name);
            $LS::activate($l, $readings, collect(Models\LeaseInspection::CHECKLIST)->flatMap(fn ($items, $area) => collect($items)->map(fn ($i) => ['area' => $area, 'item' => $i, 'condition' => 'good']))->values()->all(), $u, $l->start_date->toDateString());
            Models\Lease::whereKey($l->id)->update(['signed_at' => $l->start_date->copy()->subDays(3)->setTime(11, 0)]);
            $l->refresh();
        };
        $infosys = Models\Company::where('name', 'like', 'Infosys%')->first();
        $tcs = Models\Company::where('name', 'like', 'Tata%')->first() ?? $infosys;

        // 1. corporate 2-bed, monthly, 8% WHT, metered electricity & water, readings every month
        $l1 = $mk(['lease_unit_id' => $units['A-201']->id, 'company_id' => $infosys?->id, 'tenant_name' => 'Rahul Menon', 'phone' => '+233 20 555 0142', 'email' => 'rahul.menon@example.com', 'id_type' => 'Passport', 'id_no' => 'Z5561203',
            'occupants' => 'Priya Menon (spouse)', 'term_months' => 12, 'rent' => 11000, 'service_charge' => 800, 'cycle_months' => 1, 'deposit_amount' => 22000, 'elec_mode' => 'metered', 'water_mode' => 'metered',
            'inclusions' => 'Daily housekeeping, Wi-Fi, 2 parking bays, pool & gym', 'notice_days' => 60, 'wht_pct' => 8], -100);
        $LB::pay($l1, 'deposit', 22000, $card, 'INFY-PO-7781', $u, $d(-102));
        $moveIn($l1, ['electricity' => 18450, 'water' => 902]);
        $e = 18450.0; $w = 902.0;
        foreach ([-70, -40, -10] as $off) {
            $e += random_int(520, 680); $w += random_int(14, 22);
            $LS::recordReading($units['A-201'], 'electricity', $e, $u, $d($off));
            $LS::recordReading($units['A-201'], 'water', $w, $u, $d($off));
        }
        $catchUp($l1);
        $payPeriods($l1, $d(-5), $card, true);
        $l1->refresh();
        foreach (Models\LeaseCharge::where('lease_id', $l1->id)->whereIn('type', ['electricity', 'water'])->where('date', '<', $d(-20))->get() as $c) {
            $LB::pay($l1, 'payment', (float) $c->total, $card, 'UTIL-'.$c->charge_no, $u, $c->date->copy()->addDays(6)->toDateString()); // receipts settle the oldest due first
        }

        // 2. expat 1-bed, 6 months billed & paid half-yearly in advance
        $l2 = $mk(['lease_unit_id' => $units['A-102']->id, 'tenant_name' => 'Jean-Luc Moreau', 'phone' => '+233 24 410 7733', 'email' => 'jl.moreau@example.com', 'id_type' => 'Passport', 'id_no' => '19FV22817',
            'term_months' => 6, 'rent' => 7500, 'service_charge' => 500, 'cycle_months' => 6, 'deposit_amount' => 8000, 'inclusions' => 'Twice-weekly housekeeping, Wi-Fi, pool & gym'], -45);
        $LB::pay($l2, 'deposit', 8000, $momo, 'MM'.random_int(10000000, 99999999), $u, $d(-47));
        $moveIn($l2);
        $catchUp($l2);
        $payPeriods($l2, $today->toDateString(), $card);

        // 3. residential 3-bed, quarterly (Rent Act cap: 6 months), current quarter only part-paid → arrears
        $l3 = $mk(['lease_unit_id' => $units['A-202']->id, 'tenant_name' => 'Kwame & Efua Boateng', 'phone' => '+233 27 301 8841', 'email' => 'kboateng@example.com', 'id_type' => 'Ghana Card', 'id_no' => 'GHA-712004381-5',
            'occupants' => '2 children', 'tax_class' => 'residential', 'term_months' => 12, 'rent' => 9000, 'service_charge' => 0, 'cycle_months' => 3, 'deposit_amount' => 18000, 'elec_mode' => 'prepaid', 'water_mode' => 'included'], -200);
        $LB::pay($l3, 'deposit', 18000, $momo, 'MM'.random_int(10000000, 99999999), $u, $d(-203));
        $moveIn($l3);
        $catchUp($l3);
        $payPeriods($l3, $d(-60), $momo);
        $q3 = Models\LeaseCharge::where('lease_id', $l3->id)->where('type', 'rent')->orderByDesc('period_start')->first();
        $LB::pay($l3, 'payment', 10000, $momo, 'MM'.random_int(10000000, 99999999), $u, min($today->toDateString(), $q3->due_date->copy()->addDays(2)->toDateString()), 0, null, 'Part payment – balance promised end of month');

        // 4. office suite, yearly, 3-year term, +10% rent review each year (year 2 already reviewed), company withholds 8%
        $l4 = $mk(['lease_unit_id' => $units['B-G01']->id, 'company_id' => $tcs?->id, 'tenant_name' => 'Neha Joshi (country office)', 'phone' => '+233 30 277 4410', 'email' => 'accra.office@example.com', 'id_type' => 'Company registration', 'id_no' => 'CS081122019',
            'tax_class' => 'commercial', 'term_months' => 36, 'rent' => 14000, 'service_charge' => 1200, 'cycle_months' => 12, 'deposit_amount' => 28000, 'escalation_pct' => 10, 'escalation_months' => 12, 'notice_days' => 90,
            'elec_mode' => 'prepaid', 'wht_pct' => 8, 'inclusions' => 'Reception, cleaning of common areas, 4 parking bays'], -430);
        $LB::pay($l4, 'deposit', 28000, $card, 'TCS-DEP', $u, $d(-433));
        $moveIn($l4);
        $catchUp($l4);
        $payPeriods($l4, $today->toDateString(), $card, true);

        // 5. lobby kiosk, monthly – last two months unpaid, a 5% late fee on the older one
        $l5 = $mk(['lease_unit_id' => $units['B-G02']->id, 'tenant_name' => 'Abena Owusu t/a Abena Mobile Accessories', 'phone' => '+233 55 918 2230', 'id_type' => 'Ghana Card', 'id_no' => 'GHA-715338210-2',
            'tax_class' => 'commercial', 'term_months' => 12, 'rent' => 2500, 'service_charge' => 0, 'cycle_months' => 1, 'deposit_amount' => 2500, 'notice_days' => 30], -120);
        $LB::pay($l5, 'deposit', 2500, $momo, 'MM'.random_int(10000000, 99999999), $u, $d(-121));
        $moveIn($l5);
        $catchUp($l5);
        $payPeriods($l5, $d(-60), $momo);
        $old = Models\LeaseCharge::where('lease_id', $l5->id)->where('type', 'rent')->whereRaw('total - paid > 0.004')->orderBy('period_start')->first();
        if ($old && $old->due_date->lt($today->copy()->subDays(10))) {
            $LB::raise($l5, 'late_fee', 'Late payment fee 5% on '.$old->charge_no, round($old->outstanding() * 0.05, 2), ['date' => $old->due_date->copy()->addDays(6)->toDateString(), 'due_date' => $old->due_date->copy()->addDays(6)->toDateString(), 'late_fee_of_id' => $old->id], $u);
        }

        // 6. studio on notice – the tenant leaves in 10 days
        $l6 = $mk(['lease_unit_id' => $units['A-101']->id, 'tenant_name' => 'Dr. Yaw Ofori-Atta', 'phone' => '+233 20 811 4478', 'email' => 'yaw.ofori@example.com', 'id_type' => 'Ghana Card', 'id_no' => 'GHA-709812334-1',
            'term_months' => 12, 'rent' => 4500, 'service_charge' => 300, 'cycle_months' => 1, 'deposit_amount' => 9000, 'elec_mode' => 'included'], -170);
        $LB::pay($l6, 'deposit', 9000, $momo, 'MM'.random_int(10000000, 99999999), $u, $d(-171));
        $moveIn($l6);
        $LS::giveNotice($l6, 'tenant', $d(10), 'Transferred to Kumasi', $u, $d(-20));
        $catchUp($l6);
        $payPeriods($l6, $today->toDateString(), $momo);

        // 7. ended lease: 6 months, moved out 2 months ago with damages, deposit applied & the rest refunded
        $l7 = $mk(['lease_unit_id' => $units['A-103']->id, 'tenant_name' => 'Sarah Whitfield', 'phone' => '+44 7700 900412', 'email' => 's.whitfield@example.com', 'id_type' => 'Passport', 'id_no' => '553018842',
            'term_months' => 6, 'rent' => 4200, 'service_charge' => 300, 'cycle_months' => 1, 'deposit_amount' => 8400, 'elec_mode' => 'metered'], -250);
        $LB::pay($l7, 'deposit', 8400, $card, 'DEP-SW', $u, $d(-251));
        $moveIn($l7, ['electricity' => 7020]);
        $catchUp($l7);
        $payPeriods($l7, $today->toDateString(), $card);
        $l7->refresh();
        $out = $l7->end_date->toDateString();
        Models\Lease::whereKey($l7->id)->update(['status' => 'notice', 'notice_on' => $l7->end_date->copy()->subDays(35)->toDateString(), 'notice_by' => 'tenant', 'move_out_on' => $out, 'end_reason' => 'Assignment in Ghana completed']);
        $l7->refresh();
        $items = collect(Models\LeaseInspection::CHECKLIST)->flatMap(fn ($its, $area) => collect($its)->map(fn ($i) => ['area' => $area, 'item' => $i, 'condition' => 'good']))->values()->all();
        foreach ($items as &$it) {
            if ($it['item'] === 'Walls & paint') { $it = ['condition' => 'damaged', 'note' => 'Wall stained and scuffed behind the sofa – repaint', 'charge' => 650] + $it; }
            if ($it['item'] === 'TV & remote') { $it = ['condition' => 'missing', 'note' => 'TV remote not returned', 'charge' => 200] + $it; }
        }
        unset($it);
        // record the move-out on its own business date (the service only accepts today or earlier and dates credits by the business date)
        $realBdate = Models\Hotel::whereKey(\App\Support\Tenant::id())->value('business_date');
        Models\Hotel::whereKey(\App\Support\Tenant::id())->update(['business_date' => $out]);
        \App\Support\Tenant::set(Models\Hotel::find(\App\Support\Tenant::id()));
        try {
            $LS::moveOut($l7, $out, ['electricity' => 7020 + random_int(230, 270) * 6], $items, 'Sarah Whitfield', $u);
        } finally {
            Models\Hotel::whereKey(\App\Support\Tenant::id())->update(['business_date' => $realBdate]);
            \App\Support\Tenant::set(Models\Hotel::find(\App\Support\Tenant::id()));
        }
        $l7->refresh();
        $s = $LS::settlement($l7);
        if ($s['apply'] > 0) {
            $LB::pay($l7, 'deposit_applied', $s['apply'], null, 'Move-out settlement', $u, Carbon::parse($out)->addDays(5)->toDateString());
        }
        if ($s['refund'] > 0) {
            $LB::pay($l7, 'deposit_refund', $s['refund'], $card, 'Refund to card ending 4412', $u, Carbon::parse($out)->addDays(12)->toDateString());
        }
        Models\Lease::whereKey($l7->id)->update(['settled_at' => Carbon::parse($out)->addDays(12)->setTime(15, 0)]);

        // 8. signed draft on the same studio, starting next week – deposit received
        $l8 = $mk(['lease_unit_id' => $units['A-103']->id, 'tenant_name' => 'Nana Akua Addo', 'phone' => '+233 24 660 1983', 'email' => 'nana.addo@example.com', 'id_type' => 'Ghana Card', 'id_no' => 'GHA-718220917-4',
            'term_months' => 6, 'rent' => 4200, 'service_charge' => 300, 'cycle_months' => 1, 'deposit_amount' => 8400, 'elec_mode' => 'metered'], 6);
        $LS::sign($l8, 'Nana Akua Addo');
        $LB::pay($l8, 'deposit', 8400, $momo, 'MM'.random_int(10000000, 99999999), $u, $today->toDateString());

        \App\Services\LedgerService::sync(true);
    }

}
