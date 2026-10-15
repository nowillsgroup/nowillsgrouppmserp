#!/usr/bin/env python3
"""Front Office & Guest Services — HTTP render sweep.

GETs every menu page of the section (params substituted from the registry),
asserts the structural fingerprint we already enforce elsewhere (leading
doctype, exactly one <html>/<body>) and records how much real content the page
carries: table rows, cards, forms, write buttons, and any empty-state text.

Usage:  python3 deploy/dev/audit/http_sweep.py > deploy/dev/audit/fo_http.json
"""
import json, os, re, sys, urllib.request, urllib.error, collections

ROOT = "/home/user/nowillsgrouppmserp"
BASE = os.environ.get("PMS_BASE", "http://127.0.0.1:8000")
routes = json.load(open("/tmp/routes.json"))
static = json.load(open("/tmp/fo_static.json"))
by_name = {r["name"]: r for r in routes if r.get("name")}

EMPTY_PHRASES = [
    "no records", "nothing to show", "no data", "is empty", "none yet", "no results",
    "nothing here", "no guests", "no items", "no rows", "empty state", "nothing found",
    "no enquiries", "no bookings", "no devices", "no messages", "no calls", "no traces",
]

results = []
for row in static["rows"]:
    rn = row["route"]
    r = by_name.get(rn)
    if not r:
        results.append({**{k: row.get(k) for k in ("module", "page")}, "url": None, "status": "NO ROUTE"})
        continue
    uri = "/" + r["uri"].lstrip("/")
    uri = uri.replace("{master}", "currencies")
    for ph, val in (("{master}", "currencies"),):
        uri = uri.replace(ph, val)
    if "{" in uri:                      # any other parameter -> skip with a note
        results.append({**{k: row.get(k) for k in ("module", "page")}, "url": uri,
                        "status": "PARAMETERISED"})
        continue
    url = BASE + uri
    try:
        with urllib.request.urlopen(url, timeout=240) as resp:
            code = resp.status
            body = resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        code, body = e.code, e.read().decode("utf-8", "replace")
    except Exception as e:               # noqa: BLE001
        results.append({**{k: row.get(k) for k in ("module", "page")}, "url": url,
                        "status": "ERROR", "error": str(e)[:160]})
        continue
    head = body.lstrip()[:60].lower()
    txt = re.sub(r"<script.*?</script>", " ", body, flags=re.S | re.I)
    txt = re.sub(r"<style.*?</style>", " ", txt, flags=re.S | re.I)
    plain = re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", txt)).lower()
    rec = {
        "module": row["module"], "page": row["page"], "url": url, "status": code,
        "bytes": len(body),
        "doctype_first": head.startswith("<!doctype"),
        "html_tags": len(re.findall(r"<html[\s>]", body, re.I)),
        "body_tags": len(re.findall(r"<body[\s>]", body, re.I)),
        "table_rows": len(re.findall(r"<tr[\s>]", body, re.I)),
        "tables": len(re.findall(r"<table[\s>]", body, re.I)),
        "forms": len(re.findall(r"<form[\s>]", body, re.I)),
        "submits": len(re.findall(r'type="submit"', body, re.I)),
        "links": len(re.findall(r"<a\s", body, re.I)),
        "empty_phrases": sorted({p for p in EMPTY_PHRASES if p in plain}),
        "error_page": bool(re.search(r"whoops|server error|500\s*—|exception", plain[:4000])),
    }
    results.append(rec)
    sys.stderr.write("%s %s %s (%s bytes, %s rows)\n" % (rec["status"], rec["url"], rec["page"],
                                                          rec["bytes"], rec["table_rows"]))
    sys.stderr.flush()

json.dump(results, open(os.path.join(ROOT, "deploy/dev/audit/fo_http.json"), "w"), indent=1)
bad = [r for r in results if r.get("status") != 200]
struct = [r for r in results if r.get("status") == 200 and
          (not r.get("doctype_first") or r.get("html_tags") != 1 or r.get("body_tags") != 1)]
print(json.dumps({"total": len(results), "non_200": bad, "structural_defects": struct,
                  "empty_pages": [{"module": r["module"], "page": r["page"], "rows": r["table_rows"],
                                   "phrases": r["empty_phrases"]}
                                  for r in results if r.get("status") == 200 and r.get("table_rows", 0) <= 1
                                  and r.get("empty_phrases")]}, indent=1))
