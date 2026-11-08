<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Internal final-bill primitive. Not connected to checkout or any HTTP route.
 * Callers must settle and invoice the resulting target separately. Activating
 * this requires the staged departure flow and integrations to be updated first.
 */
class ReservationBillConsolidator
{
    public static function consolidate(Reservation $reservation, Folio $destination, ?int $actorId): Folio
    {
        return DB::transaction(function () use ($reservation, $destination, $actorId) {
            $reservation = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $stays = ReservationRoom::where('reservation_id', $reservation->id)->orderBy('id')->lockForUpdate()->get();
            if ($stays->contains(fn ($stay) => (int) $stay->hotel_id !== (int) $reservation->hotel_id)) {
                throw new RuntimeException('A stay belongs to a different hotel.');
            }
            if ($stays->count() < 2 || ! $stays->contains(fn ($stay) => $stay->status === 'checked_out')
                || $stays->contains(fn ($stay) => ! in_array($stay->status, ['checked_out', 'cancelled', 'no_show'], true))) {
                throw new RuntimeException('All rooms must be departed, cancelled or no-show before preparing a combined bill.');
            }
            // Every participating folio is locked in ascending order, matching
            // folio move and online payment target selection. A current read is
            // necessary under MySQL repeatable read.
            $folios = Folio::where('reservation_id', $reservation->id)->orderBy('id')->lockForUpdate()->get();
            if ($folios->contains(fn ($folio) => (int) $folio->hotel_id !== (int) $reservation->hotel_id)) {
                throw new RuntimeException('A folio belongs to a different hotel.');
            }
            $target = $folios->first(fn ($folio) => (int) $folio->id === (int) $destination->id);
            if (! $target || $target->reservation_room_id !== null || $target->status !== 'open'
                || $target->invoice_no !== null || ! in_array($target->type, ['guest', 'master'], true)
                || $target->consolidated_into_folio_id !== null) {
                throw new RuntimeException('Select an open, uninvoiced reservation folio for the combined bill.');
            }
            $roomIds = $stays->pluck('id')->map(fn ($id) => (int) $id)->all();
            $sources = $folios->reject(fn ($folio) => (int) $folio->id === (int) $target->id);
            foreach ($sources as $source) {
                if ($source->status !== 'open' || $source->invoice_no !== null || $source->consolidated_into_folio_id !== null
                    || ! in_array($source->type, ['guest', 'master'], true)) {
                    throw new RuntimeException('An existing closed or invoiced folio cannot be consolidated. Keep this reservation on its existing billing workflow.');
                }
                if ($source->reservation_room_id !== null && ! in_array((int) $source->reservation_room_id, $roomIds, true)) {
                    throw new RuntimeException('A folio is linked to a stay outside this reservation.');
                }
            }
            // Validate every source before changing anything. Unsupported opening
            // receivables and complimentary records retain their existing rules.
            $linesByFolio = [];
            foreach ($folios as $source) {
                // Detect corrupt cross-hotel rows rather than silently leaving
                // them behind when the source folio is retired. No details of
                // those rows are exposed by the refusal.
                $lines = FolioTransaction::withoutGlobalScope('hotel')->where('folio_id', $source->id)->orderBy('id')->lockForUpdate()->get();
                if ($lines->contains(fn ($line) => (int) $line->hotel_id !== (int) $reservation->hotel_id)) {
                    throw new RuntimeException('A folio contains transactions from a different hotel.');
                }
                if ($lines->contains(fn ($line) => $line->isOpening() || $line->source_type === 'pos_complimentary')) {
                    throw new RuntimeException('Opening receivables or complimentary records cannot be consolidated automatically.');
                }
                $linesByFolio[$source->id] = $lines;
            }
            $when = now();
            foreach ($sources as $source) {
                $lines = $linesByFolio[$source->id];
                foreach ($lines as $line) {
                    DB::table('reservation_bill_transfers')->insert([
                        'hotel_id' => $source->hotel_id,
                        'reservation_id' => $reservation->id,
                        'folio_transaction_id' => $line->id,
                        'source_folio_id' => $source->id,
                        'target_folio_id' => $target->id,
                        'actor_user_id' => $actorId,
                        'created_at' => $when,
                    ]);
                }
                if ($lines->isNotEmpty()) {
                    $source->transactions()->whereIn('id', $lines->modelKeys())->update(['folio_id' => $target->id]);
                }
                // The source remains as a permanent, non-invoiced historical
                // folio. No GST document or loyalty award is issued here.
                $source->forceFill(['status' => 'closed', 'closed_at' => $when, 'closed_by' => $actorId,
                    'consolidated_into_folio_id' => $target->id])->save();
            }

            return $target;
        });
    }
}
