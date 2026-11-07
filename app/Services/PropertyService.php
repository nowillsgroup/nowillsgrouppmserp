<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\PosOrder;
use App\Models\PropertyEvent;
use App\Models\ReservationRoom;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v10.0 property lifecycle: create, edit, activate, suspend, resume, archive, and the per-person
 * access grants that let staff work across properties.
 *
 * Every mutation goes through here so the rules hold whichever screen calls them:
 *   - a property is never deleted, only archived, and the audit trail survives it;
 *   - a group may not exceed its property entitlement;
 *   - an archived property must have nobody inside it and no money still open;
 *   - a group always keeps at least one live property;
 *   - the property you are standing in cannot be suspended underneath you.
 */
class PropertyService
{
    public function __construct(protected PropertyAccessService $access) {}

    /** Fields a property profile may carry, shared by create and update. */
    public const PROFILE_FIELDS = [
        'name', 'legal_name', 'property_type', 'brand', 'star_rating', 'address', 'city', 'state', 'country', 'pincode',
        'phone', 'email', 'website', 'tax_number', 'currency', 'currency_symbol', 'timezone', 'checkin_time', 'checkout_time',
        'invoice_footer', 'opened_on', 'sort_order', 'is_head_office',
    ];

    public function create(HotelGroup $group, array $data, User $actor): Hotel
    {
        if ($group->isSuspended()) {
            throw ValidationException::withMessages(['group' => 'This group is suspended. Reactivate the group before opening a property.']);
        }
        if (! $group->canAddProperty()) {
            throw ValidationException::withMessages(['group' => 'The group entitlement allows '.$group->max_properties.' properties and they are all used. Archive a property or raise the entitlement first.']);
        }

        // v10.2 the first administrator is a seat like any other, and it is checked before anything is
        // provisioned — a tenant at its seat cap raises the cap or frees a seat first.
        if (! empty($data['admin_username']) && ! $group->canAddUser()) {
            throw ValidationException::withMessages(['admin_username' => 'The group has used all '.$group->max_users.' of its seats. Raise the seat entitlement or switch a person off before opening a property with its own administrator.']);
        }

        $wantsLive = ($data['status'] ?? 'draft') === 'active';

        return DB::transaction(function () use ($group, $data, $actor, $wantsLive) {
            $hotel = new Hotel;
            $hotel->fill($this->profileFrom($group, $data));
            $hotel->hotel_group_id = $group->id;
            $hotel->code = strtoupper(trim($data['code']));
            $hotel->business_date = $data['business_date'] ?? now()->toDateString();
            $hotel->status = $wantsLive ? 'active' : 'draft';
            $hotel->is_active = $wantsLive;
            if ($wantsLive) {
                $hotel->opened_on = $data['opened_on'] ?? now()->toDateString();
            }
            $hotel->save();

            HotelProvisioner::provision($hotel);

            if (! empty($data['admin_username'])) {
                $admin = new User([
                    'name' => $data['admin_name'] ?? 'Administrator',
                    'username' => $data['admin_username'],
                    'email' => $data['admin_email'] ?? null,
                    'password' => $data['admin_password'],
                    'is_active' => true,
                ]);
                $admin->hotel_id = $hotel->id;
                $admin->hotel_group_id = $group->id;
                $admin->is_super_admin = true;
                $admin->save();
            }

            PropertyEvent::record('created', "Opened property {$hotel->name} ({$hotel->code}) as ".$hotel->statusLabel(), $hotel, ['status' => $hotel->status, 'actor' => $actor->username]);

            return $hotel;
        });
    }

    public function update(Hotel $hotel, array $data, User $actor): Hotel
    {
        $before = $hotel->only(['name', 'code', 'timezone', 'currency', 'property_type', 'status']);
        $hotel->fill($this->profileFrom($hotel->group, $data, false));
        if (! empty($data['code'])) {
            $hotel->code = strtoupper(trim($data['code']));
        }
        if ($hotel->isLive() && empty($hotel->business_date)) {
            throw ValidationException::withMessages(['business_date' => 'A live property needs a business date.']);
        }
        $hotel->save();

        $changed = collect($hotel->only(array_keys($before)))->filter(fn ($v, $k) => (string) $v !== (string) ($before[$k] ?? ''))->keys();
        PropertyEvent::record('updated', "Updated {$hotel->name}".($changed->count() ? ': '.$changed->implode(', ') : ''), $hotel, ['changed' => $changed->values()->all(), 'actor' => $actor->username]);

        return $hotel;
    }

