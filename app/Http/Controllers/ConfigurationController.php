<?php

namespace App\Http\Controllers;

use App\Services\ActivityLogger;
use App\Models\Setting;
use App\Models\SettingChange;
use App\Services\ConfigurationService;
use Illuminate\Http\Request;

/**
 * Settings › Configuration.
 *
 * The register of every module's configuration surface. It reads; it does not
 * write. Each row links to the screen that owns the setting, so a value is only
 * ever changed where the module enforces its own validation, permission and
 * audit trail.
 *
 * Gated on `settings.view` (read). The screens it links to keep their own
 * `*.manage` permissions — a reader here who cannot manage a module sees it as
 * locked rather than being sent to a 403.
 */
class ConfigurationController extends Controller
{
    public function index(Request $request)
    {
        $query = $request->query('q', '');
        $q = is_string($query) ? mb_substr(trim($query), 0, 150) : '';
        $groups = ConfigurationService::grouped($q !== '' ? $q : null);

        return view('configuration.index', [
            'groups' => $groups,
            'coverage' => ConfigurationService::coverage(),
            // settings the code reads behind a service map that this register does not hold:
            // stated as a number on the sheet, because an unchecked gap is not a clean one
            'mapGap' => ConfigurationService::mapGapCount(),
            'trail' => ConfigurationService::trailCoverage(),
            'gaps' => ConfigurationService::gaps(),
            'findings' => ConfigurationService::advisories(),
            'findingCounts' => ConfigurationService::advisoryCounts(),
            'recent' => ConfigurationService::recent(8),
            'q' => $q,
            'matched' => array_sum(array_map('count', $groups)),
            'hotel' => hotel(),
        ]);
    }

    /** What this property has changed, across every module — the decisions, not the defaults. */
    public function changed()
    {
        return view('configuration.changed', [
            'decisions' => ConfigurationService::changed(),
            'coverage' => ConfigurationService::coverage(),
            'hotel' => hotel(),
        ]);
    }

    /** What an unset setting runs on — the 51 keys the code ships no default for. */
    public function onNull()
    {
        return view('configuration.on-null', [
            'groups' => ConfigurationService::onNull()['groups'],
            'counts' => ConfigurationService::onNull()['counts'],
            'conflicting' => ConfigurationService::onNull()['conflicting'],
            'coverage' => ConfigurationService::coverage(),
            'hotel' => hotel(),
        ]);
    }

    /** What each setting was before — the value trail behind "last changed by". */
    public function history(Request $request)
    {
        $requestedModule = $request->query('module', '');
        $module = is_string($requestedModule) ? trim($requestedModule) : '';
        $module = $module === '' ? null : $module;
        $known = ConfigurationService::module($module ?? '');

        return view('configuration.history', [
            'changes' => ConfigurationService::valueTrail($module, 120),
            'trail' => ConfigurationService::trailCoverage(),
            'module' => $known,
            'modules' => ConfigurationService::modules(),
            'coverage' => ConfigurationService::coverage(),
            'hotel' => hotel(),
        ]);
    }

    /**
     * Put a setting back to the value it had before one recorded change.
     *
     * The only write in this area, and a narrow one: it restores a value the property
     * itself recorded, only for a setting whose rules the register can check
     * (SettingController::KEYS), and it writes through `Setting::updateOrCreate` — so the
     * observer records the restore as a change like any other, with this person's name on it.
     */
    public function restore(int $change)
    {
        $row = SettingChange::query()->find($change);
        abort_if($row === null, 404);

        $key = (string) $row->key;
        $label = ConfigurationService::labels()[$key]['label'] ?? $key;

        // never restore a credential: the trail recorded that it changed, not what it was
        $facts = [
            'key' => $key, 'secret' => (bool) $row->was_secret, 'created' => (bool) $row->created,
            'was' => $row->was_secret ? ConfigurationService::HIDDEN : $row->old_value,
            'was_raw' => $row->was_secret ? null : $row->old_value,
        ];
        $blocked = ConfigurationService::restoreBlocked($facts);
        $problem = $blocked === null ? ConfigurationService::restoreError($key, $row->old_value) : $blocked;

        if ($problem !== null) {
            return back()->with('error', $label.' was not put back: '.$problem.'.');
        }

        Setting::updateOrCreate(['key' => $key], ['value' => $row->old_value]);

        return back()->with('success', $label.' put back to '.ConfigurationService::show($row->old_value).'.');
    }

    public function show(string $module)
    {
        $m = ConfigurationService::module($module);
        abort_if($m === null, 404);
        // both logging conventions, and the module's own log name — see ConfigurationService::logSpec()
        $history = ConfigurationService::historyFor($module);

        return view('configuration.show', [
            'm' => $m,
            'history' => $history,
            // what the values were before — the same trail, narrowed to this module
            'valueTrail' => ConfigurationService::valueTrail($module, 8),
            'findings' => ConfigurationService::advisories($module),
            'hotel' => hotel(),
        ]);
    }

    public function print()
    {
        return view('configuration.print', [
            'groups' => ConfigurationService::grouped(),
            'coverage' => ConfigurationService::coverage(),
            // the same twelve characters the handover sheet carries: two sheets that disagree
            // are two different configurations, and that is worth seeing at a glance
            'fingerprint' => ConfigurationService::fingerprint(),
            'findings' => ConfigurationService::advisories(),
            'findingCounts' => ConfigurationService::advisoryCounts(),
            'hotel' => hotel(),
            'printedAt' => now(),
        ]);
    }

    /**
     * The handover sheet: everything an auditor or an incoming manager needs on one printable
     * page — the decisions, what runs on a value in the code, the findings, what is checked,
     * how many credentials are set, the trail, a fingerprint to compare with last time, and
     * room to sign. Every number on it comes from the same service the screens read.
     */
    public function handover()
    {
        $modules = ConfigurationService::modules();

        return view('configuration.handover', [
            'fingerprint' => ConfigurationService::fingerprint(),
            'decisions' => ConfigurationService::changed(),
            'unset' => ConfigurationService::onNull(),
            'findings' => ConfigurationService::advisories(),
            'findingCounts' => ConfigurationService::advisoryCounts(),
            'coverage' => ConfigurationService::coverage(),
            'credentials' => ConfigurationService::credentialSummary(),
            'guarded' => array_values(array_filter($modules, fn ($m) => ($m['rules'] ?? 0) > 0)),
            'unguarded' => array_values(array_filter($modules, fn ($m) => ($m['rules'] ?? 0) === 0)),
            // named on the sheet as plain text: a printable document should not need a template
            // helper to join a list
            'guardedNames' => implode(' · ', array_column(array_values(array_filter($modules, fn ($m) => ($m['rules'] ?? 0) > 0)), 'label')),
            'unguardedNames' => implode(' · ', array_column(array_values(array_filter($modules, fn ($m) => ($m['rules'] ?? 0) === 0)), 'label')),
            'trail' => ConfigurationService::trailCoverage(),
            'mapGap' => ConfigurationService::mapGapCount(),
            'recentChanges' => ConfigurationService::valueTrail(null, 12),
            'hotel' => hotel(),
            'printedAt' => now(),
        ]);
    }

    public function csv()
    {
        ActivityLogger::log('settings', 'configuration_export', 'Exported the configuration register');

        return response(ConfigurationService::csv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="configuration-register-'.bdate()->toDateString().'.csv"',
        ]);
    }
}
