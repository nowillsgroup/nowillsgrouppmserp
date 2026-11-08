<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\SettingChange;
use App\Support\ConfigurationChecks;
use Illuminate\Support\Collection;

/**
 * The configuration register.
 *
 * One place that answers "what can be configured in this property, where is it
 * set, and what is it set to". The registry lives in config/settings.php; this
 * service joins it to the live `settings` rows and to the audit trail, so the
 * configuration audit checks known literal and mapped reads; dynamically constructed
 * reads can still be missed.
 *
 * Nothing here writes. Every screen listed belongs to the module that owns it —
 * one write path per setting.
 */
class ConfigurationService
{
    /**
     * What the register prints instead of a credential. These settings are stored
     * encrypted by MessageService::storeSecret(), so the stored value is not a value
     * a person should read — it is either a ciphertext or, worse, a secret on a
     * printable sheet and in a CSV anybody can export. The register says whether one
     * is set and leaves the reading to the screen that owns it.
     */
    public const HIDDEN = 'Set — hidden';

    /**
     * Where each setting is read — generated from the code by
     * deploy/dev/build-setting-readers.mjs, so the register can answer "what depends on
     * this?" without scanning the application on every page view.
     */
    public static function readers(): array
    {
        return config('setting-readers', ['readers' => [], 'families' => [], 'accessors' => []]);
    }

    /** The files that read one setting: by name, by family prefix, through a map, or through an accessor. */
    public static function readersFor(string $key): array
    {
        $all = self::readers();
        if (str_ends_with($key, '_')) {
            return array_values($all['families'][$key] ?? []);
        }

        return array_values($all['readers'][$key] ?? $all['accessors'][$key] ?? $all['map'][$key] ?? []);
    }

    /**
     * Settings the code reads through a DEFAULTS map — `cfg($k) → setting($k, DEFAULTS[$k])` —
     * rather than by name in a call. The map is where the code names them, so the map is where
     * they are found.
     */
    public static function mapReads(): array
    {
        return config('setting-readers.map', []);
    }

    /**
     * Settings the code reads through a map that this register does not hold yet. They are
     * real configuration: they have a default, they change what the application does. Until
     * they are registered they cannot be shown, trailed, restored or fingerprinted, and the
     * handover sheet says so as a number rather than letting the gap pass unmentioned.
     */
    public static function mapGap(): array
    {
        $known = [];
        foreach (config('settings.modules', []) as $module) {
            foreach (array_keys($module['keys'] ?? []) as $key) {
                $known[$key] = true;
            }
        }

        return array_diff_key(self::mapReads(), $known);
    }

    /** How many settings are read behind a map and are not in this register yet. */
    public static function mapGapCount(): int
    {
        return count(self::mapGap());
    }

    /** Credential keys, as the registry declares them (`'secret' => [...]` per module). */
    public static function secrets(?string $module = null): array
    {
        $mods = config('settings.modules', []);
        if ($module !== null) {
            return array_values($mods[$module]['secret'] ?? []);
        }
        $out = [];
        foreach ($mods as $m) {
            $out = array_merge($out, $m['secret'] ?? []);
        }

        return array_values(array_unique($out));
    }

    /** Is this key a credential the register refuses to print? */
    public static function isSecretKey(string $key): bool
    {
        // The obsolete Robita token is no longer registered/read, but legacy values and
        // older audit rows can still contain a credential and must never be exposed.
        return $key === 'robita_access_token' || in_array($key, self::secrets(), true);
    }

