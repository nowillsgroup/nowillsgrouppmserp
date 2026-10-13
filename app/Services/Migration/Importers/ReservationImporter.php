<?php

namespace App\Services\Migration\Importers;

use App\Models\Company;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\ActivityLogger;
use App\Services\Migration\BaseImporter;
use App\Services\Migration\ImportContext;
use App\Services\ReservationAmendmentService;
use App\Support\Numbering;

/**
 * Reservations / stays (v7.35 cutover). One row = one reservation with one
 * room line. Import guests (and companies) first so rows can reference them by
 * legacy external id.
 *
 * The importer intentionally bypasses live inventory checks and sends no
 * confirmations — cutover data must land exactly as exported. Resolve any
 * double-sell in the tape chart after import.
 */
class ReservationImporter extends BaseImporter
{
    public function label(): string
    {
        return 'Reservations / stays';
    }

    public function columns(): array
    {
        return [
            'external_id' => ['required' => true, 'desc' => 'Legacy reservation id', 'example' => 'R-9001', 'aliases' => ['id', 'reservation_id']],
            'guest_external_id' => ['required' => true, 'desc' => 'Guest external_id (guests file first)', 'example' => 'G-2001', 'aliases' => ['guest_id', 'guest']],
            'arrival_date' => ['required' => true, 'desc' => 'Arrival (YYYY-MM-DD or DD/MM/YYYY)', 'example' => '2026-12-24', 'aliases' => ['arrival', 'check_in']],
            'departure_date' => ['required' => true, 'desc' => 'Departure (YYYY-MM-DD or DD/MM/YYYY)', 'example' => '2026-12-27', 'aliases' => ['departure', 'check_out']],
            'room_type_code' => ['required' => true, 'desc' => 'Room-type code (must exist)', 'example' => 'STD', 'aliases' => ['room_type', 'type']],
            'room_no' => ['desc' => 'Room number when already assigned (rooms file first)', 'example' => '101', 'aliases' => ['room']],
            'rate' => ['required' => true, 'desc' => 'Nightly rate (numbers only)', 'example' => '2800'],
            'status' => ['desc' => 'tentative, confirmed, checked_in, checked_out, cancelled, no_show (default confirmed)', 'example' => 'confirmed'],
            'adults' => ['desc' => 'Adults (default 1)', 'example' => '2'],
            'children' => ['desc' => 'Children (default 0)', 'example' => '0'],
            'reservation_no' => ['desc' => 'Keep the legacy booking number (generated when blank)', 'example' => 'RES-00421', 'aliases' => ['booking_no', 'confirmation_no']],
            'company_external_id' => ['desc' => 'Company external_id (companies file first)', 'example' => 'C-1001', 'aliases' => ['company_id', 'company']],
            'billing_instruction' => ['desc' => 'guest or company (default guest)', 'example' => 'company', 'aliases' => ['billing']],
            'group_name' => ['desc' => 'Group / block name for group bookings', 'example' => 'Sahara Offsite'],
            'booking_type' => ['desc' => 'individual or group (default individual)', 'example' => 'individual'],
            'ota_reference' => ['desc' => 'Channel / OTA confirmation reference', 'example' => 'BK-99887766'],
            'purpose' => ['desc' => 'Stay purpose', 'example' => 'Business'],
            'special_request' => ['desc' => 'Free-text requests', 'example' => 'Late arrival', 'aliases' => ['requests', 'notes']],
            'eta' => ['desc' => 'Estimated arrival time (HH:MM)', 'example' => '22:30'],
            'checked_in_at' => ['desc' => 'Actual check-in (for checked_in/checked_out rows)', 'example' => '2026-12-24 15:10'],
            'checked_out_at' => ['desc' => 'Actual check-out (for checked_out rows)', 'example' => '2026-12-27 11:02'],
        ];
    }

