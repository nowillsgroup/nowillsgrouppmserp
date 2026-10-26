<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\FolioTransaction;
use App\Models\HousekeepingTask;
use App\Models\Hotel;
use App\Models\LostFoundItem;
use App\Models\MaintenanceRequest;
use App\Models\MinibarItem;
use App\Models\Room;
use App\Models\StockMovement;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Room-attendant workflow: credit-balanced daily assignment, start / DND / complete with checklist,
 * supervisor inspection (pass → inspected, fail → re-clean), minibar posting to the in-house folio,
 * and maintenance / lost-and-found reports raised from the room.
 */
class HousekeepingService
{
    public const DEFAULT_CHECKLIST = "Bed made / linen changed\nBathroom cleaned & sanitised\nTowels replaced\nFloor vacuumed / mopped\nAmenities replenished\nMinibar checked\nTrash emptied\nAC, lights & TV working";

    public static function attendants(): Collection
    {
        return Employee::where('is_active', true)->whereHas('department', fn ($q) => $q->where('name', 'like', '%Housekeeping%'))->orderBy('name')->get();
    }

    public static function checklist(): array
    {
        return collect(preg_split('/\r?\n/', (string) setting('hk_checklist', self::DEFAULT_CHECKLIST)))->map(fn ($l) => trim($l))->filter()->take(30)->values()->all();
    }

    public static function credits(string $type): int
    {
        return max(0, (int) match ($type) {
            'departure' => setting('hk_credits_departure', 2),
            'deep_clean' => setting('hk_credits_deep', 3),
            'turndown', 'inspection', 'linen_change' => 1,
            default => setting('hk_credits_stayover', 1),
        });
    }

    /**
     * Create today's room tasks and spread them across attendants by workload credits,
     * keeping each attendant on as few floors as possible.
     *
     * @return int number of tasks created
     */
    public static function generate(string $date): int
    {
        return DB::transaction(function () use ($date) {
            // Empty task sets cannot be protected by locking their rows. Serialize
            // generators through the owning hotel row before checking for existing tasks.
            if (Tenant::check()) {
                Hotel::whereKey(Tenant::id())->lockForUpdate()->firstOrFail();
            }
            $staff = self::attendants();
            $load = HousekeepingTask::where('task_date', $date)->whereNotNull('employee_id')->groupBy('employee_id')->selectRaw('employee_id, SUM(credits) c')->pluck('c', 'employee_id')->map(fn ($c) => (int) $c)->all();
            foreach ($staff as $e) {
                $load[$e->id] ??= 0;
            }
            $floorsOf = HousekeepingTask::with('room')->whereDate('task_date', $date)->whereNotNull('employee_id')->get()
                ->groupBy('employee_id')->map(fn ($g) => $g->pluck('room.floor_id')->filter()->unique()->all())->all();
            $existing = HousekeepingTask::where('task_date', $date)->whereNotNull('room_id')->where('task_type', '!=', 'inspection')->pluck('room_id');
            $stayover = setting('hk_daily_stayover', '1') === '1';
            $rooms = Room::with('floor', 'currentStay')->where('is_active', true)->whereNotIn('id', $existing)
                ->whereNotIn('status', ['out_of_order', 'out_of_service'])
                ->where(fn ($q) => $q->whereIn('hk_status', ['dirty', 'touch_up'])->when($stayover, fn ($w) => $w->orWhere('status', 'occupied')))
                ->get()->sortBy(fn ($r) => sprintf('%05d-%s', $r->floor->sort_order ?? 0, $r->room_no))->values();
            $n = 0;
            foreach ($rooms as $room) {
                $type = match (true) {
                    $room->status === 'occupied' && $room->currentStay && $room->currentStay->departure_date->toDateString() <= $date => 'departure',
                    $room->status === 'occupied' => 'cleaning',
                    $room->hk_status === 'dirty' => 'departure',
                    default => 'cleaning',
                };
                if ($type === 'cleaning' && $room->status === 'occupied' && EsgService::skipsService($room->currentStay, $date)) {
                    continue; // v7.0 green stay: the guest chose to skip today's stay-over service
                }
                $credits = self::credits($type);
                $emp = null;
                if ($staff->isNotEmpty()) {
                    $min = min($load);
                    // prefer someone already on this floor unless they are clearly busier
                    $emp = collect($load)->filter(fn ($c, $id) => in_array($room->floor_id, $floorsOf[$id] ?? []) && $c <= $min + 1)->sort()->keys()->first()
                        ?? collect($load)->sort()->keys()->first();
                    $load[$emp] += $credits;
                    $floorsOf[$emp][] = $room->floor_id;
                }
                HousekeepingTask::create(['room_id' => $room->id, 'employee_id' => $emp, 'task_type' => $type, 'credits' => $credits, 'task_date' => $date,
                    'priority' => $room->status === 'vacant' ? 'high' : 'normal', 'status' => 'pending',
                    'notes' => $room->currentStay?->reservation?->special_request ? mb_substr('Guest request: '.$room->currentStay->reservation->special_request, 0, 250) : null]);
                $n++;
            }
            ActivityLogger::log('housekeeping', 'generate', "Generated {$n} task(s) for {$date}");

            return $n;
        });
    }

