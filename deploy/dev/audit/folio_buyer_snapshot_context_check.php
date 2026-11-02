<?php

// Verify E-VAT buyer snapshots can identify a guest/company linked only through a stay.
// Run with: deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/folio_buyer_snapshot_context_check.php';"

use App\Models\Folio;
use App\Models\Hotel;
use App\Models\ReservationRoom;
use App\Services\GstService;
use App\Support\Tenant;

Tenant::set(Hotel::findOrFail(1));
$stay = ReservationRoom::with(['guest', 'reservation.guest', 'reservation.company'])->get()
    ->first(fn ($candidate) => $candidate->guest || $candidate->reservation?->guest || $candidate->reservation?->company);
if (! $stay) {
    throw new RuntimeException('No stay with a linked guest or company is available for the buyer-snapshot check.');
}
$guest = $stay->guest ?? $stay->reservation?->guest;
$company = $stay->reservation?->company ?? $guest?->company;
$possibleNames = collect([$company?->name, $guest?->company?->name, $guest?->full_name])->filter()->unique()->values();
$folio = new Folio(['reservation_room_id' => $stay->id]);
$folio->setRelation('stay', $stay);
$folio->setRelation('guest', null);
$folio->setRelation('company', null);
$folio->setRelation('reservation', null);
$snapshot = GstService::snapshot($folio);

if (! $snapshot['buyer_name'] || ! $possibleNames->contains($snapshot['buyer_name'])) {
    throw new RuntimeException('The buyer snapshot did not resolve to a name on the linked stay or reservation.');
}

echo 'Resolved E-VAT buyer snapshot through linked stay '.$stay->id.': '.$snapshot['buyer_name'].'.'.PHP_EOL;
