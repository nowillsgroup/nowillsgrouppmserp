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
        if ($stay && $room && $stay->room_id && (int) $stay->room_id !== (int) $room->id) {
            throw new RuntimeException('The selected room does not match the selected stay.');
        }
        $assignee = ! empty($d['assigned_to']) ? User::where('hotel_id', Tenant::id())->where('is_active', true)->findOrFail($d['assigned_to']) : null;
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

        [$i, $alertPhone] = DB::transaction(function () use ($data) {
            $i = SecurityIncident::create($data + ['incident_no' => Numbering::next('incident'), 'status' => 'open', 'created_by' => auth()->id()]);
            ActivityLogger::log('security', 'incident', "Incident {$i->incident_no} reported – {$i->typeLabel()} ({$i->severity}): {$i->title}", $i);
            $cfg = SecurityService::cfg();
            $alertPhone = '';
            if (SecurityIncident::RANK[$i->severity] >= SecurityIncident::RANK[$cfg['incident_alert']]) {
                $e = LogBookService::system("Security incident {$i->incident_no}: {$i->title}",
                    $i->typeLabel().' · '.$i->severityLabel().' · '.$i->location.' at '.$i->occurred_at->format('d M H:i').".\n".$i->description
                    .($i->action_taken ? "\nAction taken: ".$i->action_taken : '').($i->police_informed ? "\nPolice informed".($i->police_ref ? ' – ref '.$i->police_ref : '') : ''),
                    'incident', $i->severity === 'critical' ? 'urgent' : 'high',
                    ['department' => 'security', 'reservation_room_id' => $i->reservation_room_id, 'room_id' => $i->room_id, 'guest_id' => $i->guest_id], true);
                $i->forceFill(['log_entry_id' => $e->id])->saveQuietly();
                $alertPhone = $cfg['alert_phone'];
            }

            return [$i, $alertPhone];
        });
        if ($alertPhone !== '') {
            MessageService::send($alertPhone, hotel()->name.": {$i->severityLabel()} security incident {$i->incident_no} – {$i->title} at {$i->location}.", 'sms', 'security_alert', $i);
        }

        return $i;
    }

    protected static function lockIncident(SecurityIncident $incident): SecurityIncident
    {
        return SecurityIncident::whereKey($incident->id)->lockForUpdate()->firstOrFail();
    }

    public static function update(SecurityIncident $i, array $d): SecurityIncident
    {
        $data = self::clean($d + $i->only(self::FIELDS));

        return DB::transaction(function () use ($i, $data) {
            $locked = self::lockIncident($i);
            if ($locked->status === 'closed') {
                throw new RuntimeException('Closed incidents cannot be edited – reopen it first.');
            }
            $locked->forceFill($data)->save();
            ActivityLogger::log('security', 'incident', "Incident {$locked->incident_no} details updated", $locked);

            return $locked;
        });
    }

    /** Follow-up note, optionally moving open ↔ investigating. */
    public static function note(SecurityIncident $i, string $note, ?string $status = null): SecurityIncidentUpdate
    {
        if (trim($note) === '') {
            throw new RuntimeException('Write the follow-up note.');
        }

        return DB::transaction(function () use ($i, $note, $status) {
            $locked = self::lockIncident($i);
            if ($locked->status === 'closed') {
                throw new RuntimeException('This incident is closed – reopen it to add notes.');
            }
            $nextStatus = in_array($status, ['open', 'investigating'], true) && $status !== $locked->status ? $status : null;
            $u = SecurityIncidentUpdate::create(['security_incident_id' => $locked->id, 'user_id' => auth()->id(), 'note' => mb_substr(trim($note), 0, 4000), 'status_to' => $nextStatus]);
            $nextStatus ? $locked->forceFill(['status' => $nextStatus])->save() : $locked->touch();

            return $u;
        });
    }

    public static function close(SecurityIncident $i, string $resolution): SecurityIncident
    {
        if (trim($resolution) === '') {
            throw new RuntimeException('Record the outcome / resolution before closing.');
        }
        return DB::transaction(function () use ($i, $resolution) {
            $locked = self::lockIncident($i);
            if ($locked->status === 'closed') {
                throw new RuntimeException('This incident is already closed.');
            }
            $locked->forceFill(['status' => 'closed', 'resolution' => trim($resolution), 'closed_at' => now(), 'closed_by' => auth()->id()])->save();
            SecurityIncidentUpdate::create(['security_incident_id' => $locked->id, 'user_id' => auth()->id(), 'note' => 'Closed: '.trim($resolution), 'status_to' => 'closed']);
            if ($locked->log_entry_id && ($e = \App\Models\LogEntry::whereKey($locked->log_entry_id)->lockForUpdate()->first()) && $e->status === 'open') {
                LogBookService::resolve($e, "Incident {$locked->incident_no} closed: ".mb_substr(trim($resolution), 0, 200));
            }
            ActivityLogger::log('security', 'incident', "Incident {$locked->incident_no} closed", $locked);

            return $locked;
        });
    }

    public static function reopen(SecurityIncident $i, string $reason): SecurityIncident
    {
        if (trim($reason) === '') {
            throw new RuntimeException('Say why it is being reopened.');
        }

        return DB::transaction(function () use ($i, $reason) {
            $locked = self::lockIncident($i);
            if ($locked->status !== 'closed') {
                throw new RuntimeException('This incident is not closed.');
            }
            $locked->forceFill(['status' => 'investigating', 'closed_at' => null, 'closed_by' => null])->save();
            SecurityIncidentUpdate::create(['security_incident_id' => $locked->id, 'user_id' => auth()->id(), 'note' => 'Reopened: '.trim($reason), 'status_to' => 'investigating']);
            if ($locked->log_entry_id && ($e = \App\Models\LogEntry::whereKey($locked->log_entry_id)->lockForUpdate()->first()) && $e->status === 'resolved') {
                LogBookService::reopen($e);
            }
            ActivityLogger::log('security', 'incident', "Incident {$locked->incident_no} reopened: {$reason}", $locked);

            return $locked;
        });
    }
}