    /** draft -> live. The property must be complete enough to trade. */
    public function activate(Hotel $hotel, User $actor, ?string $reason = null): Hotel
    {
        if ($hotel->isArchived()) {
            throw ValidationException::withMessages(['status' => 'An archived property cannot be brought back. Open a new property instead.']);
        }
        if ($hotel->isLive()) {
            return $hotel;
        }
        if ($hotel->group?->isSuspended()) {
            throw ValidationException::withMessages(['status' => 'The group is suspended, so no property in it can go live.']);
        }
        if (! $hotel->business_date) {
            throw ValidationException::withMessages(['business_date' => 'Set the business (audit) date before going live.']);
        }

        $hotel->forceFill([
            'status' => 'active', 'is_active' => true, 'suspended_at' => null, 'status_reason' => $reason,
            'opened_on' => $hotel->opened_on ?? now()->toDateString(),
        ])->save();

        PropertyEvent::record('activated', "{$hotel->name} went live", $hotel, ['actor' => $actor->username, 'reason' => $reason]);

        return $hotel;
    }

    public function suspend(Hotel $hotel, User $actor, string $reason): Hotel
    {
        if ($hotel->isArchived()) {
            throw ValidationException::withMessages(['status' => 'The property is archived; it cannot be suspended.']);
        }
        if ($hotel->isSuspended()) {
            return $hotel;
        }
        if ((int) Tenant::id() === (int) $hotel->id) {
            throw ValidationException::withMessages(['status' => 'You are working in this property. Switch to another property first, then suspend it.']);
        }
        if ($hotel->isLive() && $hotel->group && $hotel->group->liveHotels()->count() <= 1) {
            throw ValidationException::withMessages(['status' => 'This is the last live property in the group. Suspend the group instead, so people are not stranded at sign-in.']);
        }

        $hotel->forceFill(['status' => 'suspended', 'is_active' => false, 'suspended_at' => now(), 'status_reason' => $reason])->save();
        PropertyEvent::record('suspended', "Suspended {$hotel->name}: {$reason}", $hotel, ['actor' => $actor->username, 'reason' => $reason]);

        return $hotel;
    }

    public function resume(Hotel $hotel, User $actor, ?string $reason = null): Hotel
    {
        if ($hotel->isArchived()) {
            throw ValidationException::withMessages(['status' => 'The property is archived and cannot be resumed.']);
        }
        if ($hotel->group?->isSuspended()) {
            throw ValidationException::withMessages(['status' => 'Reactivate the group before resuming one of its properties.']);
        }

        $hotel->forceFill(['status' => 'active', 'is_active' => true, 'suspended_at' => null, 'status_reason' => $reason])->save();
        PropertyEvent::record('resumed', "Resumed {$hotel->name}", $hotel, ['actor' => $actor->username, 'reason' => $reason]);

        return $hotel;
    }

    /**
     * Archive = retire. Refused while anybody is in-house or money is still open, because archiving
     * hides the property from the switcher and nobody would be able to close those folios.
     */
    public function archive(Hotel $hotel, User $actor, string $reason): Hotel
    {
        if ($hotel->isArchived()) {
            return $hotel;
        }
        if ((int) Tenant::id() === (int) $hotel->id) {
            throw ValidationException::withMessages(['status' => 'You are working in this property. Switch away before retiring it.']);
        }
        if ($hotel->group && $hotel->group->liveHotels()->count() <= 1 && $hotel->isLive()) {
            throw ValidationException::withMessages(['status' => 'The group would be left with no live property.']);
        }

        $blockers = $this->archiveBlockers($hotel);
        if ($blockers) {
            throw ValidationException::withMessages(['status' => 'Cannot retire this property: '.implode('; ', $blockers).'.']);
        }

        $hotel->forceFill([
            'status' => 'archived', 'is_active' => false, 'archived_at' => now(),
            'closed_on' => now()->toDateString(), 'status_reason' => $reason,
        ])->save();

        PropertyEvent::record('archived', "Retired {$hotel->name}: {$reason}", $hotel, ['actor' => $actor->username, 'reason' => $reason]);

        return $hotel;
    }

    /** Why this property may not be retired yet, in words a manager can act on. */
    public function archiveBlockers(Hotel $hotel): array
    {
        $previous = Tenant::hotel();
        $out = [];
        try {
            Tenant::set($hotel);
            $inHouse = ReservationRoom::where('status', 'checked_in')->count();
            if ($inHouse) {
                $out[] = $inHouse.' room(s) still checked in';
            }
            $openPos = PosOrder::whereIn('status', ['open', 'billed'])->count();
            if ($openPos) {
                $out[] = $openPos.' POS order(s) still open';
            }
        } finally {
            Tenant::set($previous);
        }

        return $out;
    }

