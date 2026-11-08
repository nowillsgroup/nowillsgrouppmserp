<?php

namespace App\Services;

use App\Models\Reservation;

class ReservationBillingService
{
    /**
     * Read-only totals of posted transactions on all reservation-linked folios,
     * including closed folios and credit notes. This is not a final checkout
     * quote: room nights and service items not yet posted are not included.
     */
    public static function postedSummary(Reservation $reservation): array
    {
        $reservation->loadMissing(['folios.transactions', 'folios.stay.room']);
        $summary = ['folios' => $reservation->folios->count(), 'charges' => 0.0, 'opening' => 0.0, 'discount' => 0.0, 'payments' => 0.0, 'refunds' => 0.0, 'balance' => 0.0, 'details' => [], 'room_invoices' => 0];
        foreach ($reservation->folios as $folio) {
            $totals = $folio->totals($folio->transactions);
            $isRoomInvoice = $folio->reservation_room_id !== null && $folio->type !== 'credit_note' && $folio->invoice_no !== null;
            $summary['room_invoices'] += $isRoomInvoice ? 1 : 0;
            $summary['details'][] = [
                'id' => $folio->id,
                'number' => $folio->folio_no,
                'status' => $folio->status,
                'type' => $folio->type,
                'stay_id' => $folio->reservation_room_id,
                'room_no' => $folio->stay?->room?->room_no,
                'invoice_no' => $folio->invoice_no,
                'balance' => $totals['balance'],
            ];
            foreach (['charges', 'opening', 'discount', 'payments', 'refunds', 'balance'] as $key) {
                $summary[$key] += $totals[$key];
            }
        }
        foreach (['charges', 'opening', 'discount', 'payments', 'refunds', 'balance'] as $key) {
            $summary[$key] = round($summary[$key], 2);
        }

        return $summary;
    }
}
