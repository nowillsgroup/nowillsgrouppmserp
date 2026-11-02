<?php

// End-to-end service check for the operational front-office session lifecycle.
// The whole scenario is rolled back; run with:
// deploy/dev/bin/php artisan tinker --execute="require 'deploy/dev/audit/front_desk_shift_session_check.php';"

use App\Models\ActivityLog;
use App\Models\FrontDeskShiftSession;
use App\Models\Hotel;
use App\Models\LogEntryRead;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\FrontDeskOpsService;
use App\Services\FrontDeskShiftSessionService;
use App\Services\LogBookService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

Tenant::set(Hotel::findOrFail(1));
$staff = User::query()->where('hotel_id', 1)->where('is_active', true)->get();
$outgoing = $staff->first(fn (User $user) => $user->hasPermission('logbook.view') && $user->hasPermission('logbook.write'));
$incoming = $staff->first(fn (User $user) => $user->id !== $outgoing?->id && $user->hasPermission('logbook.view') && $user->hasPermission('logbook.write'));
if (! $outgoing || ! $incoming) {
    throw new RuntimeException('The audit needs two active demo accounts with Desk Operations access.');
}

$guard = Auth::guard();
$originalUser = $guard->user();
$checks = 0;
$assert = function (bool $condition, string $label) use (&$checks) {
    ++$checks;
    echo ($condition ? 'PASS ' : 'FAIL ').$label.PHP_EOL;
    if (! $condition) {
        throw new RuntimeException('Front-desk shift-session audit failed: '.$label);
    }
};

