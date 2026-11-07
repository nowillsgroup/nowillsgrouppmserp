<?php

namespace App\Services;

use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Support\Tenant;

/**
 * v10.3 what this tenant actually bought.
 *
 * The unit of a licence is the module the permission catalogue already uses, so a licence cannot drift
 * away from the code: every route in the application is gated by a permission, every permission
 * belongs to exactly one catalogue module, and the licence is a list of modules. That is the whole
 * mapping — there is no second list of screens to keep in step.
 *
 * licensed_modules === null means everything, including modules shipped after the licence was written.
 * That is the default for every existing group, so installing this release cannot remove a screen from
 * a tenant that never asked to be restricted. A list means exactly that list.
 *
 * Enforcement is in RequirePermission (the route) and in can_do() (the sidebar and the buttons), never
 * only in the UI: an unlicensed module answers 403 if somebody types its URL. v10.4 adds the other
 * half: the guest surfaces in config/licence.php ask allowsModule() from the global LicenceSurface
 * middleware, and the REST API asks it in ApiAuthenticate once the bearer key has named the property.
 *
 * v10.5 adds the property: a group licence is the ceiling, a property may keep less of it (the group
 * bought the spa module once; only the beach property runs a spa). licenceOfHotel() intersects the
 * two, so nothing a property does can widen what the group bought.
 */
class LicenceService
{
    /** @var array<int, array<int, string>|null> group id => licence (null = all) */
    protected static array $licences = [];

    /** @var array<int, array<int, string>|null> hotel id => the licence in force there (null = all) */
    protected static array $hotelLicences = [];

    /** @var array<string, string>|null permission key => module */
    protected static ?array $moduleOf = null;

    /** Every catalogue module and the permissions it holds. */
    public static function catalogue(): array
    {
        return config('pms.permissions', []);
    }

    /** The module a permission belongs to, or null for a key that is not in the catalogue. */
    public static function moduleOf(string $perm): ?string
    {
        if (self::$moduleOf === null) {
            self::$moduleOf = [];
            foreach (self::catalogue() as $module => $perms) {
                foreach (array_keys($perms) as $key) {
                    self::$moduleOf[$key] = $module;
                }
            }
        }

        return self::$moduleOf[$perm] ?? null;
    }

    public static function moduleLabel(string $module): string
    {
        return $module;
    }

    /** Permission counts per module, for the licence panel. */
    public static function moduleSizes(): array
    {
        return array_map(fn ($perms) => count($perms), self::catalogue());
    }

    /** The licence of the current tenant, or null when it is unrestricted. */
    public static function licence(): ?array
    {
        $hotel = Tenant::hotel();

        return $hotel ? self::licenceOfHotel($hotel) : null;
    }

    /**
     * The licence in force AT A PROPERTY: the group's list, narrowed by the property's own.
     *
     * The intersection is the rule, and it is the whole point of v10.5: a group that owns one spa
     * buys the module once, and the property without a spa switches it off for itself. A property
     * can never end up with more than its group bought — widening is a commercial decision, and it
     * is taken on the group, by the platform administrator.
     */
    public static function licenceOfHotel(Hotel $hotel): ?array
    {
        $id = (int) $hotel->id;
        if (array_key_exists($id, self::$hotelLicences)) {
            return self::$hotelLicences[$id];
        }

        $group = self::licenceOf(self::groupOf($hotel));
        $own = $hotel->licensedModules();

        return self::$hotelLicences[$id] = match (true) {
            $own === null => $group,                                  // follows the group as it stands
            $group === null => $own,                                   // no group cap to narrow
            default => array_values(array_intersect($own, $group)),     // the narrower of the two
        };
    }

    /** The licence of one group, or null when it is unrestricted. */
    public static function licenceOf(?HotelGroup $group): ?array
    {
        if (! $group) {
            return null;
        }

        if (! array_key_exists((int) $group->id, self::$licences)) {
            $list = $group->licensed_modules;
            self::$licences[(int) $group->id] = is_array($list) ? array_values(array_map('strval', $list)) : null;
        }

        return self::$licences[(int) $group->id];
    }