    /**
     * The value trail: what each setting was before, newest first.
     *
     * Written by App\Observers\SettingValueObserver for model saves and deletes. Bulk SQL
     * updates/deletes bypass model events and older history cannot be reconstructed.
     * The observer masks credentials; the reader
     * also respects today's credential classification for rows written before it changed.
     */
    public static function valueTrail(?string $module = null, int $limit = 60): array
    {
        $keys = null;
        if ($module !== null) {
            $keys = config("settings.modules.$module.keys", []);
            if (! is_array($keys)) {
                $keys = [];
            }
            if ($keys === []) {
                return [];
            }
        }

        $query = SettingChange::query()->with('user:id,name')->latest('id')->take($limit);
        if ($keys !== null) {
            $exact = array_values(array_filter(array_keys($keys), fn ($key) => ! str_ends_with($key, '_')));
            $families = array_values(array_filter(array_keys($keys), fn ($key) => str_ends_with($key, '_')));
            $query->where(function ($q) use ($exact, $families) {
                $q->whereIn('key', $exact);
                $column = $q->getQuery()->getGrammar()->wrap('key');
                foreach ($families as $prefix) {
                    // A literal prefix, not LIKE: underscores in setting keys are not wildcards.
                    $q->orWhereRaw('substr('.$column.', 1, ?) = ?', [strlen($prefix), $prefix]);
                }
            });
        }

        $labels = self::labels();
        $out = [];
        foreach ($query->get() as $row) {
            // Older rows may predate a key's classification as a credential.
            $secret = (bool) $row->was_secret || self::isSecretKey((string) $row->key);
            $label = self::labelForKey((string) $row->key, $labels);
            $out[] = [
                'id' => $row->id,
                'key' => $row->key,
                'label' => $label['label'] ?? $row->key,
                'module' => $label['module'] ?? null,
                'module_label' => $label['module_label'] ?? '—',
                'was' => $row->created ? null : ($secret && $row->old_value !== null && trim((string) $row->old_value) !== '' ? self::HIDDEN : ($secret ? null : self::show($row->old_value))),
                'now' => $row->new_value === null || trim((string) $row->new_value) === '' ? 'cleared' : ($secret ? self::HIDDEN : self::show($row->new_value)),
                'secret' => $secret,
                'created' => (bool) $row->created,
                'when' => $row->created_at,
                'by' => $row->user?->name,
                'was_raw' => $row->created || $secret ? null : $row->old_value,
            ];

            // Can this one be put back? Ask before offering a button: a value the screen
            // would refuse today must say why, not fail after the click.
            $reason = self::restoreBlocked($out[count($out) - 1]);
            if ($reason === null) {
                $problem = self::restoreError($row->key, $row->old_value);
                $reason = $problem === null ? null : 'the value it had is no longer accepted: '.$problem;
            }
            $out[count($out) - 1]['restore_blocked'] = $reason;
            $out[count($out) - 1]['restore_ok'] = $reason === null;
        }

        return $out;
    }

    /**
     * The settings this register can put back by itself.
     *
     * A restore is only honest when the value can be checked against the rules the screen
     * enforces today — a select option that has since been removed, a store that has been
     * deleted, a number that was allowed then and is not now. The flat Settings form
     * (SettingController::KEYS) is the one place where those rules are data rather than
     * code, so those are the keys a restore is offered for. Everywhere else the trail shows
     * the old value and says which screen owns it: the module keeps its own validation.
     */
    public static function typed(): array
    {
        $out = [];
        foreach (\App\Http\Controllers\SettingController::KEYS as $key => $spec) {
            $out[$key] = [
                'label' => $spec[0] ?? $key,
                'type' => $spec[1] ?? 'text',
                'default' => $spec[2] ?? null,
                'options' => $spec[3] ?? null,
            ];
        }

        return $out;
    }

    /** Can this key be put back from the trail? Only a typed, non-credential setting. */
    public static function canRestore(string $key): bool
    {
        return ! self::isSecretKey($key) && array_key_exists($key, self::typed());
    }

    /**
     * Why a value cannot be put back, or null when it can. The page prints the reason
     * instead of hiding the row: "you cannot restore this" is information, not an omission.
     */
    public static function restoreBlocked(array $row): ?string
    {
        if (! empty($row['secret'])) {
            return 'credential values cannot be restored from this trail';
        }
        if (! self::canRestore((string) $row['key'])) {
            return 'this setting is owned by its module screen, which holds its own rules';
        }
        if ($row['created'] ?? false) {
            return 'there was nothing here before — it was first set, not changed';
        }
        if ($row['was'] === null) {
            return 'the value before this change was empty';
        }
        // Trail entries are clipped at 2,000 characters. Never restore a fragment
        // as if it were the full setting, even if the fragment happens to validate.
        $raw = $row['was_raw'] ?? null;
        if (is_string($raw) && mb_strlen($raw) === 2001 && str_ends_with($raw, '…')) {
            return 'the previous value was truncated in the trail — restore it from a backup or the owning screen';
        }

        return null;
    }

