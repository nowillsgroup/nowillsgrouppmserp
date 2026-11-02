#!/usr/bin/env python3
"""Static regression checks for method-dependent Mobile Money tender fields."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[3]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(f"FAIL: {message}")


single = read("resources/views/components/payment-momo-fields.blade.php")
require("label=\"MoMo Network\" type=\"select\"" in single, "single tender uses a MoMo network dropdown")
require("'MTN' => 'MTN MoMo'" in single and "'Telecel' => 'Telecel Cash'" in single and "'AirtelTigo' => 'AT Money'" in single,
        "dropdown includes Ghana's MTN, Telecel and AT Money networks")
require('label="MoMo number" type="tel"' in single, "single tender collects a telephone-style MoMo number")
require("label.includes('momo') || label.includes('mobile money')" in single,
        "single-tender details follow the selected Mobile Money payment method")
require("control.required = isMomo" in single and "control.disabled = !isMomo" in single,
        "single-tender fields are required and enabled only for Mobile Money")

split = read("resources/views/components/payment-split-tender.blade.php")
require('data-single-momo-template' in split and 'anchor.before(singleMomo)' in split,
        "shared split-payment forms place MoMo details before their reference field")
require('data-split-momo-field hidden' in split and "syncMomo" in split,
        "split rows start hidden and synchronize MoMo details to the chosen method")
require('name="payments[__INDEX__][momo_network]"' in split and 'name="payments[__INDEX__][momo_phone]"' in split,
        "split details submit the network and number")

styles = read("public/css/app.css")
require('.form-grid[data-momo-fields][hidden]' in styles and '[data-momo-fields][hidden]' in styles
        and '[data-split-momo-field][hidden]' in styles and '.form-grid[data-pos-momo-fields][hidden]' in styles,
        "hidden MoMo form grids and fields stay hidden despite display rules")

for path in [
    "resources/views/frontdesk/checkin.blade.php",
    "resources/views/frontdesk/walkin.blade.php",
    "resources/views/frontdesk/checkout.blade.php",
    "resources/views/reservations/form.blade.php",
    "resources/views/reservations/show.blade.php",
    "resources/views/reservations/desk-stay.blade.php",
    "resources/views/folios/show.blade.php",
    "resources/views/club/desk.blade.php",
    "resources/views/club/enrol.blade.php",
    "resources/views/club/passes.blade.php",
    "resources/views/laundry/show.blade.php",
    "resources/views/parking/session.blade.php",
    "resources/views/spa/index.blade.php",
    "resources/views/transport/my.blade.php",
    "resources/views/transport/show.blade.php",
]:
    require("<x-payment-momo-fields" in read(path), f"{path}: single tender includes conditional MoMo fields")

for path, row_marker in [
    ("resources/views/folios/show.blade.php", "data-folio-momo-field"),
    ("resources/views/reservations/show.blade.php", "data-advance-momo-field"),
    ("resources/views/reservations/desk-stay.blade.php", "data-desk-momo-field"),
    ("resources/views/frontdesk/checkout.blade.php", "data-checkout-momo-field"),
]:
    source = read(path)
    require(row_marker in source, f"{path}: dynamic split rows have conditional MoMo fields")
    require("label.includes('momo') || label.includes('mobile money')" in source,
            f"{path}: selected Mobile Money method controls split-row visibility")
    require("control.required = momo" in source and "control.disabled = !momo" in source,
            f"{path}: Mobile Money details are required only for MoMo tenders")
    require("Reference / transaction ID" in source, f"{path}: transaction reference remains after MoMo details")

pos = read("resources/views/pos/terminal.blade.php")
require('id="stlMomoFields" data-pos-momo-fields hidden' in pos,
        "POS single-tender MoMo details start hidden")
require('name="mobile_network"' in pos and 'name="mobile_phone"' in pos,
        "POS preserves its existing server-side Mobile Money field names")
require('data-allocation-momo hidden' in pos and 'syncAllocationMomo' in pos,
        "POS split allocations conditionally capture MoMo details")
require('Reference / transaction ID' in pos, "POS reference follows the MoMo network and number")

print("PASS: single, shared split, folio, reservation, front-desk, POS, and hidden-state MoMo field checks")