DB::beginTransaction();
try {
    $guard->setUser($outgoing);
    $session = FrontDeskShiftSessionService::start($outgoing, 'Opening desk check completed.');
    $assert($session->status === 'open' && (int) $session->started_by === (int) $outgoing->id, 'staff can start a named operational session');
    $assert($session->shift === FrontDeskOpsService::currentShift(), 'session captures the configured current shift');
    $priorEvent = new ActivityLog(['user_id' => $outgoing->id, 'module' => 'logbook', 'action' => 'prior_session_audit', 'description' => 'Previous shift activity must not appear.']);
    $priorEvent->hotel_id = 1;
    $priorEvent->created_at = $session->started_at->copy()->subSecond();
    $priorEvent->save();

    try {
        FrontDeskShiftSessionService::start($outgoing, null);
        $assert(false, 'duplicate active session is rejected');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'already have an active'), 'duplicate active session is rejected');
    }

    $entry = LogBookService::create(['title' => 'Shift-session linkage audit', 'category' => 'info', 'department' => 'front_office', 'priority' => 'normal']);
    $assert((int) $entry->shift_session_id === (int) $session->id, 'manual log-book entries link to the active session');

    $guard->setUser($incoming);
    ActivityLogger::log('logbook', 'foreign_session_audit', 'Foreign worker activity must not appear in this session feed.');
    $guard->setUser($outgoing);
    ActivityLogger::log('messages', 'restricted_module_audit', 'Messages activity must be hidden from a logbook-only viewer.');
    $activityWorkspace = FrontDeskShiftSessionService::workspace();
    $sessionFeed = $activityWorkspace['sessionActivities'][$session->id] ?? collect();
    $assert($sessionFeed->contains(fn (ActivityLog $event) => $event->description === 'Log book: Shift-session linkage audit')
        && ! $sessionFeed->contains(fn (ActivityLog $event) => str_contains($event->description, 'Foreign worker activity'))
        && ! $sessionFeed->contains(fn (ActivityLog $event) => str_contains($event->description, 'Previous shift activity')),
        'session activity is limited to the starter and the session time window');

    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $request = Request::create('/desk/shift', 'GET');
    $response = $kernel->handle($request);
    $html = (string) $response->getContent();
    $assert($response->getStatusCode() === 200 && str_contains($html, 'Close shift &amp; send hand-over')
        && str_contains($html, e($incoming->name)) && str_contains($html, 'Recent activity during this shift')
        && str_contains($html, 'Log book: Shift-session linkage audit') && str_contains($html, 'Front Office day close-out report')
        && str_contains($html, 'session #'.$session->id) && ! str_contains($html, 'Foreign worker activity'),
        'active session page renders its actor-filtered activity and daily close-out report');
    $kernel->terminate($request, $response);

    $request = Request::create('/desk/logbook?tab=all&session='.$session->id, 'GET');
    $response = $kernel->handle($request);
    $html = (string) $response->getContent();
    $assert($response->getStatusCode() === 200 && str_contains($html, 'Shift-session linkage audit')
        && str_contains($html, 'Showing entries linked to shift session'), 'log book can be filtered to entries from one session');
    $kernel->terminate($request, $response);

    $session = FrontDeskShiftSessionService::closeAndHandOver($session, $outgoing, (int) $incoming->id, 'Audit hand-over summary.');
    $assert($session->status === 'handover_pending' && $session->closed_at !== null, 'closing ends the work session and awaits incoming acknowledgement');
    $assert(is_array($session->handover_snapshot) && array_key_exists('inhouse', $session->handover_snapshot)
        && array_key_exists('wakeups_late', $session->handover_snapshot), 'closing stores an operational snapshot for the incoming shift');
    $postCloseEvent = new ActivityLog(['user_id' => $outgoing->id, 'module' => 'logbook', 'action' => 'post_close_audit', 'description' => 'Post-close activity must not appear.']);
    $postCloseEvent->hotel_id = 1;
    $postCloseEvent->created_at = $session->closed_at->copy()->addSeconds(5);
    $postCloseEvent->save();

    $readOnlyRole = Role::create(['hotel_id' => 1, 'name' => 'Shift session audit reader']);
    $readOnlyRole->permissions()->attach(Permission::where('key', 'logbook.view')->value('id'));
    $limitedViewer = (new User)->forceFill(['id' => $incoming->id, 'hotel_id' => 1, 'role_id' => $readOnlyRole->id, 'is_active' => true]);
    $guard->setUser($limitedViewer);
    $restrictedFeed = FrontDeskShiftSessionService::workspace((int) $incoming->id)['sessionActivities'][$session->id] ?? collect();
    $assert($restrictedFeed->contains(fn (ActivityLog $event) => $event->module === 'logbook')
        && ! $restrictedFeed->contains(fn (ActivityLog $event) => $event->module === 'messages')
        && ! $restrictedFeed->contains(fn (ActivityLog $event) => str_contains($event->description, 'Post-close activity')),
        'incoming activity timeline enforces module permissions and the close-time boundary');
    $guard->setUser($incoming);

    $assert(FrontDeskShiftSessionService::activeForUser((int) $outgoing->id) === null, 'closed session is no longer active for its owner');

    try {
        FrontDeskShiftSessionService::acceptHandover($session, $outgoing);
        $assert(false, 'only the named recipient can acknowledge the hand-over');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'Only the assigned'), 'only the named recipient can acknowledge the hand-over');
    }

    $guard->setUser($incoming);
    $request = Request::create('/desk/shift', 'GET');
    $response = $kernel->handle($request);
    $html = (string) $response->getContent();
    $assert($response->getStatusCode() === 200 && str_contains($html, 'Hand-overs waiting for you')
        && str_contains($html, 'name="confirm_receipt"') && str_contains($html, 'Audit hand-over summary.')
        && str_contains($html, 'Desk status at close') && str_contains($html, 'Recent activity during this shift')
        && str_contains($html, 'Log book: Shift-session linkage audit') && ! str_contains($html, 'Foreign worker activity'),
        'incoming staff sees the hand-over snapshot and the permission-filtered outgoing activity');
    $kernel->terminate($request, $response);

    try {
        FrontDeskShiftSessionService::start($incoming, null);
        $assert(false, 'incoming worker must acknowledge the hand-over before starting');
    } catch (RuntimeException $e) {
        $assert(str_contains($e->getMessage(), 'Acknowledge the hand-over'), 'incoming worker must acknowledge the hand-over before starting');
    }

    $accepted = FrontDeskShiftSessionService::acceptHandover($session, $incoming);
    $assert($accepted->status === 'closed' && (int) $accepted->received_by === (int) $incoming->id, 'incoming staff acknowledgement closes the hand-over record');
    $assert(LogEntryRead::where('log_entry_id', $entry->id)->where('user_id', $incoming->id)->exists(), 'acknowledgement marks session entries as read for the recipient');
    $nextSession = FrontDeskShiftSessionService::start($incoming, 'Accepted the previous shift hand-over.');
    $assert($nextSession->status === 'open' && (int) $nextSession->started_by === (int) $incoming->id, 'incoming staff can start their own session after acknowledgement');

    $readOnlyRole = Role::create(['hotel_id' => 1, 'name' => 'Front Office read-only shift audit']);
    $readOnlyRole->permissions()->attach(Permission::where('key', 'logbook.view')->value('id'));
    $readOnlyUser = (new User)->forceFill(['id' => $outgoing->id, 'hotel_id' => 1, 'role_id' => $readOnlyRole->id,
        'name' => 'Front Office read-only viewer', 'is_active' => true]);
    $guard->setUser($readOnlyUser);
    $request = Request::create('/my-shift', 'GET');
    $response = $kernel->handle($request);
    $hubHtml = (string) $response->getContent();
    $assert($response->getStatusCode() === 200 && str_contains($hubHtml, 'Front Office shift access is read only')
        && str_contains($hubHtml, 'View Front Office shifts') && ! str_contains($hubHtml, 'Open front-office shift'),
        'front-office read-only users get a truthful hub state instead of an unavailable open-session action');
    $kernel->terminate($request, $response);

    $persisted = FrontDeskShiftSession::find($session->id);
    $assert($persisted?->status === 'closed', 'accepted session state is persisted before rollback');
} finally {
    DB::rollBack();
    if ($originalUser) {
        $guard->setUser($originalUser);
    } else {
        $guard->forgetUser();
    }
}

echo 'Completed '.$checks.' shift-session assertions; test data was rolled back.'.PHP_EOL;