    public function validateRow(array $row, ImportContext $ctx, int $rowNo): ?string
    {
        if (! $this->externalId($row['external_id'] ?? null)) {
            return 'external_id is required.';
        }
        if (! $this->externalId($row['guest_external_id'] ?? null)) {
            return 'guest_external_id is required.';
        }
        if (! $ctx->mappedId('guests', $row['guest_external_id'])) {
            return "guest_external_id \"{$row['guest_external_id']}\" is not in the id map – import the guests file first.";
        }
        if (! empty($row['company_external_id']) && ! $ctx->mappedId('companies', $row['company_external_id'])) {
            return "company_external_id \"{$row['company_external_id']}\" is not in the id map – import the companies file first.";
        }
        $arrival = $this->date($row['arrival_date'] ?? null);
        $departure = $this->date($row['departure_date'] ?? null);
        if (! $arrival) {
            return 'arrival_date "'.($row['arrival_date'] ?? '').'" is not a recognised date.';
        }
        if (! $departure) {
            return 'departure_date "'.($row['departure_date'] ?? '').'" is not a recognised date.';
        }
        if ($departure <= $arrival) {
            return "departure_date {$departure} must be after arrival_date {$arrival}.";
        }
        if (! $this->str($row['room_type_code'] ?? null, 20)) {
            return 'room_type_code is required.';
        }
        $status = strtolower($this->str($row['status'] ?? null, 20) ?? 'confirmed');
        if (! in_array($status, ['tentative', 'confirmed', 'checked_in', 'checked_out', 'cancelled', 'no_show'], true)) {
            return "status \"{$status}\" is not a known reservation status.";
        }
        if (in_array($status, ['checked_in', 'checked_out'], true) && ! $this->date($row['checked_in_at'] ?? null)) {
            return 'checked_in_at is required for checked_in / checked_out rows.';
        }
        if ($status === 'checked_out' && ! $this->date($row['checked_out_at'] ?? null)) {
            return 'checked_out_at is required for checked_out rows.';
        }
        if ($row['rate'] !== null && $row['rate'] !== '' && ! is_numeric(str_replace([',', ' '], '', (string) $row['rate']))) {
            return "rate \"{$row['rate']}\" is not a number.";
        }

        return null;
    }

