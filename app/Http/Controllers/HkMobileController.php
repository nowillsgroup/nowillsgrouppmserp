<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HousekeepingTask;
use App\Models\MinibarItem;
use App\Services\HousekeepingService;
use Illuminate\Http\Request;

/**
 * Phone-first screen for room attendants ("My rooms") and supervisors (inspection queue).
 */
class HkMobileController extends Controller
{
    protected function me(): ?Employee
    {
        return Employee::where('user_id', auth()->id())->where('is_active', true)->first();
    }

    /** Attendants may only act on their own tasks; supervisors on any. */
    protected function authorizeTask(HousekeepingTask $task): void
    {
        if (can_do('housekeeping.manage')) {
            return;
        }
        $me = $this->me();
        abort_unless($me && $task->employee_id === $me->id, 403, 'This room is assigned to someone else.');
    }

    public function my(Request $request)
    {
        $me = $this->me();
        $supervisor = can_do('housekeeping.manage');
        $asId = $supervisor ? $request->integer('as') : null;
        $emp = $asId ? Employee::find($asId) : $me;
        $date = bdate()->toDateString();
        $tasks = $emp ? HousekeepingTask::with('room.roomType', 'room.floor', 'room.currentStay.reservation.guest')->whereDate('task_date', $date)->where('employee_id', $emp->id)
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END")->orderBy('priority')->orderBy('id')->get() : collect();

        return view('housekeeping.my', [
            'emp' => $emp, 'me' => $me, 'supervisor' => $supervisor, 'tasks' => $tasks,
            'inspect' => $supervisor ? HousekeepingTask::with('room', 'employee')->whereDate('task_date', $date)->where('status', 'completed')->whereNull('inspection_status')
                ->whereNotNull('room_id')->where('task_type', '!=', 'inspection')->orderBy('completed_at')->get() : collect(),
            'attendants' => $supervisor ? HousekeepingService::attendants()->pluck('name', 'id') : collect(),
            'checklist' => HousekeepingService::checklist(),
            'minibar' => MinibarItem::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function start(HousekeepingTask $task)
    {
        $this->authorizeTask($task);
        HousekeepingService::start($task);

        return back()->with('success', "Room {$task->room?->room_no}: cleaning started.");
    }

    public function dnd(HousekeepingTask $task)
    {
        $this->authorizeTask($task);
        HousekeepingService::dnd($task);

        return back()->with('success', "Room {$task->room?->room_no} marked DND. Try again later.");
    }

    public function complete(Request $request, HousekeepingTask $task)
    {
        $this->authorizeTask($task);
        $data = $request->validate(['checked' => 'nullable|array', 'checked.*' => 'string|max:120', 'notes' => 'nullable|string|max:200']);
        HousekeepingService::complete($task, $data['checked'] ?? [], $data['notes'] ?? null);

        return back()->with('success', "Room {$task->room?->room_no} done.".(setting('hk_require_inspection', '1') === '1' ? ' Waiting for inspection.' : ''));
    }

    public function minibar(Request $request, HousekeepingTask $task)
    {
        $this->authorizeTask($task);
        abort_unless($task->room, 404);
        $data = $request->validate(['qty' => 'required|array', 'qty.*' => 'nullable|integer|min:0|max:99']);
        $txs = HousekeepingService::postMinibar($task->room, $data['qty'], $task);

        return back()->with('success', 'Minibar '.money($txs->sum('amount')).' posted to room '.$task->room->room_no.'.');
    }

    public function issue(Request $request, HousekeepingTask $task)
    {
        $this->authorizeTask($task);
        abort_unless($task->room, 404);
        $data = $request->validate(['category' => 'required|in:electrical,plumbing,ac,carpentry,it,civil,general', 'title' => 'required|string|max:150',
            'priority' => 'required|in:low,normal,high,urgent', 'block_room' => 'nullable|boolean']);
        $t = HousekeepingService::reportIssue($task->room, $data);

        return back()->with('success', "Maintenance ticket {$t->ticket_no} raised.");
    }

    public function found(Request $request, HousekeepingTask $task)
    {
        $this->authorizeTask($task);
        abort_unless($task->room, 404);
        $data = $request->validate(['item_name' => 'required|string|max:150', 'description' => 'nullable|string|max:500', 'lf_category' => 'nullable|in:'.implode(',', array_keys(\App\Models\LostFoundItem::CATEGORIES))]);
        HousekeepingService::foundItem($task->room, $data['item_name'], $data['description'] ?? null, $this->me()?->name ?? auth()->user()->name, $data['lf_category'] ?? 'other');

        return back()->with('success', 'Found item logged to Lost & Found.');
    }

    public function inspect(Request $request, HousekeepingTask $task)
    {
        $data = $request->validate(['result' => 'required|in:pass,fail', 'notes' => 'nullable|string|max:200']);
        HousekeepingService::inspect($task, $data['result'] === 'pass', $data['notes'] ?? null);

        return back()->with('success', "Room {$task->room->room_no} ".($data['result'] === 'pass' ? 'inspected ✓' : 'sent back for re-cleaning').'.');
    }
}
