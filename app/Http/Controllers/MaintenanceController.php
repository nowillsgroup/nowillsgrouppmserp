<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\MaintenanceRequest;
use App\Models\Room;
use App\Services\ActivityLogger;
use App\Services\AssetService;
use App\Services\PmService;
use App\Support\Numbering;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MaintenanceController extends Controller
{
    public function index(Request $request)
    {
        return view('housekeeping.maintenance', [
            'tickets' => MaintenanceRequest::with('room', 'employee', 'reporter', 'asset', 'schedule')
                ->when($request->input('status', 'active') === 'active', fn ($q) => $q->whereIn('status', ['open', 'in_progress']))
                ->when($request->input('status') === 'pm', fn ($q) => $q->whereNotNull('pm_schedule_id')->whereIn('status', ['open', 'in_progress']))
                ->when($request->input('status') === 'overdue', fn ($q) => $q->whereIn('status', ['open', 'in_progress'])->whereDate('due_date', '<', bdate()->toDateString()))
                ->when(! in_array($request->input('status', 'active'), ['active', 'all', 'pm', 'overdue']), fn ($q) => $q->where('status', $request->status))
                ->when($request->filled('asset'), fn ($q) => $q->where('asset_id', $request->integer('asset')))
                ->orderByRaw("CASE WHEN status IN ('open','in_progress') AND due_date < ? THEN 0 ELSE 1 END", [bdate()->toDateString()])
                ->latest()->paginate(25)->withQueryString(),
            'assets' => Asset::where('status', '!=', 'disposed')->orderBy('name')->get()->mapWithKeys(fn ($a) => [$a->id => $a->name.' · '.$a->asset_no]),
            'pmOverdue' => PmService::overdueCount(),
            'rooms' => Room::orderBy('room_no')->pluck('room_no', 'id'),
            'staff' => Employee::where('is_active', true)->pluck('name', 'id'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'room_id' => 'nullable|integer|owned:rooms', 'location' => 'nullable|string|max:120',
            'category' => 'required|in:electrical,plumbing,ac,carpentry,it,civil,general', 'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000', 'priority' => 'required|in:low,normal,high,urgent',
            'employee_id' => 'nullable|integer|owned:employees', 'block_room' => 'boolean',
            'asset_id' => 'nullable|integer|owned:assets', 'due_date' => 'nullable|date', 'asset_down' => 'boolean',
        ]);
        $data['block_room'] = $request->boolean('block_room');
        unset($data['asset_down']);
        $asset = ! empty($data['asset_id']) ? Asset::find($data['asset_id']) : null;
        if ($asset && $asset->isDisposed()) {
            return back()->withInput()->with('error', "Asset {$asset->asset_no} has been disposed.");
        }
        if ($asset && empty($data['room_id']) && empty($data['location'])) {
            $data['room_id'] = $asset->room_id;
            $data['location'] = $asset->location;
        }
        $t = MaintenanceRequest::create($data + ['ticket_no' => Numbering::next('ticket'), 'reported_by' => auth()->id(), 'status' => 'open']);
        if ($t->block_room && $t->room_id) {
            DB::transaction(function () use ($t) {
                // A housekeeping screen may have been opened before check-in;
                // only block a freshly locked vacant room, never overwrite an
                // occupied state from a stale model.
                $room = Room::whereKey($t->room_id)->lockForUpdate()->first();
                if ($room && $room->status === 'vacant') {
                    $room->update(['status' => 'out_of_order']);
                }
            });
        }
        if ($asset && $request->boolean('asset_down') && $asset->status === 'active') {
            AssetService::setStatus($asset, 'in_repair', "ticket {$t->ticket_no}");
        }
        ActivityLogger::log('maintenance', 'create', "Ticket {$t->ticket_no}: {$t->title}", $t);

        return back()->with('success', "Ticket {$t->ticket_no} logged.");
    }

    public function update(Request $request, MaintenanceRequest $ticket)
    {
        $data = $request->validate(['status' => 'required|in:open,in_progress,resolved,closed', 'employee_id' => 'nullable|integer|owned:employees', 'resolution' => 'nullable|string|max:2000', 'cost' => 'nullable|numeric|min:0']);
        $wasOpen = $ticket->isOpen();
        $ticket->fill(array_filter($data, fn ($v) => $v !== null));
        if (in_array($data['status'], ['resolved', 'closed'])) {
            $ticket->resolved_at ??= now();
            if ($ticket->block_room && $ticket->room && $ticket->room->status === 'out_of_order') {
                $ticket->room->update(['status' => 'vacant', 'hk_status' => 'dirty']);
            }
        }
        $ticket->save();
        if ($wasOpen && ! $ticket->isOpen()) {
            PmService::completed($ticket); // v2.3: a preventive-maintenance ticket rolls its schedule on
            $asset = $ticket->asset;
            if ($asset && $asset->status === 'in_repair' && ! $asset->tickets()->whereIn('status', ['open', 'in_progress'])->exists()) {
                AssetService::setStatus($asset, 'active', "ticket {$ticket->ticket_no} {$ticket->status}");
            }
        } elseif (! $wasOpen && $ticket->isOpen()) {
            $ticket->forceFill(['resolved_at' => null])->save(); // re-opened
        }
        ActivityLogger::log('maintenance', 'update', "Ticket {$ticket->ticket_no} → {$data['status']}", $ticket);

        return back()->with('success', 'Ticket updated.');
    }
}