    /** Grant (or re-grant) one person access to one property, optionally with a role that applies here only. */
    public function grantAccess(Hotel $hotel, User $target, User $actor, ?int $roleId = null, bool $isDefault = false): void
    {
        if ($hotel->isArchived()) {
            throw ValidationException::withMessages(['user_id' => 'An archived property cannot be granted.']);
        }
        if ((int) $target->id === (int) $actor->id && (int) $actor->hotel_id === (int) $hotel->id) {
            throw ValidationException::withMessages(['user_id' => 'You already work in this property.']);
        }
        if ($target->hotel_group_id && (int) $target->hotel_group_id !== (int) $hotel->hotel_group_id) {
            throw ValidationException::withMessages(['user_id' => 'This person belongs to another group. Move them to this group before granting a property.']);
        }
        if ($roleId && ! Role::whereKey($roleId)->exists()) {
            throw ValidationException::withMessages(['role_id' => 'That role does not exist.']);
        }

        $hotel->propertyUsers()->syncWithoutDetaching([
            $target->id => ['role_id' => $roleId, 'is_default' => $isDefault, 'granted_by' => $actor->id],
        ]);
        if (! $target->hotel_group_id) {
            $target->forceFill(['hotel_group_id' => $hotel->hotel_group_id])->save();
        }

        PropertyEvent::record('access_granted', "Granted {$target->username} access to {$hotel->name}".($roleId ? ' as '.optional(Role::find($roleId))->name : ''), $hotel, [
            'user' => $target->username, 'role_id' => $roleId, 'actor' => $actor->username,
        ]);
    }

    public function revokeAccess(Hotel $hotel, User $target, User $actor): void
    {
        if ((int) $target->id === (int) $actor->id && (int) $target->hotel_id !== (int) $hotel->id) {
            throw ValidationException::withMessages(['user_id' => 'You cannot remove your own access to this property.']);
        }
        $hotel->propertyUsers()->detach($target->id);
        PropertyEvent::record('access_revoked', "Removed {$target->username}'s access to {$hotel->name}", $hotel, ['user' => $target->username, 'actor' => $actor->username]);
    }

    /**
     * v10.2 take back every cross-property grant one person holds, property by property.
     *
     * Switched off with this flag, a person cannot sign in *and* has nothing left to come back to if
     * they are re-enabled later. Each revocation is written to the property it was taken from, so the
     * property that loses a reader can say who took the right away and when.
     */
    public function revokeAllGrants(User $target, User $actor, string $why = ''): int
    {
        $granted = $target->propertyGrants()->get();
        $count = 0;
        foreach ($granted as $property) {
            $hotel = Hotel::find($property->id);
            if (! $hotel) {
                continue;
            }
            DB::table('property_user')->where('user_id', $target->id)->where('hotel_id', $hotel->id)->delete();
            PropertyEvent::record('access_revoked', "Removed {$target->username}'s access to {$hotel->name}".($why ? ": {$why}" : ''), $hotel, [
                'user' => $target->username, 'actor' => $actor->username, 'reason' => $why ?: null,
            ]);
            $count++;
        }

        return $count;
    }

    /** Mark that a user actually entered the property through this grant. */
    public function touchGrant(Hotel $hotel, ?User $user): void
    {
        if (! $user || ! $hotel) {
            return;
        }
        DB::table('property_user')->where('user_id', $user->id)->where('hotel_id', $hotel->id)->update(['last_used_at' => now()]);
    }

