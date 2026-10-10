<?php

namespace App\Services;

use App\Models\Department;
use App\Models\MaintenanceRequest;
use App\Models\QaAction;
use App\Models\QaAudit;
use App\Models\QaAuditItem;
use App\Models\QaStandard;
use App\Models\QaTemplate;
use App\Models\Setting;
use App\Models\SopDocument;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * v6.5 Quality assurance & brand standards.
 *  – Standards library (weighted 1–5, critical flag, SOP link) and checklists (templates) built from it, with a
 *    frequency and a pass mark; checklists falling due appear on the dashboard.
 *  – Audits snapshot the checklist when scheduled; the auditor records pass / fail / n/a with notes, then completes:
 *    score = points passed ÷ points applicable; a critical failure fails the audit (setting); failures that also failed on
 *    the previous audit of the same checklist are repeat findings. Every failure raises a corrective action.
 *  – Corrective actions: owner, due date (critical sooner), root cause, plan, optional engineering ticket; open → in
 *    progress → done → verified by quality – never by the person who owns or completed it – or reopened with a note.
 */
class QaService
{
    protected static array $memo = [];

    public const DEFAULTS = ['qa_pass_mark' => '85', 'qa_target_score' => '90', 'qa_critical_autofail' => '1', 'qa_action_days' => '14', 'qa_critical_action_days' => '2'];

