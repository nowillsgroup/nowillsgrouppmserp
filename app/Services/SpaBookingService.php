<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Guest;
use App\Models\ReservationRoom;
use App\Models\SpaAppointment;
use App\Models\SpaService;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Spa, salon, fitness & activity bookings: therapist / treatment-room clash checks,
 * settlement to the in-house folio, direct payment via a non-resident folio, or complimentary.
 */
class SpaBookingService
{
    public static function therapists(): Collection
    {
        return Employee::where('is_active', true)->whereHas('department', fn ($q) => $q->where('name', 'like', '%Spa%'))->orderBy('name')->get();
    }

    /** @return array<string> "HH:MM" slots between spa_open and spa_close */
    public static function slots(): array
    {
        $step = max(15, (int) setting('spa_slot_min', 30));
        $t = Carbon::parse(setting('spa_open', '09:00'));
        $end = Carbon::parse(setting('spa_close', '21:00'));
        $out = [];
        while ($t < $end && count($out) < 96) {
            $out[] = $t->format('H:i');
            $t->addMinutes($step);
        }

        return $out;
    }

    /** Human-readable clash message, or null when the slot is free. */
    public static function clash(string $date, string $start, string $end, ?int $employeeId, ?int $roomId, ?int $exceptId = null): ?string
    {
        if (! $employeeId && ! $roomId) {
            return null;
        }
        $hit = SpaAppointment::with('therapist', 'spaRoom')->whereDate('date', $date)->whereNotIn('status', ['cancelled', 'no_show'])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where('start_time', '<', $end)->where('end_time', '>', $start)
            ->where(fn ($q) => $q->when($employeeId, fn ($w) => $w->orWhere('employee_id', $employeeId))->when($roomId, fn ($w) => $w->orWhere('spa_room_id', $roomId)))
            ->first();
        if (! $hit) {
            return null;
        }
        $who = $employeeId && $hit->employee_id === $employeeId ? $hit->therapist?->name : 'Treatment room '.$hit->spaRoom?->name;

        return "{$who} is already booked ".substr($hit->start_time, 0, 5).'–'.substr($hit->end_time, 0, 5)." ({$hit->appt_no}).";
    }

    /** Create or update (reschedule) an appointment. */
    public static function save(array $d, ?SpaAppointment $appt = null): SpaAppointment
    {
        $service = SpaService::findOrFail($d['spa_service_id']);
        $start = Carbon::parse($d['date'].' '.$d['start_time']);
        $end = (clone $start)->addMinutes(max(5, (int) $service->duration_min));
        if ($end->toDateString() !== $start->toDateString()) {
            throw new RuntimeException('The treatment must finish on the same day.');
        }
        if (! $appt && $start->toDateString() < bdate()->toDateString()) {
            throw new RuntimeException('Cannot book in the past.');
        }
        if ($msg = self::clash($d['date'], $start->format('H:i:s'), $end->format('H:i:s'), $d['employee_id'] ?? null, $d['spa_room_id'] ?? null, $appt?->id)) {
            throw new RuntimeException($msg);
        }
        $stay = ! empty($d['reservation_room_id']) ? ReservationRoom::with('guest', 'room')->find($d['reservation_room_id']) : null;
        if ($stay && $stay->status !== 'checked_in' && $stay->status !== 'reserved') {
            throw new RuntimeException('Pick a stay that is in-house or due to arrive.');
        }
        $stayGuest = $stay ? ($stay->guest ?? $stay->reservation?->guest) : null;
        $pax = max(1, (int) ($d['pax'] ?? 1));
        $row = [
            'spa_service_id' => $service->id, 'employee_id' => $d['employee_id'] ?? null, 'spa_room_id' => $d['spa_room_id'] ?? null,
            'reservation_room_id' => $stay?->id, 'guest_id' => $stayGuest?->id ?? ($d['guest_id'] ?? null),
            'customer_name' => $stayGuest ? $stayGuest->full_name : trim($d['customer_name'] ?? ''), 'customer_phone' => $stayGuest ? $stayGuest->phone : ($d['customer_phone'] ?? null),
            'date' => $start->toDateString(), 'start_time' => $start->format('H:i:s'), 'end_time' => $end->format('H:i:s'), 'pax' => $pax,
            'price' => isset($d['price']) && $d['price'] !== '' ? round((float) $d['price'], 2) : round($service->price * $pax, 2),
            'discount' => round((float) ($d['discount'] ?? 0), 2), 'notes' => $d['notes'] ?? null,
        ];
        if (! $row['customer_name']) {
            throw new RuntimeException('Enter the customer name or pick an in-house guest.');
        }
        if ($row['discount'] > $row['price']) {
            throw new RuntimeException('Discount cannot exceed the price.');
        }

        return DB::transaction(function () use ($row, $appt) {
            if ($appt) {
                if (! $appt->isOpen()) {
                    throw new RuntimeException('Only booked appointments can be changed.');
                }
                $appt->update($row);
                ActivityLogger::log('spa', 'update', "Appointment {$appt->appt_no} updated", $appt);

                return $appt;
            }
            $appt = SpaAppointment::create($row + ['appt_no' => Numbering::next('spa'), 'status' => 'booked', 'created_by' => auth()->id()]);
            ActivityLogger::log('spa', 'create', "Booked {$appt->appt_no}: {$appt->service->name} for {$appt->customer_name}", $appt);

            return $appt;
        });
    }

