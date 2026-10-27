#!/usr/bin/env python3
"""Static regression checks for the reservation workspace quick search."""
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]
WORKSPACE = (ROOT / "resources/views/reservations/workspace.blade.php").read_text()
PREVIEW = (ROOT / "public/preview-reservations.html").read_text()


class ReservationWorkspaceSearchTest(unittest.TestCase):
    def test_workspace_indexes_safe_search_terms_on_each_room_card(self):
        self.assertIn("data-search=\"{{ $searchText }}\"", WORKSPACE)
        self.assertIn("$stay?->reservation?->reservation_no", WORKSPACE)
        self.assertIn("$stay?->reservation?->ota_reference", WORKSPACE)
        self.assertIn("is_scalar($value)", WORKSPACE)

    def test_workspace_search_combines_with_filters_and_restores_from_query(self):
        self.assertIn("value=\"{{ $searchQuery }}\"", WORKSPACE)
        self.assertIn("card.dataset.search || card.textContent", WORKSPACE)
        self.assertIn("matchesFilters && matchesSearch", WORKSPACE)
        self.assertIn("params.set('q', search.value.trim())", WORKSPACE)
        self.assertIn("if (search) search.value = ''", WORKSPACE)

    def test_static_preview_exposes_and_filters_the_same_search(self):
        self.assertIn("id=\"roomSearch\"", PREVIEW)
        self.assertIn("card.dataset.search", PREVIEW)
        self.assertIn("roomNoResults", PREVIEW)
        self.assertIn("aria-label=\"Find a room, guest or reservation\"", PREVIEW)


if __name__ == "__main__":
    unittest.main(verbosity=2)
