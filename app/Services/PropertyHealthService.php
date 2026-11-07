<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\PosOrder;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\User;
use App\Support\Tenant;

/**
 * v10.0 one row of property health for the register and the property page.
 *
 * Every count is read inside that property's own tenant scope and restored afterwards, so a group
 * administrator looking at the register never sees one property's rooms counted against another's.
 * The business-date lag is the operator's early warning: a property whose business date is behind
 * has not had its night audit closed.
 */
class PropertyHealthService
{
    public function row(Hotel $hotel): array
    {
        $previous = Tenant::hotel();
        $out = [
            'hotel' => $hotel,
            'rooms' => 0, 'occupied' => 0, 'occ' => 0.0, 'in_house' => 0,
            'open_pos' => 0, 'staff' => 0, 'business_date' => $hotel->business_date,
            'lag' => $hotel->businessDateLag(), 'needs_night_audit' => false,
        ];

        try {
            Tenant::set($hotel);
            $rooms = Room::where('is_active', true)->get(['id', 'status']);
            $out['rooms'] = $rooms->count();
            $out['occupied'] = $rooms->where('status', 'occupied')->count();
            $out['occ'] = $out['rooms'] ? round($out['occupied'] * 100 / $out['rooms'], 1) : 0.0;
            $out['in_house'] = (int) ReservationRoom::where('status', 'checked_in')->count();
            $out['open_pos'] = PosOrder::whereIn('status', ['open', 'billed'])->count();
            $out['staff'] = User::where('hotel_id', $hotel->id)->where('is_active', true)->count();
        } finally {
            Tenant::set($previous);
        }

        $out['needs_night_audit'] = $hotel->isLive() && $out['lag'] > 0;

        // v10.7 what this property is licensed for, read from the same row the register prints.
        // A property licensed for nothing is closed, and the register has to say so before an
        // operator wonders why it has no occupancy: the numbers are real, the door is shut.
        $catalogue = count(LicenceService::catalogue());
        $effective = LicenceService::licenceOfHotel($hotel);
        $groupLicence = LicenceService::licenceOf(LicenceService::groupOf($hotel));
        $out['licence'] = [
            'closed' => $hotel->licenceIsEmpty(),
            'narrowed' => ! $hotel->followsGroupLicence(),
            'keep' => $effective === null ? $catalogue : count($effective),
            'of' => $groupLicence === null ? $catalogue : count($groupLicence),
            'missing' => $effective === null ? 0 : max(0, ($groupLicence === null ? $catalogue : count($groupLicence)) - count($effective)),
        ];

        return $out;
    }

    /** @param  iterable<Hotel>  $hotels */
    public function rows(iterable $hotels): array
    {
        $rows = [];
        foreach ($hotels as $hotel) {
            $rows[] = $this->row($hotel);
        }

        return $rows;
    }

    public function summarise(array $rows): array
    {
        $byStatus = ['draft' => 0, 'active' => 0, 'suspended' => 0, 'archived' => 0];
        foreach ($rows as $r) {
            $byStatus[$r['hotel']->status ?? 'active'] = ($byStatus[$r['hotel']->status ?? 'active'] ?? 0) + 1;
        }

        $live = array_filter($rows, fn ($r) => $r['hotel']->isLive());
        $rooms = array_sum(array_column($rows, 'rooms'));
        $occupied = array_sum(array_column($rows, 'occupied'));

        return [
            'properties' => count($rows),
            'live' => $byStatus['active'] ?? 0,
            'draft' => $byStatus['draft'] ?? 0,
            'suspended' => $byStatus['suspended'] ?? 0,
            'archived' => $byStatus['archived'] ?? 0,
            'rooms' => $rooms,
            'occupied' => $occupied,
            'occ' => $rooms ? round($occupied * 100 / $rooms, 1) : 0.0,
            'in_house' => array_sum(array_column($rows, 'in_house')),
            'staff' => array_sum(array_column($rows, 'staff')),
            'behind' => count(array_filter($live, fn ($r) => $r['needs_night_audit'])),
            // v10.7 licence coverage across the portfolio, so it is a number rather than a hunt
            'closed' => count(array_filter($rows, fn ($r) => ! empty($r['licence']['closed']))),
            'narrowed' => count(array_filter($rows, fn ($r) => ! empty($r['licence']['narrowed']))),
        ];
    }
}
