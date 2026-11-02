#!/usr/bin/env python3
"""Static regression checks for the real reservation availability workspace."""
from pathlib import Path
import re
import sys

root = Path(__file__).resolve().parents[3]
view_path = root / "resources/views/reservations/availability.blade.php"
reservations_index_path = root / "resources/views/reservations/index.blade.php"
icons_path = root / "resources/views/partials/icons.blade.php"
view = view_path.read_text()
reservations_index = reservations_index_path.read_text()
icons = set(re.findall(r'<symbol id="i-([^"]+)"', icons_path.read_text()))
used_icons = set(re.findall(r'<x-icon\s+name="([^"]+)"', view))

checks = {
    "renders within the real PMS application shell": "@extends('layouts.app')" in view,
    "keeps the live availability matrix and date-window inputs": all(token in view for token in ["Availability by room type", 'name="from"', 'name="days"', "$matrix[$type->id][$dateKey]"]),
    "waitlist availability shortcut carries its arrival date into the matrix": "route('reservations.availability', ['from' => $date->toDateString()])" in reservations_index,
    "supports accessible room-type filtering while preserving the total row": all(token in view for token in ['id="availabilityRoomTypeFilter"', 'data-availability-type', 'id="availabilityNoMatch"', 'class="av-total-row"']),
    "preserves permission-gated reservation links for positive inventory only": "@if($count > 0 && can_do('reservations.create'))" in view and "route('reservations.create'" in view,
    "keeps final server-side availability recheck guidance": "Final reservation creation rechecks availability before saving." in view,
    "provides accessible table headers, caption and scroll-region instructions": all(token in view for token in ["<caption class=\"sr-only\">", 'scope="col"', 'scope="row"', 'role="region"', 'aria-label="Room availability matrix.' ]),
    "includes empty-inventory guidance": "No sellable room types found" in view,
    "includes dark-mode and responsive layouts": "body.dark .availability-table" in view and "@media(max-width:600px)" in view,
    "uses only icons available in the PMS sprite": not (used_icons - icons),
}

failed = [label for label, passed in checks.items() if not passed]
for label, passed in checks.items():
    print(f"{'PASS' if passed else 'FAIL'} {label}")
print(f"Completed {len(checks)} availability-page checks; {len(failed)} failed.")
if failed:
    sys.exit(1)
