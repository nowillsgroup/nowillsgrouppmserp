<?php

namespace App\Support;

use App\Models\Hotel;

/**
 * v10.4 – which licence module a guest-facing path belongs to, and how to find its property.
 *
 * The rules live in config/licence.php; this class only answers two questions about a path:
 * which surface claims it (longest prefix wins) and, when the surface says how, which hotel
 * is behind the code or token in the URL. Both answers are needed before the controller runs,
 * because the licence belongs to the group that owns the property and an unlicensed module
 * must not be able to serve the page at all.
 *
 * `config/licence.php` is the single list: the audit in deploy/dev/audit/licence_surface_check.js
 * proves every public route is claimed by it and that every module it names exists in the
 * permission catalogue.
 */
class LicenceSurfaces
{
    /** Shortest thing that can be a guest token in this application (QR tables use 16, the rest 40). */
    public const TOKEN_LENGTH = 16;

    /** @var array<string, array>|null */
    protected static ?array $rules = null;

    public static function rules(): array
    {
        return self::$rules ??= (array) config('licence', []);
    }

    /**
     * The surface a request path belongs to.
     *
     * Returns null when nothing claims the path (a signed-in screen), the entry itself with
     * `module` => null for a surface a licence may never refuse, or the surface's rule plus the
     * prefix that matched.
     */
    public static function surfaceFor(string $path): ?array
    {
        $path = self::normalise($path);

        $always = self::longestMatch((array) (self::rules()['always'] ?? []), $path);
        if ($always !== null) {
            return ['module' => null, 'prefix' => $always, 'label' => self::rules()['always'][$always]];
        }

        $prefix = self::longestMatch((array) (self::rules()['surfaces'] ?? []), $path);
        if ($prefix === null) {
            return null;
        }

        return self::rules()['surfaces'][$prefix] + ['prefix' => $prefix];
    }

    /** The module the REST API path belongs to — `default` when nothing more specific matches. */
    public static function apiModule(string $path): string
    {
        $api = (array) (self::rules()['api'] ?? []);
        $prefix = self::longestMatch($api, self::normalise($path));

        return (string) ($prefix !== null ? $api[$prefix] : ($api['default'] ?? 'API & Integrations'));
    }

    /**
     * How much of the guest estate the licence can decide from the URL alone, for the licence panel.
     * The remainder is not hand-waved: each one carries a reason in config/licence.php and the audit
     * fails if that reason goes missing.
     */
    public static function coverage(): array
    {
        $fromUrl = 0;
        $residual = [];
        foreach ((array) (self::rules()['surfaces'] ?? []) as $prefix => $rule) {
            $recipe = $rule['resolve'] ?? null;
            if (is_array($recipe) && $recipe !== [] && empty($rule['api'])) {
                $fromUrl++;
            } elseif (! empty($rule['api'])) {
                continue;
            } else {
                $residual[] = (string) ($rule['label'] ?? $prefix);
            }
        }

        return ['fromUrl' => $fromUrl, 'residual' => $residual, 'total' => $fromUrl + count($residual)];
    }

    /** Every surface, by module, for the licence panel: module => list of labels. */
    public static function byModule(): array
    {
        $out = [];
        foreach ((array) (self::rules()['surfaces'] ?? []) as $prefix => $rule) {
            $module = (string) ($rule['module'] ?? '');
            if ($module === '') {
                continue;
            }
            $out[$module][] = (string) ($rule['label'] ?? $prefix);
        }

        return $out;
    }

