<?php

namespace App\Services;

use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\SecurityIncident;
use App\Models\SecurityIncidentUpdate;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** v4.2 – security incident register: report → investigate (follow-up notes) → close; serious ones reach the log book / duty manager. */
class IncidentService
{
    public const FIELDS = ['type', 'severity', 'occurred_at', 'location', 'room_id', 'reservation_room_id', 'title', 'description', 'persons_involved',
        'action_taken', 'injuries', 'loss_amount', 'police_informed', 'police_station', 'police_ref', 'assigned_to'];

    protected static function clean(array $d): array
    {
        $type = array_key_exists($d['type'] ?? '', SecurityIncident::TYPES) ? $d['type'] : throw new RuntimeException('Choose the type of incident.');
        $sev = array_key_exists($d['severity'] ?? '', SecurityIncident::RANK) ? $d['severity'] : 'medium';
        $at = ! empty($d['occurred_at']) ? Carbon::parse($d['occurred_at']) : now();
        if ($at->gt(now()->addMinutes(5))) {
            throw new RuntimeException('The incident time cannot be in the future.');
        }
        foreach (['title' => 'a short title', 'description' => 'what happened', 'location' => 'where it happened'] as $k => $what) {
            if (trim((string) ($d[$k] ?? '')) === '') {
                throw new RuntimeException("Describe {$what}.");
            }
        }
        $stay = ! empty($d['reservation_room_id']) ? ReservationRoom::with('guest', 'reservation.guest')->findOrFail($d['reservation_room_id']) : null;
        $room = ! empty($d['room_id']) ? Room::findOrFail($d['room_id']) : null;
        $assignee = ! empty($d['assigned_to']) ? User::where('hotel_id', Tenant::id())->findOrFail($d['assigned_to']) : null;
        $police = ! empty($d['police_informed']);

        return ['type' => $type, 'severity' => $sev, 'occurred_at' => $at, 'location' => mb_substr(trim($d['location']), 0, 150),
            'room_id' => $room?->id ?? $stay?->room_id, 'reservation_room_id' => $stay?->id, 'guest_id' => $stay ? ($stay->guest_id ?? $stay->reservation?->guest_id) : null,
            'title' => mb_substr(trim($d['title']), 0, 200), 'description' => trim($d['description']),
            'persons_involved' => trim((string) ($d['persons_involved'] ?? '')) ?: null, 'action_taken' => trim((string) ($d['action_taken'] ?? '')) ?: null,
            'injuries' => ! empty($d['injuries']), 'loss_amount' => ($d['loss_amount'] ?? '') !== '' && $d['loss_amount'] !== null ? round((float) $d['loss_amount'], 2) : null,
            'police_informed' => $police, 'police_station' => $police ? (trim((string) ($d['police_station'] ?? '')) ?: null) : null,
            'police_ref' => $police ? (trim((string) ($d['police_ref'] ?? '')) ?: null) : null, 'assigned_to' => $assignee?->id];
    }

    public static function report(array $d): SecurityIncident
    {
        $data = self::clean($d);

        return DB::transaction(function () use ($data) {
            $i = SecurityIncident::create($data + ['incident_no' => Numbering::next('incident'), 'status' => 'open', 'created_by' => auth()->id()]);
            ActivityLogger::log('security', 'incident', "Incident {$i->incident_no} reported – {$i->typeLabel()} ({$i->severity}): {$i->title}", $i);
            $cfg = SecurityService::cfg();
            if (SecurityIncident::RANK[$i->severity] >= SecurityIncident::RANK[$cfg['incident_alert']]) {
                $e = LogBookService::system("Security incident {$i->incident_no}: {$i->title}",
                    $i->typeLabel().' · '.$i->severityLabel().' · '.$i->location.' at '.$i->occurred_at->format('d M H:i').".\n".$i->description
                    .($i->action_taken ? "\nAction taken: ".$i->action_taken : '').($i->police_informed ? "\nPolice informed".($i->police_ref ? ' – ref '.$i->police_ref : '') : ''),
                    'incident', $i->severity === 'critical' ? 'urgent' : 'high',
                    ['department' => 'security', 'reservation_room_id' => $i->reservation_room_id, 'room_id' => $i->room_id, 'guest_id' => $i->guest_id], true);
                $i->forceFill(['log_entry_id' => $e->id])->saveQuietly();
                if ($cfg['alert_phone'] !== '') {
                    MessageService::send($cfg['alert_phone'], hotel()->name.": {$i->severityLabel()} security incident {$i->incident_no} – {$i->title} at {$i->location}.", 'sms', 'security_alert', $i);
                }
            }

            return $i;
        });
    }

    public static function update(SecurityIncident $i, array $d): SecurityIncident
    {
        if ($i->status === 'closed') {
            throw new RuntimeException('Closed incidents cannot be edited – reopen it first.');
        }
        $data = self::clean($d + $i->only(self::FIELDS));
        $i->update($data);
        ActivityLogger::log('security', 'incident', "Incident {$i->incident_no} details updated", $i);

        return $i;
    }

    /** Follow-up note, optionally moving open ↔ investigating. */
    public static function note(SecurityIncident $i, string $note, ?string $status = null): SecurityIncidentUpdate
    {
        if ($i->status === 'closed') {
            throw new RuntimeException('This incident is closed – reopen it to add notes.');
        }
        if (trim($note) === '') {
            throw new RuntimeException('Write the follow-up note.');
        }
        $status = in_array($status, ['open', 'investigating'], true) && $status !== $i->status ? $status : null;
        $u = SecurityIncidentUpdate::create(['security_incident_id' => $i->id, 'user_id' => auth()->id(), 'note' => mb_substr(trim($note), 0, 4000), 'status_to' => $status]);
        $status ? $i->update(['status' => $status]) : $i->touch();

        return $u;
    }

    public static function close(SecurityIncident $i, string $resolution): SecurityIncident
    {
        if ($i->status === 'closed') {
            throw new RuntimeException('This incident is already closed.');
        }
        if (trim($resolution) === '') {
            throw new RuntimeException('Record the outcome / resolution before closing.');
        }
        DB::transaction(function () use ($i, $resolution) {
            $i->update(['status' => 'closed', 'resolution' => trim($resolution), 'closed_at' => now(), 'closed_by' => auth()->id()]);
            SecurityIncidentUpdate::create(['security_incident_id' => $i->id, 'user_id' => auth()->id(), 'note' => 'Closed: '.trim($resolution), 'status_to' => 'closed']);
            if ($i->log_entry_id && ($e = \App\Models\LogEntry::find($i->log_entry_id)) && $e->status === 'open') {
                LogBookService::resolve($e, "Incident {$i->incident_no} closed: ".mb_substr(trim($resolution), 0, 200));
            }
        });
        ActivityLogger::log('security', 'incident', "Incident {$i->incident_no} closed", $i);

        return $i;
    }

    public static function reopen(SecurityIncident $i, string $reason): SecurityIncident
    {
        if ($i->status !== 'closed') {
            throw new RuntimeException('This incident is not closed.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Say why it is being reopened.');
        }
        $i->update(['status' => 'investigating', 'closed_at' => null, 'closed_by' => null]);
        SecurityIncidentUpdate::create(['security_incident_id' => $i->id, 'user_id' => auth()->id(), 'note' => 'Reopened: '.trim($reason), 'status_to' => 'investigating']);
        ActivityLogger::log('security', 'incident', "Incident {$i->incident_no} reopened: {$reason}", $i);

        return $i;
    }
}
