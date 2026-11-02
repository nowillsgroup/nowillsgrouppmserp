#!/usr/bin/env python3
"""Static regression checks for method-dependent cheque details in payment forms."""
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[3]


def read(path: str) -> str:
    return (ROOT / path).read_text()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SystemExit(f"FAIL: {message}")


styles = read("public/css/app.css")
require('.form-grid[data-cheque-fields][hidden]' in styles and 'display: none !important;' in styles,
        "hidden cheque grids stay hidden despite the form-grid display rule")

single = read("resources/views/components/cheque-fields.blade.php")
require('data-cheque-fields hidden' in single, "single-tender cheque fields start hidden")
require("label.includes('cheque')" in single, "single-tender method detection checks for Cheque")
require("details.hidden = !cheque" in single, "single-tender fields follow the selected method")
require("input.required = cheque" in single and "input.disabled = !cheque" in single,
        "single-tender cheque fields become required/enabled only for Cheque")
require(single.count(' disabled/>') == 3, "single-tender cheque inputs start disabled")

split_component = read("resources/views/components/payment-split-tender.blade.php")
require('data-cheque-fields hidden' in split_component, "shared split rows start with cheque fields hidden")
require("if (details) details.hidden = !cheque" in split_component,
        "shared split rows toggle cheque-field visibility")
require("input.required = cheque" in split_component and "input.disabled = !cheque" in split_component,
        "shared split rows require and enable cheque fields only for Cheque")

# Every static instance of the shared field component must have a selector in its own form,
# including pages with multiple payment forms (e.g. banquet deposit and refund).
for view_path in (ROOT / "resources/views").rglob("*.blade.php"):
    source = view_path.read_text()
    component_count = source.count("<x-cheque-fields")
    if not component_count:
        continue
    forms = re.findall(r"<form\b[\s\S]*?</form>", source, re.IGNORECASE)
    component_forms = [form for form in forms if "<x-cheque-fields" in form]
    require(sum(form.count("<x-cheque-fields") for form in component_forms) == component_count,
            f"{view_path.relative_to(ROOT)}: every shared cheque component belongs to a form")
    require(all("data-payment-mode" in form for form in component_forms),
            f"{view_path.relative_to(ROOT)}: each cheque component is paired with its own payment selector")

for path, marker in [
    ("resources/views/folios/show.blade.php", "data-folio-payment-row"),
    ("resources/views/reservations/show.blade.php", "data-advance-row"),
    ("resources/views/reservations/desk-stay.blade.php", "data-desk-payment-row"),
    ("resources/views/frontdesk/checkout.blade.php", "data-checkout-payment-row"),
]:
    source = read(path)
    require('data-cheque-fields hidden' in source, f"{path}: dynamic cheque fields start hidden")
    require(f"querySelectorAll('[{marker}]')" in source, f"{path}: dynamic rows are synchronized")
    require("details.hidden = !" in source, f"{path}: selected method controls field visibility")
    require("input.disabled = !" in source and "input.required =" in source,
            f"{path}: dynamic fields are conditionally enabled and required")

pos = read("resources/views/pos/terminal.blade.php")
require('name="payment_mode_id" label="Payment mode" type="select" :options="$paymentModes" data-payment-mode' in pos,
        "POS single tender selector is linked to cheque details")
require('<x-cheque-fields class="mt-1"/>' in pos, "POS single tender uses the shared conditional cheque fields")
require("function syncAllocationCheque(row)" in pos and "details.hidden = !cheque" in pos,
        "POS allocation rows synchronize cheque visibility")
require("input.required = cheque" in pos and "input.disabled = !cheque" in pos,
        "POS allocation cheque fields are required/enabled only for Cheque")
require('data-cheque-fields hidden' in pos, "POS split allocation cheque fields start hidden")
tender_details = re.search(r'<details class="mt-1"><summary class="small bold">Card and tender notes</summary>(.*?)</details>', pos, re.S)
require(tender_details is not None and "cheque_" not in tender_details.group(1),
        "POS general tender disclosure no longer exposes unconditional cheque inputs")

print("PASS: single, split, dynamic, refund, advance, checkout, and POS cheque visibility checks")
