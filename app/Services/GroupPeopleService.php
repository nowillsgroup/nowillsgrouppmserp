<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\PropertyEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * v10.1 staffing a tenant.
 *
 * v10.0 could hand an *existing* person a second property, but a new person still had to be created
 * inside a property by that property's user screen — so a group administrator could not staff a
 * property they were not standing in, and could not hire somebody into two properties at once. This
 * service creates a person once, at group level, with a home property, a role that governs that home
 * property, and any number of additional grants.
 *
 * The privilege rules are the point:
 *   - a group administrator may staff their own group and cannot create a peer or a platform
 *     administrator (only a platform administrator mints group administrators);
 *   - a person's home property must belong to the group and must not be retired;
 *   - the role is taken from the home property's own catalogue, because roles are per property here;
 *   - nobody may deactivate themselves, and a group always keeps one active group administrator.
 */
class GroupPeopleService
{
    public function __construct(protected PropertyAccessService $access, protected PropertyService $properties) {}

    /** Everybody who belongs to this tenant: staff of its properties, its administrators and its grants. */
    public function people(HotelGroup $group): Collection
    {
        $hotelIds = $group->hotels()->pluck('id');

        return User::where(function ($q) use ($hotelIds, $group) { // audit-ok: group people list — this group's property ids and gid only
            $q->whereIn('hotel_id', $hotelIds)->orWhere('hotel_group_id', $group->id);
        })->with('role')->orderBy('name')->get();
    }

    public function create(HotelGroup $group, array $data, User $actor): User
    {
        $this->authoriseStaffing($group, $actor);

        if ($group->isSuspended()) {
            throw ValidationException::withMessages(['group' => 'This group is suspended. Reactivate it before staffing it.']);
        }

        $home = Hotel::find($data['hotel_id'] ?? null);
        if (! $home || (int) $home->hotel_group_id !== (int) $group->id) {
            throw ValidationException::withMessages(['hotel_id' => 'Choose a home property inside this group.']);
        }
        if ($home->isArchived()) {
            throw ValidationException::withMessages(['hotel_id' => 'A retired property cannot be anybody\'s home.']);
        }
        if (! empty($data['is_group_admin']) && ! $this->access->isPlatformAdmin($actor)) {
            throw ValidationException::withMessages(['is_group_admin' => 'Only a platform administrator can make somebody a group administrator.']);
        }

        $roleId = $data['role_id'] ?? null;
        if ($roleId) {
            // Roles are per property in this application, so a role only means something at a home property.
            $role = Role::withoutGlobalScopes()->whereKey($roleId)->where('hotel_id', $home->id)->first();
            if (! $role) {
                throw ValidationException::withMessages(['role_id' => 'That role does not belong to the home property. Pick one of its own roles.']);
            }
        }

        if (! $group->canAddUser()) {
            throw ValidationException::withMessages(['name' => 'This group has used all '.$group->max_users.' of its seats ('.$group->userCount().' active people). Raise the seat entitlement, or switch somebody off, before adding another person.']);
        }

        $extra = $this->validateGrants($group, $home, $data['grants'] ?? []);

        return DB::transaction(function () use ($group, $home, $data, $actor, $roleId, $extra) {
            $user = new User([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => Hash::make($data['password']),
                'role_id' => $roleId,
                'is_active' => true,
            ]);
            $user->hotel_id = $home->id;
            $user->hotel_group_id = $group->id;
            $user->is_group_admin = (bool) ($data['is_group_admin'] ?? false);
            $user->save();

            foreach ($extra as $hotel) {
                $this->properties->grantAccess($hotel, $user, $actor);
            }

            PropertyEvent::record('person_added', "Added {$user->name} ({$user->username}) to {$group->name} at {$home->name}"
                .($extra ? ' with access to '.count($extra).' more property(ies)' : ''), $home, [
                    'user' => $user->username, 'home' => $home->code, 'grants' => array_map(fn (Hotel $h) => $h->code, $extra), 'actor' => $actor->username,
                ]);

            return $user;
        });
    }

