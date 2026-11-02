#!/usr/bin/env python3
"""Regression checks for the reservation detail Activity Log."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
CONTROLLER = (ROOT / "app/Http/Controllers/ReservationController.php").read_text()
VIEW = (ROOT / "resources/views/reservations/show.blade.php").read_text()


class ReservationActivityLogTest(unittest.TestCase):
    def test_controller_loads_recent_logs_for_reservation_and_linked_stays(self):
        self.assertIn("$activityLogs = ActivityLog::with('user')", CONTROLLER)
        self.assertIn("where('subject_type', 'Reservation')->where('subject_id', $reservation->id)", CONTROLLER)
        self.assertIn("where('subject_type', 'ReservationRoom')->whereIn('subject_id', $stayIds)", CONTROLLER)
        self.assertIn("->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get()", CONTROLLER)
        self.assertIn("'activityLogs' => $activityItems", CONTROLLER)

    def test_online_payment_events_only_load_when_payment_permission_is_granted(self):
        self.assertIn("$onlinePayments = can_do('payments.view')", CONTROLLER)
        self.assertIn("where('subject_type', 'OnlinePayment')->whereIn('subject_id', $paymentIds)", CONTROLLER)
        self.assertIn("if ($paymentIds)", CONTROLLER)

    def test_guest_communications_are_summarized_only_for_log_viewers(self):
        self.assertIn("if (can_do('logs.view'))", CONTROLLER)
        self.assertIn("EmailLog::with('user')->where($relatedFilter)->latest('id')->limit(50)->get()", CONTROLLER)
        self.assertIn("MessageLog::with('user')->where($relatedFilter)->latest('id')->limit(50)->get()", CONTROLLER)
        self.assertIn("'action' => 'E-mail '.ucfirst($log->status)", CONTROLLER)
        self.assertIn("'description' => ucwords(str_replace('_', ' ', $log->template)).' e-mail'", CONTROLLER)
        start = CONTROLLER.index("if (can_do('logs.view'))", CONTROLLER.index('$activityItems ='))
        end = CONTROLLER.index('$activityItems = $activityItems->sortByDesc', start)
        communication_builder = CONTROLLER[start:end]
        for private_field in ('$log->to_email', '$log->to', '$log->body', '$log->error'):
            with self.subTest(field=private_field):
                self.assertNotIn(private_field, communication_builder)

    def test_view_shows_action_description_actor_and_machine_readable_time(self):
        self.assertIn('href="#reservationActivityLog"', VIEW)
        self.assertIn('id="reservationActivityLog"', VIEW)
        self.assertIn('id="reservationActivityTitle"', VIEW)
        self.assertIn('aria-labelledby="reservationActivityTitle"', VIEW)
        self.assertIn("{{ $activity->description }}", VIEW)
        self.assertIn("{{ $activity->actor }}", VIEW)
        self.assertIn("$activity->tone", VIEW)
        self.assertIn("$activity->created_at->toIso8601String()", VIEW)
        self.assertIn("$activity->created_at->format('d M Y, H:i')", VIEW)
        self.assertIn('No activity has been recorded for this reservation yet.', VIEW)
        self.assertIn("@if(can_do('logs.view'))<a class=\"btn btn-sm\" href=\"{{ route('logs.index', ['from' => $r->created_at->toDateString(), 'to' => now()->toDateString(), 'q' => $r->reservation_no]) }}\">View full log</a>@endif", VIEW)

    def test_activity_output_does_not_expose_request_metadata_or_raw_properties(self):
        section = VIEW.split('id="reservationActivityLog"', 1)[1].split('<div class="modal"', 1)[0]
        for sensitive_field in ('$activity->ip', '$activity->user_agent', '$activity->properties'):
            with self.subTest(field=sensitive_field):
                self.assertNotIn(sensitive_field, section)


if __name__ == "__main__":
    unittest.main(verbosity=2)
