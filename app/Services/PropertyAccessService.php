<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * v10.0 the single answer to "may this person work in this property?".
 *
 * Before this class the rule was copied into SetTenant, LoginController::switchHotel and
 * User::accessibleHotels(), and each copy knew a slightly different subset of it. Everything now
 * asks here, so a property added, suspended, archived or granted to somebody behaves the same at
 * login, at switch, in the top-bar switcher and in the register.
 *
 * Access is granted by any one of:
 *   - super admin on the platform (no group)           -> every property
 *   - own property (users.hotel_id)                    -> that property
 *   - group admin of the group                         -> every property of the group
 *   - an explicit property_user grant                  -> that property, with an optional role override
 */
class PropertyAccessService
{
    /** Properties this user may open, newest decisions first, in a stable order. */
    public function accessible(User $user): Collection
    {
        if (! $user->is_active) {
            return collect();
        }

        if ($this->isPlatformAdmin($user)) {
            return Hotel::orderBy('sort_order')->orderBy('name')->get();
        }

        $ids = collect();

        if ($user->hotel_id) {
            $ids->push((int) $user->hotel_id);
        }

        if ($user->is_group_admin && $user->hotel_group_id) {
            $group = HotelGroup::find($user->hotel_group_id);
            if ($group && ! $group->isSuspended()) {
                $ids = $ids->merge($group->hotels()->pluck('id'));
            }
        }

        $ids = $ids->merge($user->grantedPropertyIds());

        return Hotel::whereIn('id', $ids->unique()->all())
            ->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * The properties a user actually works in: live, and inside a group that is not itself suspended.
     * The top-bar switcher and the sign-in fallback both read this, so a suspended tenant never
     * offers a property it would then refuse to open.
     */
    public function operable(User $user): Collection
    {
        return $this->accessible($user)
            ->filter(fn (Hotel $h) => $h->isOperable() && ! ($h->group?->isSuspended()))
            ->values();
    }

    public function isPlatformAdmin(User $user): bool
    {
        return (bool) $user->is_super_admin && ! $user->hotel_group_id;
    }

    public function isGroupAdminOf(User $user, HotelGroup $group): bool
    {
        return (bool) $user->is_group_admin && (int) $user->hotel_group_id === (int) $group->id;
    }

    /**
     * @param  bool  $forSetup  a draft property is not operable, but the group admin who is
     *                          configuring it must still be able to enter it to finish the setup.
     */
    public function canEnter(User $user, Hotel $hotel, bool $forSetup = false): bool
    {
        if (! $user->is_active || $hotel->isArchived()) {
            return false;
        }

        $group = $hotel->group;
        if ($group && $group->isSuspended()) {
            return false;
        }

        if ($hotel->isSuspended()) {
            // A suspended property can only be looked at by the people who administer it, and only
            // through the administration screens — never by ordinary staff or a granted reader.
            return $this->canAdminister($user, $hotel) && $forSetup === false;
        }

        if ($hotel->isDraft()) {
            return $forSetup && $this->canAdminister($user, $hotel);
        }

        return $this->hasGrant($user, $hotel);
    }

    /** May this user change the property's setup, lifecycle or access list? */
    public function canAdminister(User $user, Hotel $hotel): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        if ($hotel->hotel_group_id && $this->isGroupAdminOf($user, $hotel->group ?? HotelGroup::find($hotel->hotel_group_id))) {
            return true;
        }

        return (int) $hotel->id === (int) $user->hotel_id && (bool) $user->is_super_admin;
    }

    public function hasGrant(User $user, Hotel $hotel): bool
    {
        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        if ((int) $hotel->id === (int) $user->hotel_id) {
            return true;
        }

        if ($hotel->hotel_group_id && $this->isGroupAdminOf($user, $hotel->group ?? HotelGroup::find($hotel->hotel_group_id))) {
            return true;
        }

        return $user->grantedPropertyIds()->contains((int) $hotel->id);
    }

    /** The property a user lands in after signing in, if the session has none. */
    public function defaultFor(User $user): ?Hotel
    {
        if ($user->hotel_id) {
            $own = Hotel::find($user->hotel_id);
            if ($own && $this->canEnter($user, $own)) {
                return $own;
            }
        }

        $pivotDefault = $user->propertyGrants()->where('is_default', true)->first();
        if ($pivotDefault) {
            $hotel = Hotel::find($pivotDefault->hotel_id);
            if ($hotel && $this->canEnter($user, $hotel)) {
                return $hotel;
            }
        }

        return $this->operable($user)->first();
    }
}
