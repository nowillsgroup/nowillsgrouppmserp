<?php

namespace App\Services;

use App\Models\MaintenanceRequest;
use App\Models\PmSchedule;
use App\Support\Numbering;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v2.3 preventive maintenance: recurring schedules (every N days / weeks / months) raise a maintenance ticket
 * `lead_days` before they fall due – one open ticket per schedule at a time. Resolving the ticket records the
 * service and rolls the schedule on to its next due date (skipping cycles that were missed entirely).
 */
class PmService
{
    /** Raise tickets for every schedule that is due within its lead time. Returns the tickets created. */
    public static function generate(?Carbon $today = null): array
    {
        $today = ($today ?? bdate())->copy()->startOfDay();
        $made = [];
        $due = PmSchedule::with('asset', 'room')->where('is_active', true)->whereDoesntHave('openTicket')->get()
            ->filter(fn ($s) => $s->next_due->copy()->subDays($s->lead_days)->lte($today))
            ->filter(fn ($s) => ! $s->asset || ! $s->asset->isDisposed());
        foreach ($due as $s) {
            $made[] = DB::transaction(function () use ($s) {
                $t = MaintenanceRequest::create([
                    'ticket_no' => Numbering::next('ticket'), 'room_id' => $s->room_id ?? $s->asset?->room_id, 'asset_id' => $s->asset_id, 'pm_schedule_id' => $s->id,
                    'location' => $s->location ?? $s->asset?->location, 'category' => $s->category, 'title' => 'PM: '.$s->title,
                    'description' => trim(($s->checklist ? "Checklist:\n".$s->checklist : '').($s->vendor ? "\n\nService vendor: {$s->vendor}" : '')) ?: null,
                    'priority' => $s->priority, 'status' => 'open', 'due_date' => $s->next_due->toDateString(), 'employee_id' => $s->employee_id, 'reported_by' => auth()->id(),
                ]);
                ActivityLogger::log('maintenance', 'pm', "Preventive maintenance ticket {$t->ticket_no} raised: {$s->title} (due ".$s->next_due->format('d M').')', $t);

                return $t;
            });
        }

        return $made;
    }

    /** Called when a ticket is resolved / closed: a PM ticket rolls its schedule forward. */
    public static function completed(MaintenanceRequest $t): void
    {
        if (! $t->pm_schedule_id || ! ($s = $t->schedule)) {
            return;
        }
        $due = $t->due_date ?? $s->next_due;
        if ($s->next_due->gt($due)) {
            return; // already rolled on (ticket re-opened and closed again)
        }
        $done = ($t->resolved_at ? Carbon::parse($t->resolved_at) : now())->startOfDay();
        $done = $done->gt(bdate()) ? bdate()->copy() : $done;
        $next = $s->advance($due);
        while ($next->lte($done)) {
            $next = $s->advance($next);
        }
        $s->forceFill(['last_done' => $done->toDateString(), 'next_due' => $next->toDateString()])->save();
    }

    /** PM compliance for a period: tickets that fell due, done on time / late / still open. */
    public static function compliance(string $from, string $to): array
    {
        $rows = MaintenanceRequest::with('schedule', 'asset', 'employee')->whereNotNull('pm_schedule_id')->whereBetween('due_date', [$from, $to])->get();
        $onTime = $rows->filter(fn ($t) => $t->resolved_at && Carbon::parse($t->resolved_at)->startOfDay()->lte($t->due_date))->count();
        $late = $rows->filter(fn ($t) => $t->resolved_at && Carbon::parse($t->resolved_at)->startOfDay()->gt($t->due_date))->count();
        $open = $rows->filter(fn ($t) => ! $t->resolved_at)->count();

        return ['rows' => $rows, 'total' => $rows->count(), 'on_time' => $onTime, 'late' => $late, 'open' => $open,
            'pct' => $rows->count() ? round($onTime * 100 / $rows->count(), 1) : null];
    }

    public static function overdueCount(): int
    {
        return MaintenanceRequest::whereNotNull('pm_schedule_id')->whereIn('status', ['open', 'in_progress'])->whereDate('due_date', '<', bdate()->toDateString())->count();
    }
}
