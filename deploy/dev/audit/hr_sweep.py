#!/usr/bin/env python3
"""HR & Workforce — HTTP render sweep of every menu page of the three HR modules.

GETs the 36 menu pages resolved in deploy/dev/audit/hr_pages.json, asserts the
structural fingerprint enforced elsewhere in this repo (leading doctype, exactly
one <html>/<body>), records how much real content each page carries (tables,
rows, forms, write buttons, links), and hunts for stub markers (TODO / not
implemented / coming soon / lorem) so a "fully developed" claim is evidenced.

Usage:  python3 deploy/dev/audit/hr_sweep.py
"""
import json, os, re, sys, urllib.request, urllib.error

ROOT = "/home/user/nowillsgrouppmserp"
BASE = os.environ.get("PMS_BASE", "http://127.0.0.1:8000")
PAGES = json.load(open(os.path.join(ROOT, "deploy/dev/audit/hr_pages.json")))

EMPTY_PHRASES = [
    "no records", "nothing to show", "no data", "is empty", "none yet", "no results",
    "nothing here", "no employees", "no items", "no rows", "empty state", "nothing found",
    "no applicants", "no vacancies", "no leave", "no shifts", "no payslips", "no claims",
    "no courses", "no sessions", "no reminders", "no one", "nothing",
]
STUB_MARKERS = [
    "todo:", "not implemented", "coming soon", "lorem ipsum", "placeholder text",
    "under construction", "work in progress", "stub", "unimplemented",
]

results = []
for row in PAGES:
    uri = row["uri"]
    if "{" in uri:
        results.append({**row, "url": BASE + uri, "status": "PARAMETERISED"})
        continue
    url = BASE + uri
    try:
        with urllib.request.urlopen(url, timeout=240) as resp:
            code, body = resp.status, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        code, body = e.code, e.read().decode("utf-8", "replace")
    except Exception as e:                                   # noqa: BLE001
        results.append({**row, "url": url, "status": "ERROR", "error": str(e)[:160]})
        continue
    head = body.lstrip()[:60].lower()
    txt = re.sub(r"<script.*?</script>", " ", body, flags=re.S | re.I)
    txt = re.sub(r"<style.*?</style>", " ", txt, flags=re.S | re.I)
    plain = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", txt)).lower()
    rec = {
        "module": row["module"], "page": row["label"], "url": url, "perm": row.get("perm"),
        "status": code, "bytes": len(body),
        "doctype_first": head.startswith("<!doctype"),
        "html_tags": len(re.findall(r"<html[\s>]", body, re.I)),
        "body_tags": len(re.findall(r"<body[\s>]", body, re.I)),
        "tables": len(re.findall(r"<table[\s>]", body, re.I)),
        "table_rows": len(re.findall(r"<tr[\s>]", body, re.I)),
        "cards": len(re.findall(r'class="[^"]*\bcard\b', body, re.I)),
        "forms": len(re.findall(r"<form[\s>]", body, re.I)),
        "submits": len(re.findall(r'type="submit"', body, re.I)),
        "buttons": len(re.findall(r"<button[\s>]", body, re.I)),
        "inputs": len(re.findall(r"<(?:input|select|textarea)[\s>]", body, re.I)),
        "links": len(re.findall(r"<a\s", body, re.I)),
        "empty_phrases": sorted({p for p in EMPTY_PHRASES if p in plain}),
        "stub_markers": sorted({m for m in STUB_MARKERS if m in plain}),
        "error_page": bool(re.search(r"whoops|server error|500\s*—|exception", plain[:4000])),
        "denied": bool(re.search(r"you do not have|not allowed|forbidden|403", plain[:2000])),
    }
    results.append(rec)
    sys.stderr.write("%s %-42s %-24s %7s bytes %3s rows %2s forms%s\n" % (
        rec["status"], rec["url"], rec["page"], rec["bytes"], rec["table_rows"], rec["forms"],
        "  STUB:" + ",".join(rec["stub_markers"]) if rec["stub_markers"] else ""))
    sys.stderr.flush()

out = os.path.join(ROOT, "deploy/dev/audit/hr_http.json")
json.dump(results, open(out, "w"), indent=1)
bad = [r for r in results if r.get("status") != 200]
struct = [r for r in results if r.get("status") == 200 and
          (not r.get("doctype_first") or r.get("html_tags") != 1 or r.get("body_tags") != 1)]
stubs = [r for r in results if r.get("stub_markers")]
thin = [{"page": r["page"], "url": r["url"], "bytes": r["bytes"], "rows": r["table_rows"],
         "phrases": r["empty_phrases"]} for r in results
        if r.get("status") == 200 and r.get("table_rows", 0) <= 1 and r.get("empty_phrases")]
print(json.dumps({"total": len(results), "http_200": sum(1 for r in results if r.get("status") == 200),
                  "non_200": bad, "structural_defects": struct, "stub_pages": stubs,
                  "empty_or_thin_pages": thin}, indent=1))