    /** Change the home property, role or group-administration flag of somebody already in the tenant. */
    public function update(User $target, array $data, User $actor): User
    {
        $group = HotelGroup::find($target->hotel_group_id ?? $target->hotel?->hotel_group_id);
        if (! $group) {
            throw ValidationException::withMessages(['user' => 'That person does not belong to a group.']);
        }
        $this->authoriseStaffing($group, $actor);

        if (! empty($data['is_group_admin']) && ! $target->is_group_admin && ! $this->access->isPlatformAdmin($actor)) {
            throw ValidationException::withMessages(['is_group_admin' => 'Only a platform administrator can make somebody a group administrator.']);
        }
        if ($target->is_group_admin && empty($data['is_group_admin']) && (int) $target->id === (int) $actor->id) {
            throw ValidationException::withMessages(['is_group_admin' => 'You cannot remove your own group administration.']);
        }
        if ($target->is_group_admin && empty($data['is_group_admin']) && $this->activeGroupAdmins($group) <= 1) {
            throw ValidationException::withMessages(['is_group_admin' => 'This is the last active group administrator. Make somebody else one first.']);
        }

        $before = ['role_id' => $target->role_id, 'is_active' => $target->is_active, 'is_group_admin' => $target->is_group_admin];

        if (array_key_exists('name', $data) && $data['name']) {
            $target->name = $data['name'];
        }
        if (array_key_exists('email', $data)) {
            $target->email = $data['email'];
        }
        if (array_key_exists('role_id', $data) && $data['role_id']) {
            $role = Role::withoutGlobalScopes()->whereKey($data['role_id'])->where('hotel_id', $target->hotel_id)->first();
            if (! $role) {
                throw ValidationException::withMessages(['role_id' => 'That role does not belong to this person\'s home property.']);
            }
            $target->role_id = $role->id;
        }
        if (array_key_exists('is_group_admin', $data)) {
            $target->is_group_admin = (bool) $data['is_group_admin'];
        }
        if (! empty($data['password'])) {
            $target->password = Hash::make($data['password']);
        }
        $target->save();

        $changed = collect(['role_id', 'is_active', 'is_group_admin'])
            ->filter(fn ($k) => (string) $target->{$k} !== (string) $before[$k])->values()->all();
        if ($changed) {
            PropertyEvent::record('person_updated', "Updated {$target->username} in {$group->name}: ".implode(', ', $changed), $target->hotel, [
                'user' => $target->username, 'changed' => $changed, 'actor' => $actor->username,
            ]);
        }

        return $target;
    }

    /**
     * Switch somebody off — or back on. Access follows is_active everywhere: SetTenant, the switcher
     * and the register.
     *
     * v10.2 $revokeGrants closes the gap v10.1 left open: switching a person off stopped them signing
     * in but left every cross-property grant on the books, so re-enabling the account silently
     * restored access to properties nobody had reconsidered. With the flag, the grants are taken back
     * as well, each one recorded on the property that loses the reader.
     *
     * @return array{user: User, revoked: int}
     */
    public function setActive(HotelGroup $group, User $target, bool $active, User $actor, bool $revokeGrants = false): array
    {
        $this->authoriseStaffing($group, $actor);

        if ((int) $target->id === (int) $actor->id && ! $active) {
            throw ValidationException::withMessages(['user' => 'You cannot switch your own account off.']);
        }
        if (! $active && $target->is_group_admin && $this->activeGroupAdmins($group) <= 1) {
            throw ValidationException::withMessages(['user' => 'This is the last active group administrator. Make somebody else one first.']);
        }

        $revoked = 0;
        if (! $active && $revokeGrants) {
            $revoked = $this->properties->revokeAllGrants($target, $actor, 'account switched off');
        }

        $target->forceFill(['is_active' => $active])->save();
        PropertyEvent::record($active ? 'person_enabled' : 'person_disabled',
            ($active ? 'Re-enabled ' : 'Switched off ').$target->name.' ('.$target->username.') in '.$group->name
            .($revoked ? ' and revoked '.$revoked.' cross-property grant(s)' : ''),
            $target->hotel, ['user' => $target->username, 'actor' => $actor->username, 'grants_revoked' => $revoked]);

        return ['user' => $target, 'revoked' => $revoked];
    }

    public function activeGroupAdmins(HotelGroup $group): int
    {
        return User::where('hotel_group_id', $group->id)->where('is_group_admin', true)->where('is_active', true)->count(); // audit-ok: group admin headcount, this group's gid only
    }

    /** The roles a person could be given at their home property, for the form on the group's people screen. */
    public function rolesFor(Hotel $home): Collection
    {
        return Role::withoutGlobalScopes()->where('hotel_id', $home->id)->orderBy('name')->get(['id', 'name']);
    }

    protected function authoriseStaffing(HotelGroup $group, User $actor): void
    {
        $ok = $this->access->isPlatformAdmin($actor) || $this->access->isGroupAdminOf($actor, $group);
        if (! $ok || ! $actor->hasPermission('properties.manage')) {
            throw ValidationException::withMessages(['group' => 'You do not administer this group.']);
        }
    }

    /** Every extra property named must belong to the group, must not be retired, and must not be the home. */
    protected function validateGrants(HotelGroup $group, Hotel $home, array $ids): array
    {
        $ids = collect($ids)->filter()->map(fn ($id) => (int) $id)->reject(fn ($id) => $id === (int) $home->id)->unique();
        if ($ids->isEmpty()) {
            return [];
        }

        $hotels = Hotel::whereIn('id', $ids)->get()->filter(fn (Hotel $h) => (int) $h->hotel_group_id === (int) $group->id && ! $h->isArchived());
        if ($hotels->count() !== $ids->count()) {
            throw ValidationException::withMessages(['grants' => 'Every additional property must belong to this group and must not be retired.']);
        }

        return $hotels->values()->all();
    }
}