    public static function flush(): void
    {
        self::$memo = [];
    }

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
    }

    public static function cfg(): array
    {
        return self::$memo['cfg'.Tenant::id()] ??= [
            'pass_mark' => (float) setting('qa_pass_mark', '85'),
            'target_score' => (float) setting('qa_target_score', '90'),
            'critical_autofail' => setting('qa_critical_autofail', '1') === '1',
            'action_days' => (int) setting('qa_action_days', '14'),
            'critical_action_days' => (int) setting('qa_critical_action_days', '2'),
        ];
    }

    public static function saveSettings(array $d): void
    {
        $pm = $d['pass_mark'] ?? null;
        self::need(is_numeric($pm) && $pm >= 50 && $pm <= 100, 'The default pass mark is between 50 and 100 %.');
        $ts = $d['target_score'] ?? null;
        self::need(is_numeric($ts) && $ts >= 50 && $ts <= 100, 'The target score is between 50 and 100 %.');
        $ad = (int) ($d['action_days'] ?? 0);
        self::need($ad >= 1 && $ad <= 90, 'Corrective actions are due in 1 to 90 days.');
        $cd = (int) ($d['critical_action_days'] ?? 0);
        self::need($cd >= 1 && $cd <= $ad, 'Critical actions are due in 1 day up to the normal action period.');
        foreach (['qa_pass_mark' => (string) round((float) $pm, 1), 'qa_target_score' => (string) round((float) $ts, 1), 'qa_critical_autofail' => ! empty($d['critical_autofail']) ? '1' : '0',
            'qa_action_days' => (string) $ad, 'qa_critical_action_days' => (string) $cd] as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        self::flush();
        ActivityLogger::log('qa', 'settings', 'Quality assurance settings updated');
    }

    // ------------------------------------------------------------------ access

    public static function canAudit(?User $u): bool
    {
        return $u && ($u->hasPermission('qa.audit') || $u->hasPermission('qa.manage'));
    }

    public static function canManage(?User $u): bool
    {
        return (bool) $u?->hasPermission('qa.manage');
    }

    protected static function need(bool $ok, string $msg = 'You do not have permission to do that.'): void
    {
        if (! $ok) {
            throw new RuntimeException($msg);
        }
    }

    /** Active staff of this hotel (action owners). */
    public static function people(): Collection
    {
        return User::where('hotel_id', hotel()->id)->where('is_active', true)->orderBy('name')->get();
    }

    public static function auditors(): Collection
    {
        return self::people()->filter(fn ($u) => self::canAudit($u))->values();
    }

    protected static function staff($id): ?User
    {
        return $id ? User::where('hotel_id', hotel()->id)->where('is_active', true)->find((int) $id) : null;
    }

    // ------------------------------------------------------------------ standards & checklists

    public static function saveStandard(array $d, ?QaStandard $s, User $u): QaStandard
    {
        self::need(self::canManage($u), 'Only quality managers maintain the standards library.');
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        self::need(preg_match('/^[A-Z0-9][A-Z0-9\-\.]{1,19}$/', $code) === 1, 'The code is 2–20 letters, digits, dots or dashes (e.g. FO-01).');
        self::need(! QaStandard::where('code', $code)->when($s, fn ($q) => $q->whereKeyNot($s->id))->exists(), "Standard {$code} already exists.");
        $title = trim((string) ($d['title'] ?? ''));
        self::need($title !== '', 'Describe the standard.');
        $area = trim((string) ($d['area'] ?? ''));
        self::need($area !== '', 'Give the area (e.g. Arrival, Guest room, Restaurant service).');
        $w = (int) ($d['weight'] ?? 0);
        self::need($w >= 1 && $w <= 5, 'The weight is 1 to 5 points.');
        self::need(empty($d['department_id']) || Department::whereKey((int) $d['department_id'])->exists(), 'Choose a department of this hotel.');
        self::need(empty($d['sop_document_id']) || SopDocument::whereKey((int) $d['sop_document_id'])->exists(), 'Choose an SOP of this hotel.');
        $data = ['code' => $code, 'title' => mb_substr($title, 0, 200), 'area' => mb_substr($area, 0, 60), 'weight' => $w, 'is_critical' => ! empty($d['is_critical']),
            'description' => ($d['description'] ?? null) ?: null, 'department_id' => ((int) ($d['department_id'] ?? 0)) ?: null,
            'sop_document_id' => ((int) ($d['sop_document_id'] ?? 0)) ?: null, 'is_active' => ! array_key_exists('is_active', $d) || ! empty($d['is_active'])];
        if ($s) {
            $s->update($data);
        } else {
            $s = QaStandard::create($data + ['sort' => (int) QaStandard::max('sort') + 1]);
        }
        ActivityLogger::log('qa', 'standard', "Standard {$s->code} saved: {$s->title}", $s);

        return $s;
    }

    public static function saveTemplate(array $d, ?QaTemplate $t, User $u): QaTemplate
    {
        self::need(self::canManage($u), 'Only quality managers maintain checklists.');
        $name = trim((string) ($d['name'] ?? ''));
        self::need($name !== '', 'Name the checklist.');
        $type = array_key_exists($d['type'] ?? '', QaTemplate::TYPES) ? $d['type'] : 'internal';
        $freq = ($d['frequency_days'] ?? '') === '' || $d['frequency_days'] === null ? null : (int) $d['frequency_days'];
        self::need($freq === null || ($freq >= 1 && $freq <= 365), 'The frequency is 1 to 365 days (blank = on demand).');
        $pm = ($d['pass_mark'] ?? '') === '' ? self::cfg()['pass_mark'] : $d['pass_mark'];
        self::need(is_numeric($pm) && $pm >= 50 && $pm <= 100, 'The pass mark is between 50 and 100 %.');
        self::need(empty($d['department_id']) || Department::whereKey((int) $d['department_id'])->exists(), 'Choose a department of this hotel.');
        $ids = collect((array) ($d['standard_ids'] ?? []))->map(fn ($v) => (int) $v)->filter()->unique();
        $stds = QaStandard::where('is_active', true)->whereIn('id', $ids)->orderBy('area')->orderBy('sort')->orderBy('code')->get();
        self::need($stds->count() >= 1, 'Pick at least one active standard.');
        self::need($stds->count() === $ids->count(), 'Some standards are inactive or not of this hotel.');

        return DB::transaction(function () use ($t, $name, $type, $freq, $pm, $d, $stds) {
            $data = ['name' => mb_substr($name, 0, 120), 'type' => $type, 'frequency_days' => $freq, 'pass_mark' => round((float) $pm, 2),
                'department_id' => ((int) ($d['department_id'] ?? 0)) ?: null, 'description' => ($d['description'] ?? null) ?: null,
                'is_active' => ! array_key_exists('is_active', $d) || ! empty($d['is_active'])];
            $t ? $t->update($data) : $t = QaTemplate::create($data);
            $t->standards()->sync($stds->values()->mapWithKeys(fn ($s, $i) => [$s->id => ['sort' => $i + 1]])->all());
            ActivityLogger::log('qa', 'template', "Checklist \"{$t->name}\" saved ({$stds->count()} standards)", $t);

            return $t;
        });
    }

    // ------------------------------------------------------------------ audits

    /** Schedule an audit: the checklist is snapshotted now, so later library changes never alter it. */
    public static function schedule(QaTemplate $t, array $d, User $u): QaAudit
    {
        self::need(self::canAudit($u), 'You cannot schedule audits.');
        self::need($t->is_active, 'This checklist is inactive.');
        $stds = $t->standards()->where('is_active', true)->get();
        self::need($stds->isNotEmpty(), 'This checklist has no active standards.');
        $date = ! empty($d['scheduled_for']) ? rescue(fn () => Carbon::parse($d['scheduled_for'])->startOfDay(), null, false) : null;
        self::need((bool) $date, 'Choose the audit date.');
        $external = in_array($t->type, ['mystery', 'brand'], true);
        // mystery guest / brand reports arrive after the visit – they may be entered up to 90 days back
        self::need($date->gte(bdate()->copy()->startOfDay()->subDays($external ? 90 : 0)), $external ? 'The visit can be at most 90 days ago.' : 'Internal audits are scheduled from today.');
        self::need($date->lte(bdate()->copy()->addYear()), 'Schedule at most a year ahead.');
        $auditor = self::staff($d['auditor_id'] ?? null);
        self::need(empty($d['auditor_id']) || ($auditor && self::canAudit($auditor)), 'The auditor must be a member of staff allowed to audit.');
        $ext = trim((string) ($d['external_auditor'] ?? ''));
        if (! $external) {
            $auditor ??= $u;
            $ext = '';
        } else {
            self::need($ext !== '' || $auditor, 'Name the mystery guest / brand auditor (or pick a member of staff who enters the report).');
            $auditor ??= $u;   // the person who enters the report
        }

        return DB::transaction(function () use ($t, $d, $u, $date, $auditor, $ext, $stds) {
            $a = QaAudit::create(['number' => Numbering::next('qa_audit'), 'qa_template_id' => $t->id, 'type' => $t->type, 'department_id' => $t->department_id,
                'title' => mb_substr(trim((string) ($d['title'] ?? '')) ?: $t->name, 0, 150), 'auditor_id' => $auditor?->id, 'external_auditor' => $ext ?: null,
                'scheduled_for' => $date->toDateString(), 'status' => 'scheduled', 'pass_mark' => $t->pass_mark, 'created_by' => $u->id]);
            foreach ($stds->values() as $i => $s) {
                $a->items()->create(['qa_standard_id' => $s->id, 'code' => $s->code, 'area' => $s->area, 'title' => $s->title, 'weight' => $s->weight,
                    'is_critical' => $s->is_critical, 'sort' => $i + 1]);
            }
            ActivityLogger::log('qa', 'schedule', "Audit {$a->number} \"{$a->title}\" scheduled for {$date->format('d M Y')} ({$a->auditorName()})", $a);

            return $a;
        });
    }

    public static function canConduct(QaAudit $a, ?User $u): bool
    {
        return $u && in_array($a->status, ['scheduled', 'in_progress'], true) && ($a->auditor_id === $u->id || self::canManage($u));
    }

    /**
     * Record results (item id => pass | fail | na, notes) and optionally complete the audit. Completing scores it, marks
     * repeat findings and raises a corrective action for every failure.
     */
    public static function record(QaAudit $a, array $results, array $notes, User $u, bool $complete = false, ?string $summary = null): QaAudit
    {
        self::need(self::canConduct($a, $u), $a->status === 'completed' ? 'This audit is already completed.' : 'Only the auditor or a quality manager records this audit.');
        $items = $a->items()->get()->keyBy('id');

        return DB::transaction(function () use ($a, $results, $notes, $u, $complete, $summary, $items) {
            foreach ($items as $id => $it) {
                $r = $results[$id] ?? $it->result;
                $r = in_array($r, ['pass', 'fail', 'na'], true) ? $r : null;
                $n = array_key_exists($id, $notes) ? (trim((string) $notes[$id]) ?: null) : $it->note;
                if ($r !== $it->result || $n !== $it->note) {
                    $it->update(['result' => $r, 'note' => $n !== null ? mb_substr($n, 0, 500) : null]);
                }
            }
            if ($a->status === 'scheduled') {
                $a->update(['status' => 'in_progress', 'started_at' => now()]);
            }
            if ($summary !== null) {
                $a->update(['summary' => trim($summary) ?: null]);
            }
            if ($complete) {
                self::complete($a->fresh(), $u);
            }

            return $a->fresh();
        });
    }

    protected static function complete(QaAudit $a, User $u): void
    {
        $items = $a->items()->get();
        $open = $items->whereNull('result')->count();
        self::need($open === 0, "{$open} item(s) still have no result.");
        $fails = $items->where('result', 'fail');
        foreach ($fails as $it) {
            if ($it->is_critical || $it->weight >= 4) {
                self::need($it->note !== null, "Add a note to the failed item {$it->code} – critical and high-weight failures need the evidence.");
            }
        }
        $applicable = $items->whereIn('result', ['pass', 'fail']);
        self::need($applicable->isNotEmpty(), 'At least one item must be pass or fail – an audit of only n/a items has no score.');
        $possible = (float) $applicable->sum('weight');
        $earned = (float) $items->where('result', 'pass')->sum('weight');
        $pct = round($earned / $possible * 100, 2);
        // repeat findings: failed again after failing on the previous completed audit of this checklist
        $prev = QaAudit::where('qa_template_id', $a->qa_template_id)->where('status', 'completed')->whereKeyNot($a->id)->orderByDesc('completed_at')->orderByDesc('id')->first();
        $prevFails = $prev ? $prev->items()->where('result', 'fail')->pluck('qa_standard_id')->filter()->all() : [];
        $repeat = 0;
        foreach ($fails as $it) {
            $is = $it->qa_standard_id && in_array($it->qa_standard_id, $prevFails, true);
            $it->update(['is_repeat' => $is]);
            $repeat += $is ? 1 : 0;
        }
        $crit = $fails->where('is_critical', true)->count();
        $passed = $pct >= (float) $a->pass_mark && ! ($crit && self::cfg()['critical_autofail']);
        $a->update(['status' => 'completed', 'completed_at' => now(), 'points_earned' => $earned, 'points_possible' => $possible, 'score_pct' => $pct,
            'fails' => $fails->count(), 'critical_fails' => $crit, 'repeat_fails' => $repeat, 'passed' => $passed]);
        $cfg = self::cfg();
        foreach ($fails as $it) {
            $it->refresh();
            $std = $it->standard;
            QaAction::create(['number' => Numbering::next('qa_action'), 'qa_audit_id' => $a->id, 'qa_audit_item_id' => $it->id, 'qa_standard_id' => $it->qa_standard_id,
                'department_id' => $std?->department_id ?? $a->department_id, 'title' => mb_substr("{$it->code} {$it->title}", 0, 200),
                'priority' => $it->is_critical ? 'critical' : ($it->is_repeat ? 'high' : 'normal'), 'root_cause' => null,
                'action_plan' => $it->note ? 'Finding: '.$it->note : null, 'status' => 'open', 'created_by' => $u->id,
                'due_date' => bdate()->copy()->addDays($it->is_critical ? $cfg['critical_action_days'] : $cfg['action_days'])->toDateString()]);
        }
        ActivityLogger::log('qa', 'complete', "Audit {$a->number} completed: {$pct}% – ".($passed ? 'passed' : 'failed').($crit ? " ({$crit} critical)" : '')
            .", {$fails->count()} finding(s)".($repeat ? ", {$repeat} repeat" : ''), $a);
    }

    public static function cancel(QaAudit $a, string $reason, User $u): void
    {
        self::need(self::canManage($u), 'Only quality managers cancel audits.');
        self::need(in_array($a->status, ['scheduled', 'in_progress'], true), 'Only scheduled or running audits can be cancelled.');
        self::need(trim($reason) !== '', 'Give a reason.');
        $a->update(['status' => 'cancelled', 'cancel_reason' => mb_substr(trim($reason), 0, 255)]);
        ActivityLogger::log('qa', 'cancel', "Audit {$a->number} cancelled: {$reason}", $a);
    }

    /** The department head confirms having seen the result (never the auditor). */
    public static function acknowledge(QaAudit $a, User $u): void
    {
        self::need($a->status === 'completed', 'Only completed audits are acknowledged.');
        self::need($a->acknowledged_at === null, 'Already acknowledged.');
        self::need($a->auditor_id !== $u->id, 'The auditor cannot acknowledge their own audit.');
        $a->update(['acknowledged_by' => $u->id, 'acknowledged_at' => now()]);
        ActivityLogger::log('qa', 'acknowledge', "Audit {$a->number} acknowledged by {$u->name}", $a);
    }

    // ------------------------------------------------------------------ corrective actions

    public static function createAction(array $d, User $u): QaAction
    {
        self::need(self::canAudit($u), 'You cannot raise corrective actions.');
        $title = trim((string) ($d['title'] ?? ''));
        self::need($title !== '', 'Describe what must be corrected.');
        $pr = array_key_exists($d['priority'] ?? '', QaAction::PRIORITIES) ? $d['priority'] : 'normal';
        $due = ! empty($d['due_date']) ? rescue(fn () => Carbon::parse($d['due_date'])->startOfDay(), null, false) : null;
        $due ??= bdate()->copy()->addDays($pr === 'critical' ? self::cfg()['critical_action_days'] : self::cfg()['action_days']);
        self::need($due->gte(bdate()->copy()->startOfDay()), 'The due date cannot be in the past.');
        $owner = self::staff($d['owner_id'] ?? null);
        self::need(empty($d['owner_id']) || $owner, 'The owner must be active staff of this hotel.');
        self::need(empty($d['department_id']) || Department::whereKey((int) $d['department_id'])->exists(), 'Choose a department of this hotel.');
        self::need(empty($d['qa_standard_id']) || QaStandard::whereKey((int) $d['qa_standard_id'])->exists(), 'Choose a standard of this hotel.');
        $x = QaAction::create(['number' => Numbering::next('qa_action'), 'title' => mb_substr($title, 0, 200), 'priority' => $pr, 'due_date' => $due->toDateString(),
            'owner_id' => $owner?->id, 'department_id' => ((int) ($d['department_id'] ?? 0)) ?: null, 'qa_standard_id' => ((int) ($d['qa_standard_id'] ?? 0)) ?: null,
            'root_cause' => ($d['root_cause'] ?? null) ?: null, 'action_plan' => ($d['action_plan'] ?? null) ?: null, 'status' => 'open', 'created_by' => $u->id]);
        ActivityLogger::log('qa', 'action', "Corrective action {$x->number} raised: {$x->title}", $x);

        return $x;
    }

    /** Managers assign / re-plan (owner, due, priority); the owner records root cause & plan and starts the work. */
    public static function updateAction(QaAction $x, array $d, User $u): QaAction
    {
        $mgr = self::canManage($u);
        $own = $x->owner_id === $u->id;
        self::need($mgr || $own, 'Only the owner or a quality manager updates this action.');
        self::need(in_array($x->status, QaAction::OPEN, true), 'Only open actions can be updated.');
        $data = [];
        foreach (['root_cause', 'action_plan'] as $k) {
            if (array_key_exists($k, $d)) {
                $data[$k] = trim((string) $d[$k]) ?: null;
            }
        }
        if ($mgr) {
            if (array_key_exists('owner_id', $d)) {
                $o = self::staff($d['owner_id']);
                self::need(empty($d['owner_id']) || $o, 'The owner must be active staff of this hotel.');
                $data['owner_id'] = $o?->id;
            }
            if (! empty($d['due_date'])) {
                $due = rescue(fn () => Carbon::parse($d['due_date'])->startOfDay(), null, false);
                self::need($due && ($due->gte(bdate()->copy()->startOfDay()) || $due->equalTo($x->due_date)), 'The due date cannot be moved into the past.');
                $data['due_date'] = $due->toDateString();
            }
            if (array_key_exists($d['priority'] ?? '', QaAction::PRIORITIES)) {
                $data['priority'] = $d['priority'];
            }
        }
        if (! empty($d['start']) && $x->status === 'open') {
            self::need(($data['owner_id'] ?? $x->owner_id) !== null, 'Assign an owner first.');
            $data['status'] = 'in_progress';
        }
        $x->update($data);
        ActivityLogger::log('qa', 'action_update', "Corrective action {$x->number} updated", $x);

        return $x;
    }

    public static function completeAction(QaAction $x, string $note, User $u): QaAction
    {
        self::need($x->owner_id === $u->id || self::canManage($u), 'Only the owner or a quality manager completes this action.');
        self::need(in_array($x->status, QaAction::OPEN, true), 'Only open actions can be completed.');
        self::need($x->owner_id !== null, 'Assign an owner first.');
        self::need(trim($note) !== '', 'Say what was done.');
        $x->update(['status' => 'done', 'completion_note' => trim($note), 'completed_at' => now(), 'completed_by' => $u->id]);
        ActivityLogger::log('qa', 'action_done', "Corrective action {$x->number} done – awaiting verification", $x);

        return $x;
    }

    /** Quality verifies the fix on the floor (four eyes: never the owner or whoever completed it) or reopens it. */
    public static function verifyAction(QaAction $x, bool $ok, ?string $note, User $u): QaAction
    {
        self::need(self::canManage($u), 'Only quality managers verify corrective actions.');
        self::need($x->status === 'done', 'Only completed actions are verified.');
        self::need($x->owner_id !== $u->id && $x->completed_by !== $u->id, 'You cannot verify an action you own or completed – another manager must check it.');
        if ($ok) {
            $x->update(['status' => 'verified', 'verified_by' => $u->id, 'verified_at' => now(), 'verify_note' => trim((string) $note) ?: null]);
        } else {
            self::need(trim((string) $note) !== '', 'Say why the fix is not accepted.');
            $x->update(['status' => 'open', 'verify_note' => mb_substr(trim($note), 0, 255), 'reopened' => min(255, $x->reopened + 1), 'completed_at' => null, 'completed_by' => null,
                'due_date' => max($x->due_date->toDateString(), bdate()->copy()->addDays(self::cfg()['critical_action_days'])->toDateString())]);
        }
        ActivityLogger::log('qa', $ok ? 'action_verified' : 'action_reopened', "Corrective action {$x->number} ".($ok ? 'verified' : 'reopened: '.$note), $x);

        return $x;
    }

    public static function cancelAction(QaAction $x, string $reason, User $u): void
    {
        self::need(self::canManage($u), 'Only quality managers cancel actions.');
        self::need(in_array($x->status, ['open', 'in_progress', 'done'], true), 'This action is already closed.');
        self::need(trim($reason) !== '', 'Give a reason.');
        $x->update(['status' => 'cancelled', 'verify_note' => mb_substr(trim($reason), 0, 255)]);
        ActivityLogger::log('qa', 'action_cancel', "Corrective action {$x->number} cancelled: {$reason}", $x);
    }

    /** Hand the physical fix to engineering: a maintenance ticket linked to the action. */
    public static function raiseTicket(QaAction $x, array $d, User $u): MaintenanceRequest
    {
        self::need($x->owner_id === $u->id || self::canManage($u), 'Only the owner or a quality manager raises a ticket.');
        self::need(in_array($x->status, QaAction::OPEN, true), 'Only open actions can raise a ticket.');
        self::need($x->maintenance_request_id === null, 'A maintenance ticket is already linked.');
        $cat = in_array($d['category'] ?? '', ['electrical', 'plumbing', 'ac', 'carpentry', 'it', 'civil', 'general'], true) ? $d['category'] : 'general';
        $t = MaintenanceRequest::create(['ticket_no' => Numbering::next('ticket'), 'category' => $cat, 'title' => mb_substr('QA '.$x->number.': '.$x->title, 0, 150),
            'description' => trim(($x->action_plan ?? '')."\n".($d['location'] ?? '')) ?: null, 'location' => ($d['location'] ?? null) ?: null,
            'priority' => ['critical' => 'urgent', 'high' => 'high', 'normal' => 'normal', 'low' => 'low'][$x->priority] ?? 'normal',
            'due_date' => $x->due_date->toDateString(), 'status' => 'open', 'reported_by' => $u->id]);
        $x->update(['maintenance_request_id' => $t->id, 'status' => $x->status === 'open' && $x->owner_id ? 'in_progress' : $x->status]);
        ActivityLogger::log('qa', 'ticket', "Maintenance ticket {$t->ticket_no} raised for corrective action {$x->number}", $x);

        return $t;
    }

    // ------------------------------------------------------------------ analytics

    protected static function completed(Carbon $from, Carbon $to)
    {
        return QaAudit::where('status', 'completed')->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
    }

    public static function kpis(Carbon $from, Carbon $to): array
    {
        $a = self::completed($from, $to)->get(['score_pct', 'passed', 'critical_fails', 'repeat_fails', 'fails', 'type']);
        $closed = QaAction::whereIn('status', ['done', 'verified'])->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get(['completed_at', 'due_date']);

        return ['audits' => $a->count(), 'avg' => $a->count() ? round((float) $a->avg('score_pct'), 1) : null, 'passed' => $a->where('passed', true)->count(),
            'pass_rate' => $a->count() ? round($a->where('passed', true)->count() / $a->count() * 100, 1) : null, 'findings' => (int) $a->sum('fails'),
            'critical' => (int) $a->sum('critical_fails'), 'repeat' => (int) $a->sum('repeat_fails'),
            'mystery_avg' => ($m = $a->where('type', 'mystery'))->count() ? round((float) $m->avg('score_pct'), 1) : null,
            'open_actions' => QaAction::whereIn('status', QaAction::OPEN)->count(),
            'overdue_actions' => QaAction::whereIn('status', QaAction::OPEN)->where('due_date', '<', bdate()->toDateString())->count(),
            'to_verify' => QaAction::where('status', 'done')->count(),
            'closed' => $closed->count(), 'on_time' => $closed->count() ? round($closed->filter(fn ($x) => $x->completed_at->toDateString() <= $x->due_date->toDateString())->count() / $closed->count() * 100, 1) : null,
            'target' => self::cfg()['target_score']];
    }

    /** Monthly average score and audit count for the last $months months (incl. the current one). */
    public static function trend(int $months = 6): Collection
    {
        $out = collect();
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = bdate()->copy()->startOfMonth()->subMonthsNoOverflow($i);
            $a = self::completed($m, $m->copy()->endOfMonth())->get(['score_pct', 'passed']);
            $out->push(['month' => $m, 'audits' => $a->count(), 'avg' => $a->count() ? round((float) $a->avg('score_pct'), 1) : null,
                'pass_rate' => $a->count() ? round($a->where('passed', true)->count() / $a->count() * 100, 1) : null]);
        }

        return $out;
    }

    /** Per department: audits, average, last score, change vs the previous equal period, open / overdue actions. */
    public static function departmentScores(Carbon $from, Carbon $to): Collection
    {
        $len = max(1, (int) $from->diffInDays($to) + 1);
        $prevFrom = $from->copy()->subDays($len);
        $prevTo = $from->copy()->subDay();
        $cur = self::completed($from, $to)->orderBy('completed_at')->get()->groupBy(fn ($a) => (int) $a->department_id);
        $prev = self::completed($prevFrom, $prevTo)->get()->groupBy(fn ($a) => (int) $a->department_id);
        $acts = QaAction::whereIn('status', QaAction::OPEN)->get(['department_id', 'due_date'])->groupBy(fn ($x) => (int) $x->department_id);
        $depts = Department::orderBy('name')->get()->keyBy('id');

        return collect($cur->keys())->merge($acts->keys())->unique()->map(function ($id) use ($cur, $prev, $acts, $depts) {
            $c = $cur[$id] ?? collect();
            $p = $prev[$id] ?? collect();
            $avg = $c->count() ? round((float) $c->avg('score_pct'), 1) : null;
            $pavg = $p->count() ? round((float) $p->avg('score_pct'), 1) : null;
            $x = $acts[$id] ?? collect();

            return ['id' => $id, 'name' => $id ? ($depts[$id]->name ?? '—') : 'Whole hotel', 'audits' => $c->count(), 'avg' => $avg, 'prev' => $pavg,
                'change' => $avg !== null && $pavg !== null ? round($avg - $pavg, 1) : null, 'last' => $c->last()?->score_pct !== null ? (float) $c->last()->score_pct : null,
                'last_at' => $c->last()?->completed_at, 'failed' => $c->where('passed', false)->count(),
                'open' => $x->count(), 'overdue' => $x->filter(fn ($a) => $a->due_date->lt(bdate()->copy()->startOfDay()))->count()];
        })->sortBy(fn ($r) => $r['avg'] ?? 999)->values();
    }

    /** Standards failing most often: fails, checks, fail rate, repeats, critical flag, open actions. */
    public static function topFailures(Carbon $from, Carbon $to, int $limit = 10): Collection
    {
        $ids = self::completed($from, $to)->pluck('id');
        $rows = QaAuditItem::whereIn('qa_audit_id', $ids)->whereIn('result', ['pass', 'fail'])->whereNotNull('qa_standard_id')
            ->selectRaw("qa_standard_id, SUM(result = 'fail') fails, COUNT(*) checks, SUM(is_repeat) repeats")->groupBy('qa_standard_id')->havingRaw("SUM(result = 'fail') > 0")->get();
        $std = QaStandard::with('department')->whereIn('id', $rows->pluck('qa_standard_id'))->get()->keyBy('id');
        $open = QaAction::whereIn('status', QaAction::OPEN)->whereIn('qa_standard_id', $rows->pluck('qa_standard_id'))->selectRaw('qa_standard_id, count(*) n')->groupBy('qa_standard_id')->pluck('n', 'qa_standard_id');

        return $rows->map(fn ($r) => ['standard' => $std[$r->qa_standard_id] ?? null, 'fails' => (int) $r->fails, 'checks' => (int) $r->checks,
            'rate' => round($r->fails / max(1, $r->checks) * 100, 1), 'repeats' => (int) $r->repeats, 'open' => (int) ($open[$r->qa_standard_id] ?? 0)])
            ->filter(fn ($r) => $r['standard'])->sortByDesc(fn ($r) => $r['fails'] * 1000 + $r['rate'])->take($limit)->values();
    }

    /** Checklists with a frequency: last audit, next due date and whether one is already booked. */
    public static function due(): Collection
    {
        return QaTemplate::with('department')->where('is_active', true)->whereNotNull('frequency_days')->orderBy('name')->get()->map(function ($t) {
            $last = QaAudit::where('qa_template_id', $t->id)->where('status', 'completed')->max('completed_at');
            $pending = QaAudit::where('qa_template_id', $t->id)->whereIn('status', ['scheduled', 'in_progress'])->orderBy('scheduled_for')->first();
            $next = $last ? Carbon::parse($last)->startOfDay()->addDays($t->frequency_days) : bdate()->copy()->startOfDay();
            $state = $pending ? ($pending->isOverdue() ? 'late' : 'booked') : ($next->lte(bdate()->copy()->startOfDay()) ? 'due' : 'ok');

            return ['template' => $t, 'last' => $last ? Carbon::parse($last) : null, 'next' => $next, 'pending' => $pending, 'state' => $state];
        });
    }

    public static function alerts(): array
    {
        return self::$memo['alerts'.Tenant::id()] ??= [
            'overdue_actions' => QaAction::whereIn('status', QaAction::OPEN)->where('due_date', '<', bdate()->toDateString())->count(),
            'to_verify' => QaAction::where('status', 'done')->count(),
            'unassigned' => QaAction::whereIn('status', QaAction::OPEN)->whereNull('owner_id')->count(),
            'audits_late' => QaAudit::whereIn('status', ['scheduled', 'in_progress'])->where('scheduled_for', '<', bdate()->toDateString())->count(),
            'checklists_due' => self::due()->where('state', 'due')->count(),
        ];
    }

    /** Badge: overdue actions + late audits, plus actions to verify for quality managers and my own open actions. */
    public static function attention(): int
    {
        if (! can_do('qa.view')) {
            return 0;
        }
        try {
            $a = self::alerts();
            $u = auth()->user();
            $mine = $u ? QaAction::whereIn('status', QaAction::OPEN)->where('owner_id', $u->id)->where('due_date', '<', bdate()->toDateString())->count() : 0;

            return self::canManage($u) ? $a['overdue_actions'] + $a['audits_late'] + $a['to_verify'] : $mine + $a['audits_late'];
        } catch (Throwable) {
            return 0;
        }
    }
}
