#!/usr/bin/env python3
"""Upload every statement format through the real form and check what lands on the statement page.

The PHP harness (bank_formats.php) proves the readers; this one proves the whole path a finance officer walks:
Accounts > Bank Reconciliation > New statement > pick a file > Create statement. Each of the four fixtures for
Ecobank - Collections, August 2026 has to produce the same nine lines, the same money in and out, and the same
closing balance, because they all go through one importer. The statement each upload creates is deleted again
before the next one, so the account never sees a duplicate; the SWIFT MT940 import is left behind as demo evidence.

    python3 deploy/dev/audit/bank_import_flow.py
"""
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pmshttp import Pms   # noqa: E402

BASE = os.environ.get("PMS_BASE", "http://127.0.0.1:8000")
HERE = os.path.dirname(os.path.abspath(__file__))
FIX = os.path.join(HERE, "fixtures")
NEW = "/accounts/bank-reconciliation/new"
POST = "/accounts/bank-reconciliation"

# The module allows only one draft statement per account, so the format that stays behind as demo evidence and the
# three that are deleted again cannot share an account: 1021 keeps the SWIFT import, 1022 is the scratch account.
KEEPER_HEAD = "223"         # 1021 Ecobank - Collections
WORK_HEAD = "224"           # 1022 Ecobank - FF&E Reserve
ACCOUNT_HEAD = WORK_HEAD
PERIOD = ("2026-08-01", "2026-08-31")
OPENING, CLOSING = "125000.50", "117366.25"
MONEY_IN, MONEY_OUT = "59,011.25", "66,645.50"

FILES = [
    ("ecobank_aug26.csv", "CSV / tab-delimited text", "text/csv", False),
    ("ecobank_aug26.xlsx", "Excel workbook (xlsx)",
     "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", False),
    ("ecobank_aug26.camt053.xml", "ISO 20022 camt.052 / camt.053", "application/xml", False),
    ("ecobank_aug26.mt940", "SWIFT MT940 / MT942", "text/plain", True),      # left behind as demo evidence
]

fails = []
oks = 0


def check(what, got, want):
    global oks
    if got == want:
        oks += 1
        print("  ok    %-56s %s" % (what, got if not isinstance(got, bool) else "yes"))
    else:
        fails.append(what)
        print("  FAIL  %-56s got %r, wanted %r" % (what, got, want))


def lines_of(html):
    """The statement's line rows: date, description, reference, money in, money out."""
    out = []
    for m in re.finditer(r'<tr data-line="(\d+)"[^>]*>(.*?)</tr>', html, re.S):
        row = m.group(2)
        date = re.search(r'<td class="small nowrap">(.*?)</td>', row)
        cells = re.findall(r'<td class="num">(.*?)</td>', row)
        ref = re.search(r'<div class="tiny mono muted">(.*?)</div>', row)
        desc = re.sub(r"<[^>]+>", " ", row)
        out.append({"id": int(m.group(1)), "date": date.group(1).strip() if date else "",
                    "ref": ref.group(1).strip() if ref else "",
                    "in": cells[0].strip() if len(cells) > 0 else "",
                    "out": cells[1].strip() if len(cells) > 1 else "",
                    "text": re.sub(r"\s+", " ", desc).strip()})
    return out


def upload(pms, name, ctype, blob=None, label=None, head=None):
    """Fill the new-statement form and post it. Returns (status, location, statement html)."""
    form = pms.get(NEW)
    tok = Pms.token(form, r"bank-reconciliation\"")
    if not tok:
        tok = Pms.token(form)
    blob = blob if blob is not None else open(os.path.join(FIX, name), "rb").read()
    fields = {"account_head_id": head or ACCOUNT_HEAD, "period_from": PERIOD[0], "period_to": PERIOD[1],
              "opening_balance": OPENING, "closing_balance": CLOSING, "mode": "csv",
              "date_format": "auto", "notes": "Imported by the audit harness (%s)." % (label or name)}
    code, _, loc = pms.post_multipart(POST, fields, {"file": (name, blob, ctype)}, tok=tok, referer=NEW)
    loc = re.sub(r"^https?://[^/]+", "", loc or "")
    html = pms.get(loc) if loc.startswith("/accounts/bank-reconciliation/") else ""
    return code, loc, html


def drop(pms, loc):
    m = re.search(r"/accounts/bank-reconciliation/(\d+)", loc)
    if not m:
        return None
    tok = Pms.token(pms.get(loc))
    code, _, _ = pms.delete("/accounts/bank-reconciliation/%s" % m.group(1), tok=tok)
    return code


def statements(pms):
    """[(id, statement_no, account, file)] straight off the index, so a run can be repeated."""
    rows = []
    # the rendered href is absolute, and Blade leaves whitespace between the cells
    for m in re.finditer(r'<tr><td><a href="[^"]*?/accounts/bank-reconciliation/(\d+)" class="bold">(BST-\d+)</a>'
                         r'<div class="tiny muted">(.*?)</div></td>\s*<td class="small">(.*?)</td>',
                         pms.get("/accounts/bank-reconciliation"), re.S):
        # sourceLabel() appends " — SWIFT MT940 / MT942" to the file name for the formats that are not plain CSV
        rows.append({"id": m.group(1), "no": m.group(2),
                     "file": re.sub(r"<[^>]+>", "", m.group(3)).split(" — ")[0].strip(),
                     "account": re.sub(r"<[^>]+>", "", m.group(4)).strip()})
    return rows


