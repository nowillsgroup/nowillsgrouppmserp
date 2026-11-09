<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\PropertyEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\LicenceService;
use App\Services\PropertyAccessService;
use App\Services\PropertyHealthService;
use App\Services\PropertyService;
use Illuminate\Http\Request;

/**
 * v10.0 Multi-tenant & multi-property administration.
 *
 * The register (index) and the property page (show) are read by anybody with properties.view inside
 * the properties they can reach; every write is reserved for the person who administers that
 * property (PropertyAccessService::canAdminister), so a property administrator never sees a sibling
 * property's setup even if they are handed the URL.
 */
class PropertyController extends Controller
{
    public const PROPERTY_TYPES = ['hotel' => 'Hotel', 'resort' => 'Resort', 'guest_house' => 'Guest house', 'service_apartment' => 'Serviced apartment', 'restaurant' => 'Restaurant', 'cafe' => 'Café'];

    public function __construct(
        protected PropertyService $properties,
        protected PropertyAccessService $access,
        protected PropertyHealthService $health,
    ) {}

    public function index(Request $request)
    {
        $register = $this->register($request);

        return view('properties.index', $register + ['currentHotel' => hotel()]);
    }

    /**
     * v10.10 the register as a file.
     *
     * The screen was the only way to read these figures, so an operator who wanted them in a
     * spreadsheet opened five property pages and retyped. This is the same rows, the same filters and
     * the same licence coverage as the screen — not a separate query that could disagree with it — and
     * the header line says which filter produced the file, because a download with no date and no
     * scope is the kind of attachment nobody trusts a month later.
     */
    public function export(Request $request)
    {
        $register = $this->register($request);
        $filters = $register['filters'];
        $scope = array_filter(['q' => $filters['q'], 'status' => $filters['status'], 'group' => $filters['group']], fn ($v) => $v !== '' && $v !== null);

        $name = 'properties_'.bdate()->format('Ymd').($scope ? '_filtered' : '').'.csv';

        return response()->streamDownload(function () use ($register, $scope) {
            $out = fopen('php://output', 'w');

            // A filter that was on is named in the file itself, not left in the URL it came from.
            if ($scope) {
                fputcsv($out, ['Filtered register: '.collect($scope)->map(fn ($v, $k) => $k.'='.$v)->implode(' · ')]);
            }

            fputcsv($out, ['Property', 'Hotel ID', 'Type', 'Brand', 'City', 'State', 'Group', 'Business date',
                'Sellable rooms', 'Occupied', 'Occupancy %', 'In-house rooms', 'Staff', 'Open POS',
                'Night audit behind', 'Licence']);

            foreach ($register['rows'] as $r) {
                /** @var Hotel $p */
                $p = $r['hotel'];
                fputcsv($out, [
                    $p->name, $p->code, ucwords(str_replace('_', ' ', (string) $p->property_type)), $p->brand, $p->city,
                    Hotel::STATUSES[$p->status ?? 'active'] ?? $p->status, $p->group?->name,
                    $r['business_date']?->format('Y-m-d'),
                    $r['rooms'], $r['occupied'], $r['occ'], $r['in_house'], $r['staff'], $r['open_pos'],
                    $r['needs_night_audit'] ? 'Yes ('.$r['lag'].' day'.($r['lag'] === 1 ? '' : 's').')' : 'No',
                    $r['licence']['closed'] ? 'Nothing licensed'
                        : ($r['licence']['narrowed'] ? $r['licence']['keep'].' of '.$r['licence']['of'].' modules' : 'Follows the group'),
                ]);
            }

            // v10.11 the file closes the way the screen does: one total line under the rows, so the
            // download reconciles to the header stats instead of needing a spreadsheet formula — and
            // occupancy is the portfolio figure, because an average of five occupancies is not one.
            $sum = $register['summary'];
            fputcsv($out, [
                'Portfolio total', '', '', '', '', '', '', '',
                $sum['rooms'], $sum['occupied'], $sum['occ'], $sum['in_house'], $sum['staff'], '', '', '',
                $sum['closed'].' of '.$sum['properties'].' licensed for nothing',
            ]);

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    /** The rows, the totals and the filters behind them — read once, by the screen and by the file. */
    protected function register(Request $request): array
    {
        $user = $request->user();
        $all = $user->accessibleProperties();

        $q = trim((string) $request->query('q'));
        if ($q !== '') {
            $needle = strtolower($q);
            $all = $all->filter(fn (Hotel $h) => str_contains(strtolower($h->name.' '.$h->code.' '.($h->city ?? '').' '.($h->brand ?? '')), $needle));
        }

        $status = (string) $request->query('status');
        if ($status !== '' && isset(Hotel::STATUSES[$status])) {
            $all = $all->where('status', $status);
        }

        $groupId = (int) $request->query('group');
        if ($groupId && $user->governsGroup($groupId)) {
            $all = $all->where('hotel_group_id', $groupId);
        }

        $rows = $this->health->rows($all->values());

        return [
            'rows' => $rows,
            'summary' => $this->health->summarise($rows),
            'statuses' => Hotel::STATUSES,
            'filters' => ['q' => $q, 'status' => $status, 'group' => $groupId ?: null],
            'groups' => $this->visibleGroups($user),
            'canAdd' => $this->groupsThatCanAdd($user),
        ];
    }

    public function show(Request $request, Hotel $hotel)
    {
        $this->authoriseView($request->user(), $hotel);

        // v10.5 the group's licence is the ceiling; this property may run fewer of its modules
        $group = $hotel->hotel_group_id ? HotelGroup::find($hotel->hotel_group_id) : null;
        $licence = LicenceService::stateForProperty($hotel, $group);

        return view('properties.show', [
            'property' => $hotel,
            'health' => $this->health->row($hotel),
            'access' => $this->accessRow($hotel),
            'members' => $hotel->propertyUsers()->with('role')->orderBy('name')->get()->map(fn (User $m) => [
                'user' => $m,
                'role' => $m->role?->name,
                'is_default' => (bool) $m->pivot->is_default,
                'last_used' => $m->pivot->last_used_at ? \Carbon\Carbon::parse($m->pivot->last_used_at) : null,
            ]),
            'ownStaff' => User::where('hotel_id', $hotel->id)->orderBy('name')->get(),
            'addressLine' => collect([$hotel->address, $hotel->city, $hotel->state, $hotel->country, $hotel->pincode])->filter()->implode(', '),
            'contactLine' => collect([$hotel->phone, $hotel->email, $hotel->website])->filter()->implode(' · '),
            'events' => $hotel->propertyEvents()->with('user')->limit(25)->get(),
            'blockers' => $this->properties->archiveBlockers($hotel),
            'candidates' => $this->grantCandidates($hotel),
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'canManage' => $this->access->canAdminister($request->user(), $hotel),
            // v10.1 a handover is a platform decision, and only to a group with room under its entitlement
            'canTransfer' => $this->access->isPlatformAdmin($request->user()) && ! $hotel->isArchived(),
            'transferGroups' => HotelGroup::where('id', '!=', $hotel->hotel_group_id)->orderBy('name')->get(),
            'licence' => $licence,
            'licenceCounts' => [
                'keep' => count(array_filter($licence, fn ($m) => $m['licensed'])),
                'group' => count(array_filter($licence, fn ($m) => $m['group'])),
            ],
            'licenceGroup' => $group,
            'licenceEditable' => $group && ($this->access->isGroupAdminOf($request->user(), $group) || $this->access->isPlatformAdmin($request->user())),
            'licenceNarrowed' => ! $hotel->followsGroupLicence(),
            'licenceGroupRestricted' => $group?->licensedModules() !== null,
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('properties.manage'), 403);

        $groups = $this->groupsThatCanAdd($user);
        abort_if($groups->isEmpty(), 403, 'No group you administer has room for another property.');

        $groupId = (int) $request->query('group') ?: $groups->first()->id;
        $group = $groups->firstWhere('id', $groupId) ?? $groups->first();

        return view('properties.form', [
            'property' => new Hotel(['property_type' => $group->default_property_type ?: 'hotel', 'country' => $group->country, 'currency' => $group->currency, 'timezone' => $group->timezone]),
            'group' => $group,
            'groups' => $groups,
            'types' => self::PROPERTY_TYPES,
            'timezones' => $this->timezones(),
            'isNew' => true,
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('properties.manage'), 403);

        $data = $request->validate([
            'hotel_group_id' => 'required|integer|exists:hotel_groups,id',
            'code' => 'required|alpha_dash|max:30|unique:hotels,code',
            'name' => 'required|string|max:255',
            'property_type' => 'required|in:'.implode(',', array_keys(self::PROPERTY_TYPES)),
            'status' => 'required|in:draft,active',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'currency' => 'nullable|string|max:10',
            'timezone' => 'nullable|string|max:50',
            'business_date' => 'nullable|date',
            'opened_on' => 'nullable|date',
            'brand' => 'nullable|string|max:255',
            'admin_username' => 'nullable|alpha_dash|max:60|unique:users,username',
            'admin_name' => 'nullable|string|max:255',
            'admin_email' => 'nullable|email|max:255',
            'admin_password' => 'nullable|required_with:admin_username|string|min:8',
        ]);

        $user = $request->user();
        $group = HotelGroup::findOrFail($data['hotel_group_id']);
        abort_unless($this->access->isGroupAdminOf($user, $group) || $this->access->isPlatformAdmin($user), 403);

        $hotel = $this->properties->create($group, $data, $user);

        return redirect()->route('properties.show', $hotel)
            ->with('success', "{$hotel->name} created as ".$hotel->statusLabel().($hotel->isDraft() ? ' — finish the setup, then take it live.' : '.'));
    }

    public function edit(Request $request, Hotel $hotel)
    {
        $this->authoriseManage($request->user(), $hotel);

        return view('properties.form', [
            'property' => $hotel,
            'group' => $hotel->group,
            'groups' => collect([$hotel->group])->filter(),
            'types' => self::PROPERTY_TYPES,
            'timezones' => $this->timezones(),
            'isNew' => false,
        ]);
    }

    public function update(Request $request, Hotel $hotel)
    {
        $this->authoriseManage($request->user(), $hotel);

        $data = $request->validate([
            'code' => 'required|alpha_dash|max:30|unique:hotels,code,'.$hotel->id,
            'name' => 'required|string|max:255',
            'legal_name' => 'nullable|string|max:255',
            'property_type' => 'required|in:'.implode(',', array_keys(self::PROPERTY_TYPES)),
            'brand' => 'nullable|string|max:255',
            'star_rating' => 'nullable|integer|min:1|max:5',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:255',
            'pincode' => 'nullable|string|max:20',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:255',
            'website' => 'nullable|string|max:255',
            'tax_number' => 'nullable|string|max:40',
            'currency' => 'required|string|max:10',
            'currency_symbol' => 'nullable|string|max:5',
            'timezone' => 'required|string|max:50',
            'checkin_time' => ['nullable', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'checkout_time' => ['nullable', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'invoice_footer' => 'nullable|string|max:500',
            'opened_on' => 'nullable|date',
            'sort_order' => 'nullable|integer|min:0|max:9999',
            'is_head_office' => 'nullable|boolean',
        ]);
        $data['is_head_office'] = $request->boolean('is_head_office');

        $this->properties->update($hotel, $data, $request->user());

        return redirect()->route('properties.show', $hotel)->with('success', "{$hotel->name} updated.");
    }

    public function activate(Request $request, Hotel $hotel)
    {
        $this->authoriseManage($request->user(), $hotel);
        $data = $request->validate(['business_date' => 'nullable|date']);
        if (! empty($data['business_date'])) {
            $hotel->forceFill(['business_date' => $data['business_date']])->save();
        }
        $this->properties->activate($hotel, $request->user(), $request->input('reason'));

        return back()->with('success', "{$hotel->name} is live.");
    }

    public function suspend(Request $request, Hotel $hotel)
    {
        $this->authoriseManage($request->user(), $hotel);
        $data = $request->validate(['reason' => 'required|string|min:4|max:255']);
        $this->properties->suspend($hotel, $request->user(), $data['reason']);

        return back()->with('success', "{$hotel->name} suspended. Its people keep their history and lose access until it resumes.");
    }

    public function resume(Request $request, Hotel $hotel)
    {
        $this->authoriseManage($request->user(), $hotel);
        $this->properties->resume($hotel, $request->user(), $request->input('reason'));

        return back()->with('success', "{$hotel->name} resumed.");
    }

    public function archive(Request $request, Hotel $hotel)
    {
        $this->authoriseManage($request->user(), $hotel);
        $data = $request->validate(['reason' => 'required|string|min:4|max:255']);
        $this->properties->archive($hotel, $request->user(), $data['reason']);

        return redirect()->route('properties.index')->with('success', "{$hotel->name} retired. Nothing was deleted — its books stay readable.");
    }

    /**
     * v10.5 switch modules off at this property without touching the group.
     *
     * The group's licence is the ceiling: anything the group did not buy is refused here rather than
     * filtered out silently, and an empty tick list stores null, which means "follow the group" — so
     * a property that stops narrowing follows the group again, including modules added later.
     */
    public function licence(Request $request, Hotel $hotel)
    {
        $user = $request->user();
        $group = $hotel->hotel_group_id ? HotelGroup::find($hotel->hotel_group_id) : null;
        abort_unless($group && ($this->access->isGroupAdminOf($user, $group) || $this->access->isPlatformAdmin($user)), 403,
            'Only a group administrator can narrow the licence of one of the group\'s properties.');

        $data = $request->validate(['modules' => 'nullable|array', 'modules.*' => 'string|max:60', 'follow' => 'nullable|boolean']);

        $ceiling = LicenceService::licenceOf($group);              // null = the group bought everything
        $catalogue = array_keys(LicenceService::catalogue());
        $chosen = collect($data['modules'] ?? [])->map(fn ($m) => (string) $m)->unique()
            ->filter(fn ($m) => in_array($m, $catalogue, true))
            ->filter(fn ($m) => $ceiling === null || in_array($m, $ceiling, true))
            ->values();

        $everything = $ceiling === null ? $catalogue : $ceiling;
        $before = $hotel->licensedModules();
        // An explicit "follow the group" is its own action: it is the only way back from a property
        // that was narrowed to nothing, and it does not depend on counting boxes correctly.
        $now = $request->boolean('follow') || count($chosen) === count($everything) ? null : $chosen->all();

        $hotel->forceFill(['licensed_modules' => $now])->save();
        LicenceService::forget();

        $count = count($everything) - count($chosen->all());

        // v10.7 name the modules, not only their number: "6 of 7" cannot settle a dispute months
        // later, and the two lists (what went off, what came back) are what somebody actually asks.
        $was = $before === null ? $everything : $before;
        $is = $now === null ? $everything : $now;
        $off = array_values(array_diff($was, $is));
        $on = array_values(array_diff($is, $was));

        PropertyEvent::record('licence_changed', sprintf(
            'Licence at %s %s. %s%s',
            $hotel->name,
            $now === null ? 'set to follow the group' : 'narrowed to '.count($chosen).' of '.count($everything).' module(s)',
            $count > 0 ? $count.' module(s) switched off at this property only; the group keeps them.' : 'Nothing was switched off.',
            ($off ? ' Switched off: '.implode(', ', $off).'.' : '')
                .($on ? ' Switched back on: '.implode(', ', $on).'.' : '')
        ), $hotel, [
            'before' => $before, 'licensed' => $now, 'actor' => $user->username,
            'switched_off' => $off, 'switched_on' => $on,
        ]);

        return back()->with('success', $now === null
            ? "{$hotel->name} follows the group licence again — including modules added later."
            : "{$hotel->name} now runs ".count($chosen)." of the group's ".count($everything).' module(s). The screens of the rest refuse at this property only.');
    }

    /** v10.1 hand the property to another group (platform administrator only). */
    public function transfer(Request $request, Hotel $hotel)
    {
        $this->authoriseManage($request->user(), $hotel);
        $data = $request->validate([
            'group_id' => 'required|integer|exists:hotel_groups,id',
            'reason' => 'required|string|min:4|max:255',
        ]);

        $result = $this->properties->transfer($hotel, HotelGroup::findOrFail($data['group_id']), $request->user());

        $dropped = $result['licence_dropped'] ?? [];

        return redirect()->route('properties.show', $hotel)->with('success', sprintf(
            '%s handed to %s. %d staff account(s) moved with it, %d cross-property grant(s) revoked, %d group administrator(s) demoted.%s Reason: %s',
            $hotel->name, $result['hotel']->group?->name ?? 'the new group', $result['moved'], $result['revoked'], $result['demoted'],
            $dropped ? ' Its own licence was reconciled with the new group\'s: '.count($dropped).' module(s) it had bought at that property are not on the receiving group\'s licence (' . implode(', ', $dropped) . ').' : '',
            $data['reason']
        ));
    }

    public function grant(Request $request, Hotel $hotel)
    {
        $this->authoriseManage($request->user(), $hotel);
        $data = $request->validate([
            // The endpoint must apply the same rule the picker shows: the person's group matches this
            // property's group, or they are ungrouped and will be adopted (grantCandidates semantics).
            'user_id' => ['required', 'integer', function ($attribute, $value, $fail) use ($hotel) {
                if (! $this->grantableUsers($hotel)->whereKey($value)->exists()) {
                    $fail('That person cannot be granted this property.');
                }
            }],
            'role_id' => 'nullable|integer|exists:roles,id',
            'is_default' => 'nullable|boolean',
        ]);

        $this->properties->grantAccess($hotel, User::findOrFail($data['user_id']), $request->user(), $data['role_id'] ?: null, $request->boolean('is_default')); // audit-ok: $data['user_id'] validated as grantable above (same group or ungrouped)

        return back()->with('success', 'Access granted. It takes effect at their next sign-in or switch.');
    }

    public function revoke(Request $request, Hotel $hotel, User $user)
    {
        $this->authoriseManage($request->user(), $hotel);
        $this->properties->revokeAccess($hotel, $user, $request->user());

        return back()->with('success', "{$user->name}'s access to {$hotel->name} removed.");
    }

    protected function authoriseView(User $user, Hotel $hotel): void
    {
        $ok = $this->access->canAdminister($user, $hotel) || $this->access->hasGrant($user, $hotel);
        abort_unless($user->hasPermission('properties.view') && $ok, 403);
    }

    protected function authoriseManage(User $user, Hotel $hotel): void
    {
        abort_unless($user->hasPermission('properties.manage') && $this->access->canAdminister($user, $hotel), 403);
    }

    protected function visibleGroups(User $user)
    {
        if ($this->access->isPlatformAdmin($user)) {
            return HotelGroup::orderBy('name')->get();
        }
        if ($user->is_group_admin && $user->hotel_group_id) {
            return HotelGroup::whereKey($user->hotel_group_id)->get();
        }

        return HotelGroup::whereIn('id', $user->accessibleProperties()->pluck('hotel_group_id')->filter()->unique())->orderBy('name')->get();
    }

    /** Groups this user may open a property in — entitlement and suspension already applied. */
    protected function groupsThatCanAdd(User $user)
    {
        return $this->visibleGroups($user)->filter(fn (HotelGroup $g) => $g->canAddProperty() && ($this->access->isGroupAdminOf($user, $g) || $this->access->isPlatformAdmin($user)))->values();
    }

    protected function accessRow(Hotel $hotel): array
    {
        $granted = $hotel->propertyUsers()->count();
        $staff = User::where('hotel_id', $hotel->id)->where('is_active', true)->count();

        return [
            'staff' => $staff,
            'granted' => $granted,
                'group_admins' => $hotel->hotel_group_id ? User::where('hotel_group_id', $hotel->hotel_group_id)->where('is_group_admin', true)->count() : 0, // audit-ok: group admin headcount for THIS group
            'total' => $staff + $granted,
        ];
    }

    /** People who could be granted this property: the group's own people, plus ungrouped accounts. One rule for the picker and the validator. */
    protected function grantableUsers(Hotel $hotel)
    {
        $hotelIds = Hotel::where('hotel_group_id', $hotel->hotel_group_id)->pluck('id')->push($hotel->id)->unique();

        return User::where('is_active', true) // audit-ok: grantableUsers — group admin grants across the group's own properties
            ->where(function ($q) use ($hotel, $hotelIds) {
                $q->whereIn('hotel_id', $hotelIds)->orWhere('hotel_group_id', $hotel->hotel_group_id)->orWhereNull('hotel_group_id');
            });
    }

    /** The picker rows for the grant form. */
    protected function grantCandidates(Hotel $hotel)
    {
        return $this->grantableUsers($hotel)
            ->orderBy('name')->limit(200)->get(['id', 'name', 'username', 'hotel_id']);
    }

    protected function timezones(): array
    {
        $zones = ['Africa/Accra', 'Africa/Lagos', 'Africa/Abidjan', 'Africa/Nairobi', 'Africa/Johannesburg', 'Africa/Cairo', 'Europe/London', 'Europe/Paris', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Singapore', 'America/New_York', 'UTC'];

        return array_combine($zones, $zones);
    }
}
