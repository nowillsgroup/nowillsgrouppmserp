#!/usr/bin/env python3
"""Static regression guards for reservation-form lookups and quote races.

Run with: python3 deploy/dev/audit/reservation_lookup_safety_check.py
"""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
GUEST_PARTIAL = (ROOT / "resources/views/reservations/_guest.blade.php").read_text()
FORM = (ROOT / "resources/views/reservations/form.blade.php").read_text()


def function_body(source: str, start: str, end: str) -> str:
    begin = source.index(start)
    finish = source.index(end, begin)
    return source[begin:finish]


class ReservationLookupSafetyTest(unittest.TestCase):
    def test_guest_results_are_built_as_text_not_interpolated_html(self):
        self.assertNotIn("innerHTML", GUEST_PARTIAL)
        self.assertIn("name.textContent =", GUEST_PARTIAL)
        self.assertIn("details.textContent =", GUEST_PARTIAL)
        self.assertIn("option.append(name", GUEST_PARTIAL)
        self.assertIn("encodeURIComponent(query)", GUEST_PARTIAL)

    def test_guest_search_discards_out_of_order_responses(self):
        self.assertIn("let requestVersion = 0", GUEST_PARTIAL)
        self.assertIn("version !== requestVersion", GUEST_PARTIAL)
        self.assertIn("inp.value.trim() !== query", GUEST_PARTIAL)

    def test_room_and_rate_choices_use_safe_dom_options(self):
        self.assertIn("el.textContent = label", FORM)
        rooms = function_body(FORM, "function loadRooms(", "function fillPlans(")
        plans = function_body(FORM, "function fillPlans(", "// seasonal pricing")
        self.assertNotIn("innerHTML", rooms + plans)
        self.assertIn("room.room_no", rooms)
        self.assertIn("plan.name", plans)
        self.assertIn("option(`${plan.name}", plans)

    def test_room_and_rate_requests_ignore_stale_responses(self):
        self.assertIn("tr.dataset.roomsRequest", FORM)
        self.assertIn("request !== Number(tr.dataset.roomsRequest)", FORM)
        self.assertIn("tr.dataset.rateRequest", FORM)
        self.assertIn("request !== Number(tr.dataset.rateRequest)", FORM)
        self.assertIn("tr.dataset.selectedRoom", FORM)

    def test_date_math_is_date_only_and_empty_dates_are_safe(self):
        nights = function_body(FORM, "function nights(", "function nextDate(")
        next_date = function_body(FORM, "function nextDate(", "function reindex(")
        self.assertIn("if (!A.value || !D.value) return 0", nights)
        self.assertIn("T00:00:00Z", nights)
        self.assertIn("Date.UTC(year, month - 1, day + 1)", next_date)
        self.assertIn("D.min = nextDate(A.value)", FORM)

    def test_rate_banner_escapes_server_strings_before_html_insertion(self):
        banner = function_body(FORM, "function contractBanner(", "function seasonRate(")
        self.assertIn("esc(c.code)", banner)
        self.assertIn("esc(c.name)", banner)
        self.assertIn("esc(c.why.join(', '))", banner)


if __name__ == "__main__":
    unittest.main(verbosity=2)