    /** The value checked against the rule the owning screen applies — an error message or null. */
    public static function restoreError(string $key, ?string $value): ?string
    {
        $spec = self::typed()[$key] ?? null;
        if ($spec === null) {
            return 'this setting is not one the register can validate';
        }

        $v = (string) $value;

        switch ($spec['type']) {
            case 'number':
                [$min, $max] = \App\Http\Controllers\SettingController::BOUNDS[$key] ?? ['0', null];
                return is_numeric($v) && (float) $v >= (float) $min && ($max === null || (float) $v <= (float) $max)
                    ? null : 'it is outside the range this screen accepts';
            case 'bool':
                return in_array($v, ['0', '1'], true) ? null : 'it is not a valid switch value';
            case 'text':
                return mb_strlen($v) <= 120 ? null : 'it is longer than the screen accepts for this field';
            case 'select':
                return is_array($spec['options']) && array_key_exists($v, $spec['options'])
                    ? null : 'that option no longer exists on the screen';
            case 'time':
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? null : 'it is not a time the screen accepts';
            case 'emails':
                $list = collect(preg_split('/[\s,;]+/', $v))->filter()->unique();
                return $list->count() > 0 && $list->count() <= 10
                    && $list->every(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))
                    ? null : 'it is not a list of valid e-mail addresses';
            case 'store':
                return \App\Models\Store::whereKey($v)->exists() ? null : 'that store no longer exists';
            case 'outlet':
                return \App\Models\Outlet::whereKey($v)->exists() ? null : 'that outlet no longer exists';
            default:
                return mb_strlen($v) <= 5000 ? null : 'it is longer than the screen accepts';
        }
    }

    /**
     * Version 2 fingerprint of registered settings. Structured JSON avoids ambiguity
     * between missing, null and empty values and between values containing newlines.
     * Credentials contribute only set/unset, never a guessable hash of their value.
     */
    public static function fingerprint(): array
    {
        $values = self::values();
        $records = [];
        $secrets = array_flip(self::secrets());
        $keys = array_keys(self::labels());
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            if (isset($secrets[$key])) {
                $set = isset($values[$key]) && trim((string) $values[$key]) !== '';
                $records[] = [$key, 'credential', $set];
            } elseif (str_ends_with((string) $key, '_')) {
                $held = [];
                foreach ($values as $k => $v) {
                    if (str_starts_with((string) $k, (string) $key)) {
                        $held[$k] = isset($secrets[(string) $k])
                            ? ['credential', $v !== null && trim((string) $v) !== '']
                            : ['value', $v === null ? null : (string) $v];
                    }
                }
                ksort($held, SORT_STRING);
                $members = [];
                foreach ($held as $k => $value) {
                    $members[] = [$k, $value];
                }
                $records[] = [$key, 'family', $members];
            } else {
                $records[] = array_key_exists($key, $values)
                    ? [$key, 'value', $values[$key] === null ? null : (string) $values[$key]]
                    : [$key, 'missing'];
            }
        }

        $full = hash('sha256', json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return [
            'full' => $full,
            'short' => strtoupper(substr($full, 0, 12)),
            'keys' => count($keys),
            'lines' => count($records),
            'at' => SettingChange::query()->latest('id')->value('created_at'),
        ];
    }

    /** How many credentials this property has set — the sheet says how many, never which value. */
    public static function credentialSummary(): array
    {
        $modules = 0;
        $keys = 0;
        $set = 0;

        foreach (self::modules() as $m) {
            $here = 0;
            $any = 0;
            foreach ($m['keys'] as $k) {
                if (! $k['secret']) {
                    continue;
                }
                $keys++;
                $here++;
                if ($k['set']) {
                    $set++;
                    $any++;
                }
            }
            if ($here > 0 && $any > 0) {
                $modules++;
            }
        }

        return ['modules' => $modules, 'keys' => $keys, 'set' => $set];
    }

    /** Where a key sits in the register: its label and the module that owns it. */
    public static function labels(): array
    {
        $out = [];
        foreach (config('settings.modules', []) as $moduleKey => $m) {
            foreach (($m['keys'] ?? []) as $key => $label) {
                $out[$key] = [
                    'label' => $label, 'module' => $moduleKey,
                    'module_label' => $m['label'] ?? $moduleKey,
                    'group' => $m['group'] ?? 'Platform',
                    'route' => $m['route'] ?? null,
                    'perm' => $m['perm'] ?? null,
                ];
            }
        }

        return $out;
    }

    /** Resolve a concrete family key without losing which member changed. */
    private static function labelForKey(string $key, array $labels): ?array
    {
        if (isset($labels[$key])) {
            return $labels[$key] + ['registry_key' => $key];
        }
        $match = null;
        $length = 0;
        foreach ($labels as $prefix => $label) {
            if (str_ends_with($prefix, '_') && str_starts_with($key, $prefix) && strlen($prefix) > $length) {
                $match = $label;
                $match['label'] .= ' ('.$key.')';
                $match['registry_key'] = $prefix;
                $length = strlen($prefix);
            }
        }

        return $match;
    }

    /** How the trail is doing: rows recorded, settings covered, and where it starts. */
    public static function trailCoverage(): array
    {
        $rows = SettingChange::query()->count();
        $labels = self::labels();
        $covered = [];
        foreach (SettingChange::query()->distinct()->pluck('key') as $key) {
            $registered = self::labelForKey((string) $key, $labels)['registry_key'] ?? null;
            if ($registered !== null) {
                $covered[$registered] = true;
            }
        }
        $keys = count($covered);
        $first = SettingChange::query()->oldest('id')->value('created_at');

        return [
            'rows' => $rows,
            'keys' => $keys,
            'keys_total' => count(self::labels()),
            'since' => $first ? \Illuminate\Support\Carbon::parse($first) : null,
        ];
    }

    /** The registry, joined to the live values and the last change on each module. */
    public static function modules(): array
    {
        $values = self::values();
        $last = self::lastChanged();
        $out = [];

        foreach (config('settings.modules', []) as $key => $m) {
            $rows = [];
            $set = 0;
            $changed = 0;
            $known = 0;
            $secrets = 0;
            $secret = $m['secret'] ?? [];
            foreach (($m['keys'] ?? []) as $k => $label) {
                $row = self::row($k, $label, $key, $values, in_array($k, $secret, true));
                $rows[] = $row;
                $set += $row['set'] ? 1 : 0;
                $changed += $row['changed'] ? 1 : 0;
                $known += $row['default'] !== null ? 1 : 0;
                $secrets += $row['secret'] ? 1 : 0;
            }

            $out[$key] = [
                'key' => $key,
                'label' => $m['label'] ?? $key,
                'group' => $m['group'] ?? 'Platform',
                'brief' => $m['brief'] ?? '',
                'route' => $m['route'] ?? null,
                'perm' => $m['perm'] ?? null,
                'edit' => $m['edit'] ?? $m['perm'] ?? null,
                'keys' => $rows,
                'total' => count($rows),
                'set' => $set,
                'missing' => count($rows) - $set,
                'changed' => $changed,
                'defaults_known' => $known,
                'secrets' => $secrets,
                'rules' => count(ConfigurationChecks::rulesFor((string) $key)),
                'last' => $last[$key] ?? null,
                'open' => ! empty($m['route']),
                'reachable' => ! empty($m['route']) && (empty($m['perm']) || can_do($m['perm'])),
            ];
        }

        return $out;
    }

    public static function module(string $key): ?array
    {
        return self::modules()[$key] ?? null;
    }

    /**
     * The built-in default of each setting — what the code falls back to when the property
     * has never set it. Generated from the `setting()` calls themselves by
     * deploy/dev/build-setting-defaults.mjs and proven in step with the code by the
     * configuration audit, so the register cannot show a default the code does not use.
     */
    public static function defaults(): array
    {
        return config('setting-defaults.defaults', []);
    }

    /** The default as a person reads it, or null when the key is read with no default at all. */
    public static function defaultFor(string $key): ?string
    {
        $defaults = self::defaults();

        return array_key_exists($key, $defaults) ? (string) $defaults[$key] : null;
    }

    /** Is the stored value different from the built-in default? Numbers compare as numbers. */
    public static function drifted($stored, ?string $default): bool
    {
        if ($default === null) {
            return false; // nothing to drift from
        }
        $a = trim((string) $stored);
        $b = trim($default);
        if ($a === $b) {
            return false;
        }

        return ! (is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) < 0.000001);
    }

    /** Every stored setting for this property, as key => value. */
    public static function values(): array
    {
        return Setting::pluck('value', 'key')->all();
    }

    /**
     * One register row. A key ending in `_` is a family — the code builds the real
     * key at runtime (`fb_sla_high`, `sms_tpl_welcome`), so the family is set when
     * any stored key carries the prefix and the row lists what it found.
     */
    public static function row(string $key, string $label, string $module, array $values, bool $secret = false): array
    {
        if ($secret) {
            $set = isset($values[$key]) && trim((string) $values[$key]) !== '';

            // the value is never shaped, shortened or exported — only whether one is stored
            return [
                'key' => $key, 'raw' => $key, 'label' => $label, 'family' => false, 'module' => $module,
                'secret' => true, 'value' => $set ? self::HIDDEN : null, 'set' => $set,
                'state' => $set ? 'set' : 'default', 'count' => 1,
                'default' => null, 'default_show' => null, 'changed' => false,
            ] + self::readBy($key);
        }

        if (str_ends_with($key, '_')) {
            $hits = [];
            foreach ($values as $k => $v) {
                if (str_starts_with($k, $key)) {
                    $hits[substr($k, strlen($key))] = self::isSecretKey((string) $k)
                        ? (trim((string) $v) === '' ? '—' : self::HIDDEN)
                        : self::show($v);
                }
            }
            ksort($hits);

            return [
                'key' => $key.'…', 'raw' => $key, 'label' => $label, 'family' => true, 'module' => $module, 'secret' => false,
                'value' => $hits ? implode(' · ', array_map(fn ($k, $v) => $k.' = '.$v, array_keys($hits), $hits)) : null,
                'set' => (bool) $hits, 'state' => $hits ? 'set' : 'default', 'count' => count($hits),
                'default' => null, 'default_show' => null, 'changed' => false,
            ] + self::readBy($key);
        }

        $v = $values[$key] ?? null;
        $set = $v !== null && trim((string) $v) !== '';
        $default = self::defaultFor($key);

        return [
            'key' => $key, 'raw' => $key, 'label' => $label, 'family' => false, 'module' => $module, 'secret' => false,
            'value' => $set ? self::show($v) : null, 'set' => $set,
            'state' => $set ? 'set' : 'default', 'count' => 1,
            'default' => $default, 'default_show' => $default === null ? null : self::show($default),
            'changed' => $set && self::drifted($v, $default),
            // only for a key with no built-in default: what actually comes back instead
            'unset_show' => $default === null ? self::unsetShow($key) : null,
        ] + self::readBy($key);
    }

    /** How a row says where it is read: the count, the names, and the paths in full. */
    private static function readBy(string $key): array
    {
        $files = self::readersFor($key);
        $names = array_map(fn ($f) => basename($f, '.php'), $files);

        return [
            'readers' => count($files),
            'read_in' => $files === [] ? '—'
                : (count($files) > 2 ? count($files).' files' : implode(', ', $names)),
            'read_paths' => implode(' · ', $files),
        ];
    }

    /** A stored value as a person reads it. */
    public static function show($v): string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return '—';
        }
        if (in_array($v, ['1', '0'], true)) {
            // a switch is stored as 1/0 whichever control wrote it
            $label = (string) $v === '1' ? 'On' : 'Off';

            return $label;
        }
        $v = preg_replace('/\s+/', ' ', $v) ?? $v;

        return mb_strlen($v) > 80 ? mb_substr($v, 0, 77).'…' : $v;
    }

    /**
     * How a module's configuration changes are written in the activity log.
     *
     * There are two conventions in this codebase, both deliberate:
     *   ActivityLogger::log('settings', 'banquet', …)   the flat Settings screen style
     *   ActivityLogger::log('transport', 'settings', …) the module's own screen style
     * and a few modules log under a name of their own (`transport` for Fleet, `frontdesk`
     * for the desk). The register declared none of that, so it only ever found the first
     * convention and silently showed "nothing changed" for the modules that use the second
     * — which is exactly what a "last changed by" column must not do. The registry names
     * the alias in `log`; everything else is derived here.
     */
    public static function logSpec(string $module): array
    {
        $m = config('settings.modules.'.$module, []);
        $modules = [$module];
        $actions = [$module];

        // `log` is either the name the trail uses instead of the module key (`transport`
        // for Fleet), or `module:action` when the module logs under both (`accounts:gl_mapping`).
        $log = $m['log'] ?? null;
        if (is_string($log) && $log !== '') {
            [$logModule, $logAction] = array_pad(explode(':', $log, 2), 2, null);
            $modules[] = $logModule;
            $actions[] = $logAction ?? $logModule;
        }

        // a module whose screen *is* the flat Settings form is changed by that form,
        // which logs the whole save as "update"
        if (($m['route'] ?? null) === 'settings.index') {
            $actions[] = 'update';
        }
        if ($module === 'property') {
            array_push($actions, 'update', 'add_property'); // the hotel settings form and Add property
        }
        if ($module === 'gst') {
            $actions[] = 'vat_registration';
        }

        return [
            'modules' => array_values(array_unique(array_filter($modules))),
            'actions' => array_values(array_unique(array_filter($actions))),
        ];
    }

    /** Does this audit row record a change to that module's configuration? */
    public static function isChangeFor($row, array $spec): bool
    {
        $action = (string) $row->action;
        $module = (string) $row->module;

        if ($module === 'settings' && in_array($action, $spec['actions'], true)) {
            return true;
        }
        if (! in_array($module, $spec['modules'], true)) {
            return false;
        }

        // the module's own screen style: `settings`, a named variant such as
        // `wht_settings`, or the action the registry declared (`gl_mapping`)
        return $action === 'settings' || str_ends_with($action, 'settings') || in_array($action, $spec['actions'], true);
    }

    /**
     * Every log row that could be a configuration change, whichever convention wrote it.
     *
     * The filter has to be the union of every module's spec: a module that logs under an
     * action of its own (`accounts` / `gl_mapping`) would be dropped by a narrower
     * `action like '%settings'` before `isChangeFor()` ever saw the row.
     */
    private static function trailQuery()
    {
        $modules = $actions = [];
        foreach (array_keys(config('settings.modules', [])) as $key) {
            $spec = self::logSpec((string) $key);
            $modules = array_merge($modules, $spec['modules']);
            $actions = array_merge($actions, $spec['actions']);
        }
        $modules = array_values(array_unique($modules));
        $actions = array_values(array_unique($actions));

        return ActivityLog::with('user:id,name')->where(function ($q) use ($modules, $actions) {
            $q->where(fn ($s) => $s->where('module', 'settings')->whereIn('action', $actions))
                ->orWhere(fn ($m) => $m->whereIn('module', $modules)
                    ->where(fn ($a) => $a->where('action', 'settings')
                        ->orWhere('action', 'like', '%settings')
                        ->orWhereIn('action', $actions)));
        });
    }

    /** Restrict the activity query to the same conventions as isChangeFor(). */
    private static function moduleTrailQuery(array $spec)
    {
        return ActivityLog::with('user:id,name')->where(function ($q) use ($spec) {
            $q->where(fn ($s) => $s->where('module', 'settings')->whereIn('action', $spec['actions']))
                ->orWhere(fn ($m) => $m->whereIn('module', $spec['modules'])
                    ->where(fn ($a) => $a->where('action', 'settings')
                        ->orWhere('action', 'like', '%settings')
                        ->orWhereIn('action', $spec['actions'])));
        });
    }

    /** The configuration changes on one module, newest first. */
    public static function historyFor(string $module, int $take = 12): Collection
    {
        return self::moduleTrailQuery(self::logSpec($module))->latest('id')->take($take)
            ->get(['id', 'module', 'action', 'description', 'user_id', 'created_at']);
    }

    /** Last change on each module, from across both logging conventions. */
    public static function lastChanged(): array
    {
        // Matching depends only on module/action. The newest row for each pair is
        // sufficient, even when another module has thousands of newer changes.
        $ids = self::trailQuery()->select('module', 'action')->selectRaw('MAX(id) as latest_id')
            ->groupBy('module', 'action')->pluck('latest_id');
        $rows = ActivityLog::with('user:id,name')->whereIn('id', $ids)->latest('id')
            ->get(['id', 'module', 'action', 'description', 'user_id', 'created_at']);

        $map = [];
        foreach (array_keys(config('settings.modules', [])) as $key) {
            $spec = self::logSpec((string) $key);
            foreach ($rows as $r) {
                if (self::isChangeFor($r, $spec)) {
                    $map[$key] = ['at' => $r->created_at, 'by' => $r->user?->name ?? 'System', 'what' => $r->description];
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * What this property has decided: the settings it moved off a built-in default, and the
     * settings it filled in where the code has no default to move off. Everything else is
     * running on the value the code ships with, which is not a decision anybody made here —
     * and the difference matters when a configuration is handed over or audited.
     */
    public static function changed(?string $module = null): array
    {
        $last = self::lastChanged();
        $drifted = $chosen = [];
        $modules = [];

        foreach (self::modules() as $key => $m) {
            if ($module !== null && $key !== $module) {
                continue;
            }
            $hit = [];
            foreach ($m['keys'] as $k) {
                if (! $k['set']) {
                    continue;
                }
                if ($k['changed']) {
                    $hit[] = $k;
                    $drifted[] = self::decision($m, $k, $last[$key] ?? null);
                } elseif ($k['default'] === null && ! $k['secret']) {
                    $hit[] = $k;
                    $chosen[] = self::decision($m, $k, $last[$key] ?? null);
                }
            }
            if ($hit) {
                $modules[$key] = $m['label'];
            }
        }

        return [
            'drifted' => $drifted,
            'chosen' => $chosen,
            'modules' => $modules,
            'counts' => [
                'drifted' => count($drifted),
                'chosen' => count($chosen),
                'total' => count($drifted) + count($chosen),
                'modules' => count($modules),
            ],
        ];
    }

    /** One decided setting, decorated with the module that owns it and its last change. */
    private static function decision(array $m, array $k, ?array $last): array
    {
        return [
            'module' => $m['key'], 'module_label' => $m['label'], 'group' => $m['group'],
            'route' => $m['route'], 'perm' => $m['perm'], 'reachable' => $m['reachable'],
            'key' => $k['key'], 'raw' => $k['raw'], 'label' => $k['label'], 'value' => $k['value'],
            'default' => $k['default_show'], 'secret' => $k['secret'], 'family' => $k['family'],
            'when' => $last['at'] ?? null, 'by' => $last['by'] ?? null, 'what' => $last['what'] ?? null,
        ];
    }

    /**
     * What the code does when a setting has no built-in default at all.
     *
     * 209 of the 276 keys carry a default in `setting-defaults.php`. The other 51 are read
     * with no second argument, so the runtime hands back null — and the fallback, where
     * there is one, lives at the read site: `?? 'log'` in a service, `?: 30` in a
     * controller, or a default a page prints for itself. `build-setting-fallbacks.mjs`
     * reads those and this reads its output, so "never set" can be said out loud.
     */
    public static function fallbacks(): array
    {
        return config('setting-fallbacks.fallbacks', []);
    }

    /** The fallback for one key, or null when the code has none to offer. */
    public static function fallbackFor(string $key): ?array
    {
        return self::fallbacks()[$key] ?? null;
    }

    /**
     * The sentence a screen prints for a setting nobody has set and whose code ships no
     * default: what actually comes back. Three honest answers, and never a guess.
     */
    public static function unsetShow(string $key): ?string
    {
        $f = self::fallbackFor($key);
        if ($f === null) {
            return null;
        }

        $service = $f['service'] ?? null;
        $view = $f['view'] ?? null;
        $where = isset($f['service_file']) ? basename($f['service_file'], '.php') : null;
        $page = isset($f['view_file']) ? basename($f['view_file'], '.php') : null;

        if ($service !== null) {
            $text = 'runs on '.self::show($service).($where ? ' — written in '.$where : '');
            if ($view !== null && $view !== $service) {
                $text .= ' (a page prints '.self::show($view).')';
            }

            return $text;
        }

        if ($view !== null) {
            return 'the service sees null; a page prints '.self::show($view).($page ? ' — '.$page : '');
        }

        return 'nothing — the service reads it bare'.($where ? ' in '.$where : '');
    }

    /**
     * The 51 settings with no built-in default, grouped by what an unset value actually
     * runs on: a literal in the code, a literal only a page prints, or nothing at all.
     * A handover asks this question about every setting it cannot see a value for.
     */
    public static function onNull(?string $module = null): array
    {
        $groups = [
            'code' => ['state' => 'code', 'title' => 'Falls back to a value written in the code',
                'hint' => 'Unset on this property, so the code supplies the value at its read site. Change it here and you take over from that literal.', 'rows' => []],
            'page' => ['state' => 'page', 'title' => 'A page prints a value; the service gets null',
                'hint' => 'The screen shows something reassuring while the service that acts sees nothing. Worth a decision, not a shrug.', 'rows' => []],
            'null' => ['state' => 'null', 'title' => 'Nothing at all',
                'hint' => 'Read with no default, so the service gets null and each call site decides what that means. Unset here is genuinely unset.', 'rows' => []],
        ];
        $conflicting = config('setting-fallbacks.conflicting', []);
        $modules = [];

        foreach (self::modules() as $key => $m) {
            if ($module !== null && $key !== $module) {
                continue;
            }
            foreach ($m['keys'] as $k) {
                if ($k['default'] !== null || $k['secret'] || $k['family']) {
                    continue;
                }
                $f = self::fallbackFor((string) $k['raw']);
                if ($f === null) {
                    continue;
                }
                $state = ($f['service'] ?? null) !== null ? 'code'
                    : (($f['view'] ?? null) !== null ? 'page' : 'null');
                $groups[$state]['rows'][] = [
                    'key' => $k['key'], 'raw' => $k['raw'], 'label' => $k['label'],
                    'module' => $key, 'module_label' => $m['label'], 'group' => $m['group'],
                    'route' => $m['route'], 'perm' => $m['perm'], 'reachable' => $m['reachable'],
                    'set' => $k['set'], 'value' => $k['value'], 'what' => self::unsetShow((string) $k['raw']),
                    'service' => $f['service'] ?? null, 'view' => $f['view'] ?? null,
                    'service_file' => $f['service_file'] ?? null, 'view_file' => $f['view_file'] ?? null,
                    'read_in' => $k['read_in'], 'read_paths' => $k['read_paths'],
                ];
                $modules[$key] = $m['label'];
            }
        }

        $counts = ['code' => count($groups['code']['rows']), 'page' => count($groups['page']['rows']),
            'null' => count($groups['null']['rows']), 'total' => 0, 'modules' => count($modules)];
        $counts['total'] = $counts['code'] + $counts['page'] + $counts['null'];

        return [
            'groups' => array_values($groups),
            'counts' => $counts,
            'modules' => $modules,
            'conflicting' => $module !== null ? [] : $conflicting,
        ];
    }

    /** The last few configuration changes across every module — the hub's audit strip. */
    public static function recent(int $limit = 10): Collection
    {
        return self::trailQuery()->latest('id')->take($limit)
            ->get(['id', 'action', 'description', 'user_id', 'created_at']);
    }

    /** Totals for the strip at the top of the hub. */
    public static function coverage(?array $modules = null): array
    {
        $modules ??= self::modules();
        $keys = 0;
        $set = 0;
        $changed = 0;
        $known = 0;
        foreach ($modules as $m) {
            $keys += $m['total'];
            $set += $m['set'];
            $changed += $m['changed'] ?? 0;
            $known += $m['defaults_known'] ?? 0;
        }

        return [
            'modules' => count($modules),
            'screens' => count(array_filter($modules, fn ($m) => $m['open'])),
            'gaps' => count(array_filter($modules, fn ($m) => ! $m['open'])),
            'keys' => $keys,
            'set' => $set,
            'unset' => $keys - $set,
            'changed' => $changed,
            'defaults_known' => $known,
            'on_null' => count(config('setting-fallbacks.keys', [])),
            'groups' => count(array_unique(array_column($modules, 'group'))),
            'guarded' => count(array_filter($modules, fn ($m) => $m['rules'] > 0)),
            'map_gap' => self::mapGapCount(),
        ];
    }

    /** Modules grouped for the hub, filtered by a free-text search. */
    public static function grouped(?string $q = null): array
    {
        $modules = self::modules();
        if ($q !== null && trim($q) !== '') {
            $needle = mb_strtolower(trim($q));
            $modules = array_filter($modules, function ($m) use ($needle) {
                $hay = mb_strtolower($m['label'].' '.$m['brief'].' '.$m['key']);
                foreach ($m['keys'] as $k) {
                    $hay .= ' '.mb_strtolower($k['label'].' '.$k['raw']);
                }

                return str_contains($hay, $needle);
            });
        }

        $groups = [];
        foreach (config('settings.groups', []) as $g) {
            $in = array_values(array_filter($modules, fn ($m) => $m['group'] === $g));
            if ($in) {
                $groups[$g] = $in;
            }
        }
        // anything whose group is not in the order list still has to appear
        foreach ($modules as $m) {
            if (! isset($groups[$m['group']]) && ! in_array($m['group'], config('settings.groups', []), true)) {
                $groups[$m['group']][] = $m;
            }
        }

        return $groups;
    }

    /**
     * What this property's configuration contradicts — see App\Support\ConfigurationChecks.
     *
     * A finding is decorated with the module it belongs to and that module's screen, so a
     * reader can go and settle it where the value is actually owned. Blockers sort first:
     * a property that advertises something it cannot deliver outranks a policy choice.
     */
    public static function advisories(?string $module = null): array
    {
        $all = ConfigurationChecks::run();
        if ($module !== null) {
            $all = array_values(array_filter($all, fn ($f) => $f['module'] === $module));
        }

        $mods = self::modules();
        $rank = ['blocker' => 0, 'warning' => 1, 'note' => 2];
        $out = [];
        foreach ($all as $f) {
            $m = $mods[$f['module']] ?? null;
            $f['module_label'] = $m['label'] ?? ucfirst((string) $f['module']);
            $f['route'] = $m['route'] ?? null;
            $f['perm'] = $m['perm'] ?? null;
            $f['severity_label'] = ConfigurationChecks::SEVERITIES[$f['severity']] ?? $f['severity'];
            $f['keys_text'] = implode(' · ', $f['keys'] ?? []);
            $out[] = $f;
        }

        usort($out, fn ($a, $b) => [$rank[$a['severity']] ?? 9, $a['title']] <=> [$rank[$b['severity']] ?? 9, $b['title']]);

        return $out;
    }

    /** Findings by severity, for the badge on the hub. */
    public static function advisoryCounts(?array $findings = null): array
    {
        $findings ??= self::advisories();
        $counts = ['blocker' => 0, 'warning' => 0, 'note' => 0, 'total' => count($findings)];
        foreach ($findings as $f) {
            $counts[$f['severity']] = ($counts[$f['severity']] ?? 0) + 1;
        }

        return $counts;
    }

    /** Modules with no screen yet — the worklist behind the hub. */
    public static function gaps(): array
    {
        return array_values(array_filter(self::modules(), fn ($m) => ! $m['open']));
    }

    /** Every register row, flat — what the CSV and the printed sheet export. */
    public static function rows(): array
    {
        $rows = [];
        foreach (self::modules() as $m) {
            if (! $m['keys']) {
                $rows[] = ['group' => $m['group'], 'module' => $m['label'], 'setting' => '—', 'key' => '',
                    'value' => '', 'default' => '', 'state' => $m['open'] ? 'screen only' : 'no screen', 'screen' => $m['route'] ?? '—', 'read_in' => '—'];

                continue;
            }
            foreach ($m['keys'] as $k) {
                $rows[] = ['group' => $m['group'], 'module' => $m['label'], 'setting' => $k['label'], 'key' => $k['key'],
                    'value' => $k['value'] ?? '', 'default' => $k['default_show'] ?? '',
                    'state' => $k['secret'] ? ($k['set'] ? 'credential set' : 'credential not set')
                        : ($k['changed'] ? 'changed' : ($k['set'] ? 'set' : 'default')),
                    'screen' => $m['route'] ?? '—', 'read_in' => $k['read_paths'] ?: '—'];
            }
        }

        return $rows;
    }

    /** Neutralise formula-like cells for spreadsheets, while preserving ordinary numbers. */
    private static function csvSafe($value): string
    {
        $value = (string) $value;

        return ! is_numeric($value) && preg_match('/^(?:[\x00-\x20]*[=+\-@]|[\t\r\n])/', $value)
            ? "'".$value : $value;
    }

    /** Spreadsheet-safe CSV; BOM lets GH₵ and accented text survive. */
    public static function csv(): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, ['Group', 'Module', 'Setting', 'Key', 'Value', 'Built-in default', 'State', 'Screen', 'Read in']);
        foreach (self::rows() as $r) {
            fputcsv($fh, array_map([self::class, 'csvSafe'], [$r['group'], $r['module'], $r['setting'], $r['key'], $r['value'], $r['default'], $r['state'], $r['screen'], $r['read_in']]), ',', '"', '');
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);

        return "\xEF\xBB\xBF".$out;
    }
}