    public static function start(HousekeepingTask $task): HousekeepingTask
    {
        $updated = DB::transaction(function () use ($task) {
            $locked = HousekeepingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            self::assertOpen($locked);
            $locked->forceFill(['status' => 'in_progress', 'started_at' => $locked->started_at ?? now(), 'dnd_at' => null])->save();

            return $locked->load('room');
        });

        return $updated;
    }

    /** Guest has Do-Not-Disturb on / refused service: task stays pending and is flagged. */
    public static function dnd(HousekeepingTask $task): HousekeepingTask
    {
        $updated = DB::transaction(function () use ($task) {
            $locked = HousekeepingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            self::assertOpen($locked);
            $locked->forceFill(['status' => 'pending', 'dnd_at' => now()])->save();

            return $locked->load('room');
        });
        ActivityLogger::log('housekeeping', 'dnd', "Room {$updated->room?->room_no}: DND / service refused", $updated);

        return $updated;
    }

    public static function complete(HousekeepingTask $task, array $checked = [], ?string $notes = null): HousekeepingTask
    {
        $updated = DB::transaction(function () use ($task, $checked, $notes) {
            $locked = HousekeepingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            self::assertOpen($locked);
            $items = self::checklist();
            $checked = array_values(array_intersect($items, $checked));
            if (setting('hk_checklist_required', '1') === '1' && $locked->room_id && $locked->task_type !== 'public_area' && count($checked) < count($items)) {
                throw new RuntimeException('Tick every checklist item before completing the room ('.count($checked).'/'.count($items).').');
            }
            $locked->forceFill(['status' => 'completed', 'started_at' => $locked->started_at ?? now(), 'completed_at' => now(), 'completed_by' => auth()->id(),
                'checklist' => $checked, 'dnd_at' => null, 'notes' => $notes ? mb_substr(trim(($locked->notes ? $locked->notes.' | ' : '').$notes), 0, 255) : $locked->notes])->save();
            if ($locked->room_id) {
                // Task then room is the shared lock order for housekeeping writes.
                $room = Room::whereKey($locked->room_id)->lockForUpdate()->first();
                if ($room) {
                    $status = $locked->task_type === 'inspection' ? 'inspected' : (setting('hk_require_inspection', '1') === '1' ? 'clean' : 'inspected');
                    if (in_array($locked->task_type, ['cleaning', 'departure', 'deep_clean', 'turndown', 'linen_change', 'inspection'], true)) {
                        $room->update(['hk_status' => $status]);
                    }
                    if ($status === 'inspected' && $locked->task_type !== 'inspection') {
                        $locked->forceFill(['inspection_status' => 'passed', 'inspected_at' => now()])->save();
                    }
                }
            }

            return $locked->load('room');
        });
        ActivityLogger::log('housekeeping', 'complete', "Room {$updated->room?->room_no} cleaned (".(HousekeepingTask::TYPES[$updated->task_type] ?? $updated->task_type).')', $updated);

        return $updated;
    }

