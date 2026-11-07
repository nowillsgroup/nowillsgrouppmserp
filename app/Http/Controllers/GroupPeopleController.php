<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use App\Services\GroupPeopleService;
use App\Services\PropertyAccessService;
use Illuminate\Http\Request;

/**
 * v10.1 the tenant's people: who works in the group, at which home property, under which role,
 * and in how many other properties they have been granted.
 *
 * Read by anybody with properties.view inside the group; every write goes through
 * GroupPeopleService, which is where the privilege rules live.
 */
class GroupPeopleController extends Controller
{
    public function __construct(protected GroupPeopleService $people, protected PropertyAccessService $access) {}

    public function index(Request $request)
    {
        $group = $this->group($request);
        if (! $group) {
            return redirect()->route('properties.index')->with('warning', 'The group account is administered by a group administrator. You can still open and configure your own property.');
        }

        $search = trim((string) $request->query('q'));
        $people = $this->people->people($group);
        if ($search !== '') {
            $needle = strtolower($search);
            $people = $people->filter(fn (User $u) => str_contains(strtolower($u->name.' '.$u->username.' '.($u->email ?? '')), $needle));
        }

        $hotels = $group->hotels()->orderBy('sort_order')->orderBy('name')->get();
        $homeOptions = $hotels->reject(fn (Hotel $h) => $h->isArchived())->mapWithKeys(fn (Hotel $h) => [$h->id => $h->name.' ('.$h->code.')'])->all();

        return view('group.people', [
            'group' => $group,
            'people' => $people->values(),
            'hotels' => $hotels,
            'homeOptions' => $homeOptions,
            'grantsByUser' => $this->grantsByUser($group, $people->pluck('id')->all()),
            'roles' => $hotels->isEmpty() ? collect() : $this->people->rolesFor($hotels->firstWhere('is_head_office', true) ?? $hotels->first()),
            'rolesByHotel' => $hotels->mapWithKeys(fn (Hotel $h) => [$h->id => $this->people->rolesFor($h)->pluck('name', 'id')->all()])->all(),
            'search' => $search,
            'canStaff' => $this->access->isPlatformAdmin($request->user()) || $this->access->isGroupAdminOf($request->user(), $group),
            'canMintAdmins' => $this->access->isPlatformAdmin($request->user()),
            'groupAdmins' => $group->admins()->where('is_active', true)->count(),
            'activePeople' => $group->userCount(),
        ]);
    }

    public function store(Request $request)
    {
        $group = $this->group($request);
        abort_unless($group, 403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|alpha_dash|max:60|unique:users,username',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'password' => 'required|string|min:8',
            'hotel_id' => 'required|integer|exists:hotels,id',
            'role_id' => 'nullable|integer|exists:roles,id',
            'grants' => 'nullable|array',
            'grants.*' => 'integer|exists:hotels,id',
            'is_group_admin' => 'nullable|boolean',
        ]);
        $data['is_group_admin'] = $request->boolean('is_group_admin');

        $person = $this->people->create($group, $data, $request->user());

        return back()->with('success', "{$person->name} added at ".($person->hotel?->name ?? 'their home property').'.'
            .(count($data['grants'] ?? []) ? ' They can also work in '.count($data['grants']).' other property(ies).' : ''));
    }

    public function update(Request $request, User $user)
    {
        $group = $this->group($request);
        abort_unless($group && (int) ($user->hotel_group_id ?? $user->hotel?->hotel_group_id) === (int) $group->id, 403);

        $data = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'role_id' => 'nullable|integer|exists:roles,id',
            'password' => 'nullable|string|min:8',
            'is_group_admin' => 'nullable|boolean',
        ]);
        if ($request->has('is_group_admin')) {
            $data['is_group_admin'] = $request->boolean('is_group_admin');
        }

        $this->people->update($user, $data, $request->user());

        return back()->with('success', "{$user->name} updated.");
    }

    public function toggle(Request $request, User $user)
    {
        $group = $this->group($request);
        abort_unless($group && (int) ($user->hotel_group_id ?? $user->hotel?->hotel_group_id) === (int) $group->id, 403);

        $active = ! $user->is_active;
        $result = $this->people->setActive($group, $user, $active, $request->user(), $request->boolean('revoke_grants'));

        if ($active) {
            return back()->with('success', "{$user->name} can sign in again.");
        }

        return back()->with('success', "{$user->name} is switched off. Their history stays; they cannot enter any property."
            .($result['revoked'] ? ' '.$result['revoked'].' cross-property grant(s) were taken back.' : ' Their property grants were kept — re-enabling them would restore those properties.'));
    }

    /** Home group of the person the acting user may staff. */
    protected function group(Request $request): ?HotelGroup
    {
        $user = $request->user();
        if ($user->is_group_admin && $user->hotel_group_id) {
            return HotelGroup::find($user->hotel_group_id);
        }
        if ($userId = (int) $request->query('group')) {
            return $user->governsGroup($userId) ? HotelGroup::find($userId) : null;
        }
        $current = hotel();
        if ($current?->hotel_group_id && $user->governsGroup($current->hotel_group_id)) {
            return $current->group;
        }
        if ($this->access->isPlatformAdmin($user)) {
            return HotelGroup::orderBy('name')->first();
        }

        return null;
    }

    /** How many extra properties each person has been granted, for the list. */
    protected function grantsByUser(HotelGroup $group, array $userIds): array
    {
        if (! $userIds) {
            return [];
        }

        $hotelIds = $group->hotels()->pluck('id');

        return \Illuminate\Support\Facades\DB::table('property_user')
            ->join('hotels', 'hotels.id', '=', 'property_user.hotel_id')
            ->whereIn('property_user.user_id', $userIds)
            ->whereIn('property_user.hotel_id', $hotelIds)
            ->get(['property_user.user_id', 'hotels.name'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('name')->all())
            ->all();
    }
}