    /**
     * May this property use this module? The guest surfaces (config/licence.php) ask this, because a
     * public page has no signed-in user whose permissions could be checked — the property itself is
     * the only thing known when the page is refused.
     */
    public static function allowsModule(string $module, ?Hotel $hotel = null): bool
    {
        $hotel = $hotel ?? Tenant::hotel();
        if (! $hotel) {
            return true; // nothing to ask about (console, queue, a page that has not resolved its stay yet)
        }

        $licence = self::licenceOfHotel($hotel);

        return $licence === null || in_array($module, $licence, true);
    }

    /**
     * Does the GROUP hold this module, ignoring any narrowing at a property?
     *
     * A refusal has to say which of the two licences is missing: a group that never bought the module
     * needs the platform administrator, a property that switched it off needs the group
     * administrator who runs that property. Those are different people, so the answer is not merged.
     */
    public static function groupHolds(string $module, ?Hotel $hotel = null): bool
    {
        $hotel = $hotel ?? Tenant::hotel();
        $licence = $hotel ? self::licenceOf(self::groupOf($hotel)) : self::licence();

        return $licence === null || in_array($module, $licence, true);
    }

    /** The group behind a property, read through the relation when it is already loaded. */
    public static function groupOf(Hotel $hotel): ?HotelGroup
    {
        if ($hotel->relationLoaded('group')) {
            return $hotel->group;
        }

        return $hotel->hotel_group_id ? HotelGroup::find($hotel->hotel_group_id) : null;
    }

    /** May the current tenant use the function behind this permission? */
    public static function licensed(string $perm): bool
    {
        $licence = self::licence();
        if ($licence === null) {
            return true; // unrestricted tenant, or no tenant at all (console, queue, sign-in)
        }

        $module = self::moduleOf($perm);
        if ($module === null) {
            // A permission outside the catalogue cannot be attributed to a module. The configuration
            // check proves there are none on any route; until one appears, allow rather than guess.
            return true;
        }

        return in_array($module, $licence, true);
    }

    /** Every module of the tenant's licence, keyed by module, with whether it is on this licence. */
    public static function stateFor(?HotelGroup $group): array
    {
        $licence = $group?->licensed_modules;
        $licence = is_array($licence) ? array_map('strval', $licence) : null;
        $out = [];
        foreach (self::catalogue() as $module => $perms) {
            $out[$module] = [
                'permissions' => count($perms),
                'licensed' => $licence === null ? true : in_array($module, $licence, true),
            ];
        }

        return $out;
    }

    /**
     * The module list for a property screen: what the group bought, what this property keeps, and
     * whether the property has a list of its own. Three states, never blurred into one badge.
     */
    public static function stateForProperty(Hotel $hotel, ?HotelGroup $group): array
    {
        $groupLicence = self::licenceOf($group);
        $own = $hotel->licensedModules();
        $effective = self::licenceOfHotel($hotel);

        $out = [];
        foreach (self::catalogue() as $module => $perms) {
            $inGroup = $groupLicence === null || in_array($module, $groupLicence, true);
            $out[$module] = [
                'permissions' => count($perms),
                'group' => $inGroup,
                'licensed' => $effective === null || in_array($module, $effective, true),
                'own' => $own !== null && in_array($module, $own, true),
            ];
        }

        return $out;
    }

    /** Forget the cached licence — call it after the licence changes in the same request. */
    public static function forget(?int $groupId = null): void
    {
        if ($groupId === null) {
            self::$licences = [];
        } else {
            unset(self::$licences[$groupId]);
        }

        // The property licences are derived from the group's, so they go with it.
        self::$hotelLicences = [];
    }

    protected static function group(): ?HotelGroup
    {
        $hotel = Tenant::hotel();

        return $hotel ? self::groupOf($hotel) : null;
    }
}