    /**
     * Does this path really look like a guest request for this surface?
     *
     * Some guest prefixes are also the first segment of a signed-in screen — `/feedback` is the
     * public form with a hotel code and the answers list without one; `/stay/{stay}/…` are
     * reservation-desk screens whose `{stay}` is a numeric id, while the public portal is
     * `/stay/{token}` with the route's own 40-character rule. The recipe says which kind of value
     * the surface expects, so the same rule decides: a code is 2–20 characters, a token is a token.
     * Getting this wrong would hide a paid-for screen, which is worse than missing a guest page.
     */
    public static function looksLikeGuest(array $surface, string $path): bool
    {
        $recipe = $surface['resolve'] ?? null;
        if (! is_array($recipe) || $recipe === []) {
            return true; // the surface opens its own link inside the controller
        }

        $value = self::valueFor($surface, $path);
        if (in_array($recipe[0] ?? 'code', ['token', 'setting'], true)) {
            return mb_strlen($value) >= self::TOKEN_LENGTH;
        }

        return (bool) preg_match('/^[A-Za-z0-9_-]{2,20}$/', $value);
    }

    /** The value the recipe would read: the segment after the prefix, without the surface's suffix. */
    public static function valueFor(array $surface, string $path): string
    {
        $path = self::normalise($path);
        $prefix = rtrim((string) ($surface['prefix'] ?? ''), '/');
        $rest = $prefix === '' ? '' : trim(mb_substr($path, mb_strlen($prefix)), '/');
        $parts = $rest === '' ? [] : explode('/', $rest);
        $value = (string) ($parts[max(0, ((int) ($surface['segment'] ?? 1)) - 1)] ?? '');
        if (isset($surface['strip']) && $surface['strip'] !== '') {
            $value = (string) preg_replace('/'.preg_quote((string) $surface['strip'], '/').'$/i', '', $value);
        }

        return $value;
    }

    /**
     * The hotel behind this request, resolved from the surface's recipe.
     *
     * The value is read from the path (the segment after the matched prefix — the global stack runs
     * before routing, so there are no route parameters yet), because this has to be answered before
     * the controller and before the session. null means "not known yet": the surface resolves its own
     * link, or the code/token matches nothing and the controller will 404 by itself.
     */
    public static function propertyFor(array $surface, string $path): ?Hotel
    {
        $recipe = $surface['resolve'] ?? null;
        if (! is_array($recipe) || $recipe === []) {
            return null;
        }

        $value = self::valueFor($surface, $path);
        if ($value === '') {
            return null;
        }

        // ['setting', 'pbx_token'] – the segment is the value of a per-hotel setting row (a token the
        // hotel pastes into its own PBX or review platform, so it lives in settings, not a table)
        if (($recipe[0] ?? '') === 'setting') {
            $key = (string) ($recipe[1] ?? '');
            $row = \App\Models\Setting::withoutGlobalScope('hotel')->where('key', $key)->where('value', $value)->first();

            return $row?->hotel_id ? Hotel::find($row->hotel_id) : null;
        }

        // ['code'] – the path segment is hotels.code
        if (count($recipe) === 1) {
            return Hotel::where('code', $value)->first() ?: Hotel::where('code', strtoupper($value))->first();
        }

        // ['token', Model::class, 'column', 'hash'?] – the path segment is a column on that model
        $model = $recipe[1] ?? null;
        $column = $recipe[2] ?? null;
        if (! is_string($model) || ! class_exists($model) || ! is_string($column)) {
            return null;
        }

        if (($recipe[3] ?? null) === 'sha256') {
            $value = hash('sha256', $value);
        }

        $row = $model::withoutGlobalScopes()->where($column, $value)->first();
        $hotelId = $row?->hotel_id ?? null;

        return $hotelId ? Hotel::find($hotelId) : null;
    }

    /** Segment-aware longest-prefix match: `/v` claims `/v/CODE` but never `/visitor-pass/…`. */
    protected static function longestMatch(array $rules, string $path): ?string
    {
        $best = null;
        $bestLength = -1;
        foreach (array_keys($rules) as $prefix) {
            $prefix = (string) $prefix;
            $trimmed = rtrim($prefix, '/');
            $hit = $trimmed === '' ? $path === '/' : ($path === $trimmed || str_starts_with($path, $trimmed.'/'));
            if ($hit && strlen($trimmed) > $bestLength) {
                $best = $prefix;
                $bestLength = strlen($trimmed);
            }
        }

        return $best;
    }

    protected static function normalise(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        return $path === '' ? '/' : $path;
    }
}