    public function importRow(array $row, ImportContext $ctx, bool $dryRun): array
    {
        $ext = $this->externalId($row['external_id']);
        $guestId = $ctx->mappedId('guests', $this->externalId($row['guest_external_id']));
        $companyId = $ctx->mappedId('companies', $this->externalId($row['company_external_id'] ?? null));
        $status = strtolower($this->str($row['status'] ?? null, 20) ?? 'confirmed');
        $arrival = $this->date($row['arrival_date']);
        $departure = $this->date($row['departure_date']);
        $typeCode = strtoupper($this->str($row['room_type_code'], 20));
        $roomNo = $this->str($row['room_no'] ?? null, 20);

        $existingId = $ctx->mappedId('reservations', $ext);
        if ($existingId) {
            $existing = Reservation::find($existingId);
            if ($existing) {
                return ['status' => 'skipped', 'message' => 'Already imported as '.$existing->reservation_no.' – legacy stays are not re-imported.', 'model_type' => Reservation::class, 'model_id' => $existing->id];
            }
        }

        if ($dryRun) {
            $typeOk = RoomType::where('code', $typeCode)->exists();
            $roomOk = $roomNo ? Room::where('room_no', $roomNo)->exists() : true;
            $msg = null;
            if (! $typeOk) {
                $msg = "Room type {$typeCode} does not exist – create it in Masters first.";
            } elseif (! $roomOk) {
                $msg = "Room {$roomNo} does not exist – import the rooms file first.";
            }
            if ($msg) {
                return ['status' => 'failed', 'message' => $msg, 'model_type' => null, 'model_id' => null];
            }

            return ['status' => 'created', 'message' => null, 'model_type' => Reservation::class, 'model_id' => null];
        }

        $type = RoomType::where('code', $typeCode)->firstOrFail();
        $room = $roomNo ? Room::where('room_no', $roomNo)->first() : null;
        if ($roomNo && ! $room) {
            return ['status' => 'failed', 'message' => "Room {$roomNo} does not exist – import the rooms file first.", 'model_type' => null, 'model_id' => null];
        }

        $reservationNo = $this->str($row['reservation_no'] ?? null, 30);
        if ($reservationNo && Reservation::withoutGlobalScopes()->where('hotel_id', $ctx->hotel->id)->where('reservation_no', $reservationNo)->exists()) {
            return ['status' => 'failed', 'message' => "Reservation number {$reservationNo} already exists.", 'model_type' => null, 'model_id' => null];
        }

        $res = Reservation::create([
            'reservation_no' => $reservationNo ?: Numbering::next('reservation'),
            'guest_id' => $guestId,
            'company_id' => $companyId,
            'booking_type' => strtolower($this->str($row['booking_type'] ?? null, 20) ?? 'individual') === 'group' ? 'group' : 'individual',
            'group_name' => $this->str($row['group_name'] ?? null, 255),
            'arrival_date' => $arrival,
            'departure_date' => $departure,
            'eta' => $this->str($row['eta'] ?? null, 8),
            'adults' => max(1, (int) $this->num($row['adults'] ?? null, 1)),
            'children' => max(0, (int) $this->num($row['children'] ?? null)),
            'status' => $status,
            'ota_reference' => $this->str($row['ota_reference'] ?? null, 60),
            'purpose' => $this->str($row['purpose'] ?? null, 60),
            'special_request' => $this->str($row['special_request'] ?? null, 65000),
            'billing_instruction' => strtolower($this->str($row['billing_instruction'] ?? null, 20) ?? 'guest') === 'company' ? 'company' : 'guest',
            'created_by' => auth()->id(),
        ]);

        $lineStatus = match ($status) {
            'checked_in' => 'checked_in',
            'checked_out' => 'checked_out',
            'cancelled' => 'cancelled',
            'no_show' => 'no_show',
            default => 'reserved',
        };
        $res->rooms()->create([
            'room_type_id' => $type->id,
            'room_id' => $room?->id,
            'guest_id' => $guestId,
            'arrival_date' => $arrival,
            'departure_date' => $departure,
            'rate' => $this->num($row['rate']),
            'adults' => max(1, (int) $this->num($row['adults'] ?? null, 1)),
            'children' => max(0, (int) $this->num($row['children'] ?? null)),
            'status' => $lineStatus,
            'checked_in_at' => $this->date($row['checked_in_at'] ?? null),
            'checked_out_at' => $this->date($row['checked_out_at'] ?? null),
        ]);

        $ctx->map('reservations', $ext, Reservation::class, $res->id);
        ReservationAmendmentService::record($res, 'created', null, ReservationAmendmentService::snapshot($res), 'Imported from legacy system', 'migration');
        ActivityLogger::log('migration', 'import', "Imported reservation {$res->reservation_no} ({$res->guest->full_name}) from legacy id {$ext}", $res);

        return ['status' => 'created', 'message' => null, 'model_type' => Reservation::class, 'model_id' => $res->id];
    }

    public function reconcile(ImportContext $ctx): array
    {
        $mapped = \App\Models\DataMigrationIdMap::withoutGlobalScopes()
            ->where('hotel_id', $ctx->hotel->id)->where('dataset', 'reservations');

        return [
            'Reservations in this property now' => Reservation::count(),
            'External ids mapped (all batches)' => (clone $mapped)->count(),
            'Upcoming (arriving later)' => Reservation::whereDate('arrival_date', '>', now()->toDateString())->whereIn('status', ['tentative', 'confirmed'])->count(),
            'In-house' => Reservation::where('status', 'checked_in')->count(),
            'Checked out' => Reservation::where('status', 'checked_out')->count(),
            'Cancelled / no-show' => Reservation::whereIn('status', ['cancelled', 'no_show'])->count(),
        ];
    }
}
