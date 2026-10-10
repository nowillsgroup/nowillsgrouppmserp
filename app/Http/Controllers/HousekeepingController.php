<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HousekeepingTask;
use App\Models\Room;
use App\Services\ActivityLogger;
use App\Services\HousekeepingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class HousekeepingController extends Controller
{
    public function index(Request $request)
    {
        $rooms = Room::with(['roomType', 'floor', 'currentStay.reservation.guest'])->where('is_active', true)
            ->when($request->hk, fn ($q, $s) => $q->where('hk_status', $s))
            ->when($request->floor, fn ($q, $f) => $q->where('floor_id', $f))
            ->orderBy('room_no')->get();

        return view('housekeeping.index', [
            'rooms' => $rooms,
            'counts' => Room::where('is_active', true)->get()->countBy('hk_status'),
            'floors' => \App\Models\Floor::orderBy('sort_order')->pluck('name', 'id'),
        ]);
    }

    public function updateStatus(Request $request)
    {
        $data = $request->validate([
            'rooms' => 'required|array|min:1', 'rooms.*' => 'integer|owned:rooms',
            'hk_status' => 'nullable|in:clean,dirty,inspected,touch_up', 'status' => 'nullable|in:vacant,out_of_order,out_of_service',
            'notes' => 'nullable|string|max:255',
        ]);
        try {
            $rooms = DB::transaction(function () use ($data) {
                // Re-read and lock each room before changing housekeeping or
                // operational status. A stale housekeeping screen must not
                // turn a room vacant after front desk has checked a guest in.
                $rooms = Room::whereIn('id', $data['rooms'])->orderBy('id')->lockForUpdate()->get();
                foreach ($rooms as $room) {
                    if (! empty($data['status']) && $room->status === 'occupied') {
                        throw new RuntimeException("Room {$room->room_no} is occupied; its status can't be changed.");
                    }
                    if (! empty($data['hk_status'])) {
                        $room->hk_status = $data['hk_status'];
                    }
                    if (! empty($data['status'])) {
                        $room->status = $data['status'];
                    }
                    if (array_key_exists('notes', $data) && $data['notes'] !== null) {
                        $room->notes = $data['notes'];
                    }
                    $room->save();
                }

                return $rooms;
            });
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        ActivityLogger::log('housekeeping', 'status', 'Rooms '.$rooms->pluck('room_no')->join(', ').' → '.($data['hk_status'] ?? $data['status'] ?? 'updated'));

        return back()->with('success', $rooms->count().' room(s) updated.');
    }

    public function tasks(Request $request)
    {
        $date = $request->input('date', bdate()->toDateString());
        $tasks = HousekeepingTask::with('room.floor', 'employee', 'inspector')->where('task_date', $date)
            ->when($request->status, fn ($q, $s) => $s === 'inspect' ? $q->where('status', 'completed')->whereNull('inspection_status')->whereNotNull('room_id')->where('task_type', '!=', 'inspection') : $q->where('status', $s))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END")->get();
        $staff = HousekeepingService::attendants();

        return view('housekeeping.tasks', [
            'tasks' => $tasks, 'date' => $date,
            'rooms' => Room::orderBy('room_no')->pluck('room_no', 'id'),
            'staff' => $staff->pluck('name', 'id'),
            'workload' => $staff->map(fn ($e) => (object) ['emp' => $e, 'tasks' => $tasks->where('employee_id', $e->id)])
                ->push((object) ['emp' => null, 'tasks' => $tasks->whereNull('employee_id')])->filter(fn ($w) => $w->emp || $w->tasks->isNotEmpty()),
        ]);
    }

    public function storeTask(Request $request)
    {
        $data = $request->validate([
            'room_id' => 'nullable|required_without:area|integer|owned:rooms', 'area' => 'nullable|string|max:120', 'employee_id' => 'nullable|integer|owned:employees',
            'task_type' => 'required|in:'.implode(',', array_keys(HousekeepingTask::TYPES)), 'priority' => 'required|in:low,normal,high',
            'task_date' => 'required|date', 'notes' => 'nullable|string|max:255',
        ]);
        HousekeepingTask::create($data + ['credits' => HousekeepingService::credits($data['task_type'])]);

        return back()->with('success', 'Task assigned.');
    }

    /** Create today's room tasks and balance them across attendants by credits. */
    public function generate(Request $request)
    {
        $date = $request->validate(['date' => 'nullable|date'])['date'] ?? bdate()->toDateString();
        $n = HousekeepingService::generate($date);

        return back()->with('success', $n.' task(s) generated and assigned.');
    }

    public function updateTask(Request $request, HousekeepingTask $task)
    {
        $data = $request->validate(['status' => 'nullable|in:pending,in_progress,completed', 'employee_id' => 'nullable|integer|owned:employees']);
        if ($request->has('employee_id')) {
            $task->update(['employee_id' => $data['employee_id'] ?? null]);
        }
        match ($data['status'] ?? null) {
            'in_progress' => HousekeepingService::start($task),
            // supervisor override from the desk: the checklist is taken as done by the supervisor
            'completed' => HousekeepingService::complete($task, HousekeepingService::checklist(), 'Closed by '.auth()->user()->name),
            'pending' => $task->update(['status' => 'pending']),
            default => null,
        };

        return back()->with('success', 'Task updated.');
    }
}