KEEPER = next(f[0] for f in FILES if f[3])          # the MT940 statement stays as demo evidence


def cleanup(pms):
    """Delete the statements a previous run of this harness created, so the scratch account is free again.

    The keeper is left alone on purpose: it is the statement the dev-changes card deep-links to, and
    re-importing it would move it to a new id every run. When it is already there it is verified, not rebuilt.
    """
    names = {f[0] for f in FILES} - {KEEPER}
    gone = []
    for st in statements(pms):
        if st["file"] in names and st["account"][:4] in ("1021", "1022"):
            tok = Pms.token(pms.get("/accounts/bank-reconciliation/%s" % st["id"]))
            pms.delete("/accounts/bank-reconciliation/%s" % st["id"], tok=tok)
            gone.append(st["no"])
    if gone:
        print("Cleared %s left by an earlier run.\n" % ", ".join(gone))


def kept_already(pms):
    for st in statements(pms):
        if st["file"] == KEEPER and st["account"].startswith("1021"):
            return st
    return None


def head_of(keep):
    return KEEPER_HEAD if keep else WORK_HEAD


def main():
    pms = Pms(BASE)
    cleanup(pms)
    before = len(statements(pms))
    print("Uploading one statement per format to %s (%s to %s)\n"
          "  scratch account head %s (1022), the SWIFT import keeps head %s (1021)\n"
          % (BASE, *PERIOD, WORK_HEAD, KEEPER_HEAD))

    kept = None
    existing = kept_already(pms)
    for name, label, ctype, keep in FILES:
        print("%s — %s" % (label, name))
        if keep and existing:
            loc = "/accounts/bank-reconciliation/%s" % existing["id"]
            html = pms.get(loc)
            code, flash_txt = 200, "%s already imported as %s" % (label, existing["no"])
            print("  (already there from an earlier run — verifying %s rather than re-importing)" % loc)
        else:
            code, loc, html = upload(pms, name, ctype, label=label, head=head_of(keep))
            flash_txt = None
        if flash_txt is None:
            check("  posted and redirected to the statement",
                  (code, bool(re.match(r"^/accounts/bank-reconciliation/\d+$", loc))), (302, True))
        flash = flash_txt or " ".join(Pms.flash(html))
        check("  the statement says it came from this format",
              bool(re.search(r"Imported 9 line\(s\) into BST-\d+ from a %s" % re.escape(label), flash)
                   or re.search(r"%s · BST-\d+" % re.escape(label), html)
                   or re.search(r"BST-\d+ from a %s" % re.escape(label), flash)), True)
        if not html:
            print("  (no statement page)")
            continue
        print("        “%s”" % re.sub(r"\s+", " ", flash)[:220])
        rows = lines_of(html)
        check("  lines on the statement page", len(rows), 9)
        foot = re.search(r"(\d+) line\(s\)</th><th class=\"num\">(.*?)</th><th class=\"num\">(.*?)</th>", html)
        check("  footer counts the lines", foot.group(1) if foot else None, "9")
        check("  money in", foot.group(2).strip() if foot else None, MONEY_IN)
        check("  money out", foot.group(3).strip() if foot else None, MONEY_OUT)
        by_ref = {r["ref"]: r for r in rows}
        check("  the guest settlement is money in", by_ref.get("INV-4471", {}).get("in"), "18,450.00")
        check("  the bank charge is money out", by_ref.get("CHG-000881", {}).get("out"), "145.00")
        check("  both salary payments of the batch are there",
              sorted(r["date"] for r in rows if r["ref"] == "PAY-2026-08"), ["17 Aug", "17 Aug"])
        check("  the reversal reads as money coming back", by_ref.get("REV-AFD2291", {}).get("in"), "9,320.50")
        check("  the MoMo settlement kept its reference", by_ref.get("MOMO-7741203", {}).get("ref"), "MOMO-7741203")
        if (label.startswith("SWIFT") or label.startswith("ISO")) and flash_txt is None:
            check("  the page reports how the file was read",
                  bool(re.search(r"Read as %s" % re.escape(label), flash)), True)
        if keep:
            kept = loc
            print("  → kept as demo evidence: %s" % loc)
        elif flash_txt is None:
            print("  → deleted again: %s" % drop(pms, loc))
        print()

    print("Rejections — what the form does with a file it cannot read")
    # upload() follows the redirect back to the form, so `html` is the page carrying the error message
    code, loc, html = upload(pms, "legacy.xls", "application/vnd.ms-excel", head=WORK_HEAD,
                             blob=b"\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1old binary excel workbook", label="legacy.xls")
    check("  an .xls is refused without creating a statement", (code, loc), (302, "/accounts/bank-reconciliation/new"))
    check("  the reason is shown on the form",
          bool(re.search(r"Excel 97-2003.*save it as \.xlsx", " ".join(Pms.flash(html)))), True)

    code, loc, html = upload(pms, "nonsense.txt", "text/plain", head=WORK_HEAD,
                             blob=b"one,two,three\n4,5,6\n", label="nonsense.txt")
    check("  a file with no date column is refused", bool(re.search(r"No date column", " ".join(Pms.flash(html)))), True)

    after = len(statements(pms))
    check("  the list is back to where it started (plus the one kept)", after, before + (0 if existing else 1))
    print("\n%s" % (kept or ""))
    print("%d checks passed, %d failed%s" % (oks, len(fails), (": " + ", ".join(fails)) if fails else ""))
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())
