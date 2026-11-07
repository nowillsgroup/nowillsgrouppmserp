<?php

namespace App\Http\Controllers;

use App\Models\HotelGroup;
use App\Models\PropertyEvent;
use App\Services\LicenceService;
use App\Services\PropertyAccessService;
use App\Services\PropertyHealthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v10.0 the tenant account itself: identity, brand, the defaults every new property inherits,
 * the property entitlement, and the switch that suspends the whole group.
 *
 * A group is the billing and accountability boundary. Suspending it is reserved for the platform
 * administrator: a group administrator can retire individual properties but cannot lock their own
 * staff out of the building.
 */
class HotelGroupController extends Controller
{
    public function __construct(protected PropertyAccessService $access, protected PropertyHealthService $health) {}

    public function edit(Request $request)
    {
        $group = $this->group($request);
        if (! $group) {
            // A property administrator holds properties.manage inside their own property but does
            // not govern the group account, so send them to the register rather than a bare 404.
            return redirect()->route('properties.index')->with('warning', 'The group account is administered by a group administrator. You can still open and configure your own property.');
        }

        return view('group.account', [
            'group' => $group,
            'rows' => $this->health->rows($group->hotels()->orderBy('sort_order')->orderBy('name')->get()),
            'summary' => $this->health->summarise($this->health->rows($group->hotels()->orderBy('name')->get())),
            'events' => PropertyEvent::where('hotel_group_id', $group->id)->with(['user', 'hotel'])->latest('id')->limit(20)->get(),
            'canSuspendGroup' => $this->access->isPlatformAdmin($request->user()),
            'admins' => $group->admins()->with('role')->orderBy('name')->get(),
            'types' => PropertyController::PROPERTY_TYPES,
            // v10.3 what the tenant bought, module by module
            'licence' => LicenceService::stateFor($group),
            'licenceEditable' => $this->access->isPlatformAdmin($request->user()),
            'licenceRestricted' => $group->licensedModules() !== null,
            'guestSurfaces' => \App\Support\LicenceSurfaces::byModule(), // v10.4: the guest pages and feeds that follow the same licence
            'guestCoverage' => \App\Support\LicenceSurfaces::coverage(),
            // v10.6 a property licensed for nothing is closed, and its people stop holding a seat
            'seatsReleased' => count($group->seatsReleased()),
            'darkProperties' => count($group->darkPropertyIds()),
            'seatBasis' => $group->seatBasis(),
        ]);
    }