    public static function inspect(HousekeepingTask $task, bool $pass, ?string $notes = null): HousekeepingTask
    {
        $updated = DB::transaction(function () use ($task, $pass, $notes) {
            $locked = HousekeepingTask::whereKey($task->id)->lockForUpdate()->firstOrFail();
            if (! $locked->awaitingInspection()) {
                throw new RuntimeException('This task is not awaiting inspection.');
            }
            if (! $pass && ! trim((string) $notes)) {
                throw new RuntimeException('Say what needs re-doing when failing an inspection.');
            }
            $room = Room::whereKey($locked->room_id)->lockForUpdate()->first();
            if (! $room) {
                throw new RuntimeException('The room for this inspection no longer exists.');
            }
            if ($pass) {
                $locked->forceFill(['inspection_status' => 'passed', 'inspected_by' => auth()->id(), 'inspected_at' => now(), 'inspection_notes' => $notes])->save();
                $room->update(['hk_status' => 'inspected']);
            } else {
                // re-open the same task for the same attendant
                $locked->forceFill(['status' => 'pending', 'inspection_status' => null, 'inspected_by' => auth()->id(), 'inspected_at' => now(),
                    'inspection_notes' => mb_substr($notes, 0, 255), 'reclean_count' => $locked->reclean_count + 1, 'completed_at' => null, 'priority' => 'high'])->save();
                $room->update(['hk_status' => 'dirty']);
            }

            return $locked->load('room');
        });
        ActivityLogger::log('housekeeping', $pass ? 'inspect_pass' : 'inspect_fail', "Room {$updated->room->room_no} inspection ".($pass ? 'passed' : 'failed: '.$notes), $updated);

        return $updated;
    }

    /**
     * Post minibar consumption to the guest's open folio.
     *
     * @param  array<int, int|float>  $lines  minibar_item_id => qty
     * @return Collection<FolioTransaction>
     */
    public static function postMinibar(Room $room, array $lines, ?HousekeepingTask $task = null): Collection
    {
        $lines = array_filter(array_map('floatval', $lines), fn ($q) => $q > 0);
        if (! $lines) {
            throw new RuntimeException('Enter the quantity consumed for at least one item.');
        }
        $stay = $room->currentStay()->first();
        if (! $stay) {
            throw new RuntimeException("Room {$room->room_no} is not occupied. Post late minibar charges from the guest's folio.");
        }

        return DB::transaction(function () use ($room, $lines, $task, $stay) {
            $folio = FolioService::openForStay($stay);
            $items = MinibarItem::whereIn('id', array_keys($lines))->where('is_active', true)->get()->keyBy('id');
            if ($items->count() !== count($lines)) {
                throw new RuntimeException('Unknown minibar item.');
            }
            $store = setting('minibar_store_id');
            $txs = collect();
            foreach ($lines as $id => $qty) {
                $it = $items[$id];
                $qty = min(99, round($qty));
                $txs->push(FolioService::charge($folio, 'minibar', "Minibar – {$it->name} × {$qty}", round($it->price * $qty, 2), true,
                    ['source_type' => 'minibar', 'source_id' => $task?->id, 'reference' => 'Room '.$room->room_no]));
                if ($it->inventory_item_id && $store) {
                    StockMovement::create(['inventory_item_id' => $it->inventory_item_id, 'store_id' => $store, 'date' => bdate(), 'type' => 'issue', 'qty' => -$qty,
                        'rate' => (float) $it->inventoryItem?->last_cost, 'department' => 'Minibar', 'reference' => 'Room '.$room->room_no, 'user_id' => auth()->id()]);
                }
            }
            ActivityLogger::log('housekeeping', 'minibar', "Minibar posted for room {$room->room_no}: ".money($txs->sum('amount')), $stay);

            return $txs;
        });
    }

    public static function reportIssue(Room $room, array $data): MaintenanceRequest
    {
        $t = MaintenanceRequest::create([
            'ticket_no' => Numbering::next('ticket'), 'room_id' => $room->id, 'category' => $data['category'], 'title' => $data['title'],
            'description' => $data['description'] ?? null, 'priority' => $data['priority'] ?? 'normal', 'status' => 'open', 'reported_by' => auth()->id(),
            'block_room' => ! empty($data['block_room']),
        ]);
        if ($t->block_room) {
            DB::transaction(function () use ($room) {
                $locked = Room::whereKey($room->id)->lockForUpdate()->first();
                if ($locked && $locked->status === 'vacant') {
                    $locked->update(['status' => 'out_of_order']);
                }
            });
        }
        ActivityLogger::log('maintenance', 'create', "Ticket {$t->ticket_no} from housekeeping, room {$room->room_no}: {$t->title}", $t);

        return $t;
    }

    public static function foundItem(Room $room, string $name, ?string $description, ?string $foundBy, string $category = 'other'): LostFoundItem
    {
        // v3.5: numbered, categorised, retention date and probable owner (guest last in the room)
        return LostFoundService::log(['item_name' => $name, 'description' => $description, 'room_id' => $room->id, 'category' => $category,
            'found_date' => bdate()->toDateString(), 'found_by' => $foundBy]);
    }

    protected static function assertOpen(HousekeepingTask $task): void
    {
        if ($task->status === 'completed') {
            throw new RuntimeException('This task is already completed.');
        }
    }
}
