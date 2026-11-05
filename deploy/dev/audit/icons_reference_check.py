#!/usr/bin/env python3
"""Every icon the interface asks for must exist in the SVG sprite.

`<x-icon name="truck"/>` renders `<use href="#i-truck"/>`. When the name has no
`<symbol id="i-truck">` in resources/views/partials/icons.badge the element is
simply blank — no error, no warning, just a gap in the button. Six icons had
already gone missing that way (external, info, list, settings, truck, upload), so
this harness keeps the sprite and every source of icon names in step:

  * static `<x-icon name="…">` usages in the views,
  * `'icon' => '…'` values in config/pms.php (the sidebar / module registry),
  * the icon maps the models expose for dynamic names
    (Activity::ICONS, ConciergePlace::ICONS, Conversation::CHANNEL_ICONS,
    ReservationWorkspaceService::AMENITY_ICONS).

Run: python3 deploy/dev/audit/icons_reference_check.py
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[3]
SPRITE = ROOT / "resources/views/partials/icons.blade.php"

ICON_MAPS = (
    ("app/Models/Activity.php", "ICONS"),
    ("app/Models/ConciergePlace.php", "ICONS"),
    ("app/Models/Conversation.php", "CHANNEL_ICONS"),
    ("app/Services/ReservationWorkspaceService.php", "AMENITY_ICONS"),
)


def sprite_symbols():
    return set(re.findall(r'<symbol id="i-([a-z0-9\-]+)"', SPRITE.read_text()))


def static_usages():
    used = {}
    for path in sorted((ROOT / "resources/views").rglob("*.blade.php")):
        for name in re.findall(r'<x-icon[^>]*\bname="([a-z0-9\-]+)"', path.read_text(errors="ignore")):
            used.setdefault(name, set()).add(str(path.relative_to(ROOT)))
    return used


def config_icons():
    return set(re.findall(r"'icon'\s*=>\s*'([a-z0-9\-]+)'", (ROOT / "config/pms.php").read_text()))


def map_icons(rel, const):
    source = (ROOT / rel).read_text()
    match = re.search(r"const\s+" + const + r"\s*=\s*\[(.*?)\];", source, re.S)
    if not match:
        return set()
    return set(re.findall(r"=>\s*'([a-z0-9\-]+)'", match.group(1)))


class IconReferenceTest(unittest.TestCase):
    def test_sprite_is_not_empty(self):
        self.assertGreater(len(sprite_symbols()), 50)

    def test_every_static_icon_usage_has_a_symbol(self):
        symbols = sprite_symbols()
        missing = {name: paths for name, paths in static_usages().items() if name not in symbols}
        detail = "\n".join(f"{name}: {sorted(paths)[:3]}" for name, paths in sorted(missing.items()))
        self.assertEqual(missing, {}, f"icons used in views but absent from the sprite:\n{detail}")

    def test_every_configured_menu_icon_has_a_symbol(self):
        symbols = sprite_symbols()
        self.assertEqual(sorted(config_icons() - symbols), [])

    def test_every_dynamic_icon_map_has_a_symbol(self):
        symbols = sprite_symbols()
        for rel, const in ICON_MAPS:
            with self.subTest(map=f"{rel}:{const}"):
                self.assertEqual(sorted(map_icons(rel, const) - symbols), [])


if __name__ == "__main__":
    unittest.main(verbosity=2)