    public function update(Request $request)
    {
        $group = $this->group($request);
        abort_unless($group, 403);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|alpha_dash|max:30|unique:hotel_groups,code,'.$group->id,
            'legal_name' => 'nullable|string|max:255',
            'brand' => 'nullable|string|max:255',
            'management_company' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:30',
            'country' => 'nullable|string|max:255',
            'currency' => 'nullable|string|max:10',
            'timezone' => 'nullable|string|max:50',
            'default_property_type' => 'nullable|in:'.implode(',', array_keys(PropertyController::PROPERTY_TYPES)),
            'max_properties' => 'nullable|integer|min:1|max:500',
            'max_users' => 'nullable|integer|min:1|max:5000',
            'licence_ends_on' => 'nullable|date',
            'seat_basis' => 'nullable|in:active,named',
            'notes' => 'nullable|string|max:1000',
        ]);
        $data['code'] = strtoupper($data['code']);

        $group->fill($data)->save();
        PropertyEvent::record('group_updated', "Group {$group->name} settings updated", null, ['group' => $group->code, 'actor' => $request->user()->username]);

        return back()->with('success', 'Group settings saved. They apply to every property opened from now on.');
    }

    /**
     * v10.3 the module licence. Platform administrator only: this is the commercial decision, not an
     * operational one, and a group administrator who could widen their own licence would make it
     * meaningless.
     *
     * Ticking every module stores null — "everything, including what ships next" — so a tenant that
     * pays for the whole product is never quietly restricted by a future release.
     */
    public function licence(Request $request)
    {
        $group = $this->group($request);
        abort_unless($group && $this->access->isPlatformAdmin($request->user()), 403);

        $modules = array_keys(LicenceService::catalogue());
        $data = $request->validate([
            'modules' => 'nullable|array',
            'modules.*' => 'string|in:'.implode(',', array_map(fn ($m) => str_replace(',', ' ', $m), $modules)),
        ]);

        $chosen = collect($data['modules'] ?? [])->unique()->values();
        $unknown = $chosen->diff($modules);
        $chosen = $chosen->diff($unknown)->values();

        $before = $group->licensedModules();   // read before the licence is replaced
        $now = $chosen->count() === count($modules) ? null : $chosen->all();
        $group->forceFill(['licensed_modules' => $now])->save();
        LicenceService::forget($group->id);

        $restricted = $now === null ? 0 : count($modules) - count($now);

        // v10.7 the trail names the modules: a count tells the next administrator nothing about
        // which screen the group lost, and that is the only question ever asked of this row.
        $was = $before === null ? $modules : $before;
        $is = $now === null ? $modules : $now;
        $off = array_values(array_diff($was, $is));
        $on = array_values(array_diff($is, $was));

        PropertyEvent::record('licence_changed', 'Licence for '.$group->name.' set to '
            .($now === null ? 'every module' : count($now).' of '.count($modules).' modules')
            .($restricted ? ' — '.$restricted.' module(s) are not on it' : '')
            .($off ? '. Switched off: '.implode(', ', $off) : '')
            .($on ? '. Switched back on: '.implode(', ', $on) : ''), null, [
                'group' => $group->code, 'licensed' => $now, 'actor' => $request->user()->username,
                'switched_off' => $off, 'switched_on' => $on,
            ]);

        return back()->with('success', $now === null
            ? 'Every module is licensed, including modules added by later releases.'
            : $restricted.' module(s) are not on this licence. Their screens now refuse and disappear from the sidebar; the data behind them is untouched.');
    }

    /** Platform administrator only: stop the whole tenant at the account level. */
    public function suspend(Request $request)
    {
        $group = $this->group($request);
        abort_unless($group && $this->access->isPlatformAdmin($request->user()), 403);
        $data = $request->validate(['reason' => 'required|string|min:4|max:255']);

        DB::transaction(function () use ($group, $data, $request) {
            $group->forceFill(['status' => 'suspended', 'is_active' => false, 'suspended_at' => now()])->save();
            PropertyEvent::record('group_suspended', "Group {$group->name} suspended: {$data['reason']}", null, ['group' => $group->code, 'reason' => $data['reason'], 'actor' => $request->user()->username]);
        });

        return back()->with('success', "{$group->name} suspended. Nobody can sign in to any of its properties until it resumes.");
    }

    public function resume(Request $request)
    {
        $group = $this->group($request);
        abort_unless($group && $this->access->isPlatformAdmin($request->user()), 403);

        $group->forceFill(['status' => 'active', 'is_active' => true, 'suspended_at' => null])->save();
        PropertyEvent::record('group_resumed', "Group {$group->name} resumed", null, ['group' => $group->code, 'actor' => $request->user()->username]);

        return back()->with('success', "{$group->name} resumed. Its live properties are reachable again.");
    }

    /** The group the acting user administers, or the one they are working in. */
    protected function group(Request $request): ?HotelGroup
    {
        $user = $request->user();
        if ($user->is_group_admin && $user->hotel_group_id) {
            return HotelGroup::find($user->hotel_group_id);
        }
        if ($groupId = (int) $request->query('group')) {
            return $user->governsGroup($groupId) ? HotelGroup::find($groupId) : null;
        }
        $current = hotel();
        if ($current?->hotel_group_id && $user->governsGroup($current->hotel_group_id)) {
            return $current->group;
        }
        if ($user->is_super_admin && ! $user->hotel_group_id) {
            return HotelGroup::orderBy('name')->first();
        }

        return null;
    }
}