    public static function setStatus(SpaAppointment $appt, string $status, ?string $reason = null): SpaAppointment
    {
        $allowed = ['in_progress' => ['booked'], 'cancelled' => ['booked'], 'no_show' => ['booked'], 'booked' => ['in_progress']];
        if (! in_array($appt->status, $allowed[$status] ?? [])) {
            throw new RuntimeException("Cannot change a {$appt->status} appointment to {$status}.");
        }
        if ($status === 'cancelled' && ! trim((string) $reason)) {
            throw new RuntimeException('A cancellation reason is required.');
        }
        $appt->update(['status' => $status, 'cancel_reason' => $status === 'cancelled' ? $reason : $appt->cancel_reason]);
        ActivityLogger::log('spa', $status, "Appointment {$appt->appt_no} → {$status}".($reason ? ": {$reason}" : ''), $appt);

        return $appt;
    }

    /**
     * Complete & settle. room_post = charge the in-house guest's folio; payment = charge + pay + close a non-resident folio;
     * complimentary = no revenue (logged).
     */
    public static function settle(SpaAppointment $appt, string $mode, ?int $paymentModeId = null, ?string $reference = null, ?array $cheque = null): SpaAppointment
    {
        if (! $appt->isOpen()) {
            throw new RuntimeException('This appointment is already '.str_replace('_', ' ', $appt->status).'.');
        }
        if ($mode === 'payment' && GiftVoucherService::isVoucherMode($paymentModeId)) {
            throw new RuntimeException('To pay with a gift voucher, post the treatment to the room and redeem the voucher on the folio.'); // v3.8
        }
        $net = $appt->net();
        $desc = ($appt->service->category === 'spa' ? 'Spa' : ucfirst($appt->service->category))." – {$appt->service->name}".($appt->pax > 1 ? " × {$appt->pax}" : '')." ({$appt->appt_no})";

        return DB::transaction(function () use ($appt, $mode, $paymentModeId, $reference, $cheque, $net, $desc) {
            $folio = null;
            if ($mode === 'room_post') {
                $stay = $appt->stay;
                if (! $stay || $stay->status !== 'checked_in') {
                    throw new RuntimeException('Post to room needs a checked-in guest. Settle by payment instead.');
                }
                $folio = FolioService::openForStay($stay);
                if ($net > 0) {
                    FolioService::charge($folio, 'spa', $desc, $net, true, ['source_type' => 'spa', 'source_id' => $appt->id]);
                }
            } elseif ($mode === 'payment') {
                if (! $paymentModeId) {
                    throw new RuntimeException('Choose a payment mode.');
                }
                $guestId = $appt->guest_id ?? Guest::create(['first_name' => mb_substr($appt->customer_name, 0, 80), 'phone' => $appt->customer_phone])->id;
                $folio = FolioService::openStandalone(['guest_id' => $guestId, 'type' => 'walk_in', 'reservation_room_id' => null]);
                if ($net > 0) {
                    FolioService::charge($folio, 'spa', $desc, $net, true, ['source_type' => 'spa', 'source_id' => $appt->id]);
                    FolioService::payment($folio, $folio->fresh()->balance(), $paymentModeId, $reference, 'settlement', 'payment', $cheque);
                }
                FolioService::close($folio->fresh());
            } elseif ($mode !== 'complimentary') {
                throw new RuntimeException('Unknown settlement mode.');
            }
            $appt->update(['status' => 'completed', 'settlement' => $mode, 'payment_mode_id' => $mode === 'payment' ? $paymentModeId : null,
                'folio_id' => $folio?->id, 'settled_at' => now()]);
            ActivityLogger::log('spa', 'settle', "Appointment {$appt->appt_no} settled ({$mode}) ".money($mode === 'complimentary' ? 0 : $net), $appt);

            return $appt;
        });
    }
}