    /**
     * v10.1 hand a property over to another group — a management contract won, a lease reassigned, a
     * portfolio restructured. Platform administrators only, and deliberately explicit about what
     * follows the property and what does not:
     *   - the property's own staff follow it, and their group membership moves with them;
     *   - a group administrator whose home happens to be this property does NOT follow it — they are
     *     demoted, because otherwise a handover would silently hand one tenant's administrator the
     *     keys to another tenant;
     *   - every cross-property grant that was made to somebody outside the new group is revoked,
     *     because it was a grant of trust inside the old tenant.
     * The property's history, its books and its audit trail are untouched.
     *
     * @return array{hotel: Hotel, moved: int, revoked: int, demoted: int}
     */
    public function transfer(Hotel $hotel, HotelGroup $target, User $actor): array
    {
        if (! $this->access->isPlatformAdmin($actor)) {
            throw ValidationException::withMessages(['group' => 'Only a platform administrator can move a property between groups.']);
        }
        if ((int) $target->id === (int) $hotel->hotel_group_id) {
            throw ValidationException::withMessages(['group_id' => 'The property already belongs to that group.']);
        }
        if ($target->isSuspended()) {
            throw ValidationException::withMessages(['group_id' => 'The receiving group is suspended. Reactivate it first.']);
        }
        if ($hotel->isArchived()) {
            throw ValidationException::withMessages(['group_id' => 'A retired property cannot be handed over.']);
        }
        if (! $target->canAddProperty()) {
            throw ValidationException::withMessages(['group_id' => 'The receiving group has no property left under its entitlement.']);
        }

        $from = $hotel->group;

        return DB::transaction(function () use ($hotel, $target, $from, $actor) {
            $moved = 0;
            $demoted = 0;
            foreach (User::where('hotel_id', $hotel->id)->get() as $person) {
                $wasGroupAdmin = (bool) $person->is_group_admin; // read before the flag is cleared
                $person->forceFill([
                    'hotel_group_id' => $target->id,
                    // a group administrator is a right inside the *old* tenant; it does not travel
                    'is_group_admin' => false,
                ])->saveQuietly();
                $moved++;
                if ($wasGroupAdmin) {
                    $demoted++;
                }
            }

            $revoked = 0;
            foreach ($hotel->propertyUsers()->get() as $granted) {
                if ((int) $granted->hotel_group_id !== (int) $target->id) {
                    DB::table('property_user')->where('user_id', $granted->id)->where('hotel_id', $hotel->id)->delete();
                    $revoked++;
                }
            }

            // v10.6 the property's own licence was written against the OLD group's list. Carrying it
            // over unchanged would leave a list naming modules the receiving group never bought —
            // harmless at the gate (the intersection protects it) but a lie on the screen. Reconcile
            // it: keep what both allow, and if that is everything the new group has, follow the group.
            $before = $hotel->licensedModules();
            $after = $before;
            $dropped = [];
            if ($before !== null) {
                $ceiling = LicenceService::licenceOf($target);        // null = the new group bought everything
                $allowed = $ceiling === null ? $before : array_values(array_intersect($before, $ceiling));
                $dropped = array_values(array_diff($before, $allowed));
                $everything = $ceiling === null ? array_keys(LicenceService::catalogue()) : $ceiling;
                $after = count($allowed) === count($everything) ? null : $allowed;
            }

            $hotel->forceFill(['hotel_group_id' => $target->id, 'licensed_modules' => $after])->save();
            LicenceService::forget();

            PropertyEvent::record('transferred', "Handed {$hotel->name} ({$hotel->code}) from ".($from?->name ?? 'no group').' to '.$target->name
                .($dropped ? ' — '.count($dropped).' module(s) it was licensed for are not on the receiving group\'s licence' : ''), $hotel, [
                'from' => $from?->code, 'to' => $target->code, 'staff_moved' => $moved, 'grants_revoked' => $revoked, 'admins_demoted' => $demoted,
                'licence_before' => $before, 'licence_after' => $after, 'licence_dropped' => $dropped, 'actor' => $actor->username,
            ]);

            return ['hotel' => $hotel, 'moved' => $moved, 'revoked' => $revoked, 'demoted' => $demoted, 'licence_dropped' => $dropped];
        });
    }

    /**
     * Profile values for a property: everything explicitly given, everything else inherited from the
     * group's defaults. This is the inheritance rule the module promises — a new property is never
     * born with the *current* property's settings, only with its own group's.
     */
    protected function profileFrom(?HotelGroup $group, array $data, bool $isCreate = true): array
    {
        $out = [];
        foreach (self::PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $out[$field] = $data[$field] === '' ? null : $data[$field];
            }
        }

        if ($isCreate && $group) {
            $out += array_filter([
                'country' => $group->country,
                'currency' => $group->currency ?: 'GHS',
                'currency_symbol' => $this->symbolFor($group->currency ?: 'GHS'),
                'timezone' => $group->timezone ?: 'Africa/Accra',
                'property_type' => $group->default_property_type ?: 'hotel',
                'brand' => $group->brand,
            ], fn ($v) => $v !== null && $v !== '');
            // Explicit values always beat the group defaults, so re-apply them after the merge.
            foreach (self::PROFILE_FIELDS as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== '') {
                    $out[$field] = $data[$field];
                }
            }
        }

        return $out;
    }

    protected function symbolFor(string $currency): string
    {
        return match (strtoupper($currency)) {
            'GHS' => '₵', 'NGN' => '₦', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'INR' => '₹', 'KES' => 'KSh', 'ZAR' => 'R', 'XOF' => 'CFA',
            default => $currency,
        };
    }
}
