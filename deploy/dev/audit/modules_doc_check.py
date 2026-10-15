#!/usr/bin/env python3
"""Check docs/MODULES.md against the live config('pms.menu') registry, line by line.

The document was written from the v7.36-v7.38 menu and its own header admits the per-module lists are
a historical inventory, so this is the drift gate: it parses every claim the document makes — the
header table, the version, each group, its section key, its module count, each module, whether it is a
direct link or a page list, how many pages it claims and which ones it names — and diffs them against
what the running application actually menus.

    python3 deploy/dev/audit/modules_doc_check.py [path/to/menu_live.json] [path/to/routes.json]

With no arguments it dumps the live registry itself (deploy/dev/audit/menu_inventory.php) and, when
/tmp/routes.json is present, checks the URIs the prose names against the route list as well.

Exits non-zero on any discrepancy, so it can be run after a menu change to catch a document that has
gone stale. Deliberate differences — pages that are routed but not menued, which the document records
as prose notes under the module — are listed separately and are not failures.
"""
import json
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
DOC = os.path.join(HERE, "..", "..", "..", "docs", "MODULES.md")   # deploy/dev/audit → repo root
LIVE = sys.argv[1] if len(sys.argv) > 1 else "/tmp/menu_live.json"
ROUTES = sys.argv[2] if len(sys.argv) > 2 else "/tmp/routes.json"

fails = []
notes = []
oks = 0


def check(what, got, want):
    global oks
    if got == want:
        oks += 1
        return True
    fails.append("%-58s doc says %r, menu says %r" % (what, got, want))
    return False


def norm(name):
    """Compare page names on their letters, not on the bold and parentheticals the doc adds."""
    n = re.sub(r"\*\*", "", name)
    n = re.sub(r"\[([^\]]*)\]\([^)]*\)", r"\1", n)   # a markdown link keeps its text
    # "(v7.43, Act 601 / Act 1142 calendar)" — balanced, so a parenthetical may hold another one
    while True:
        m = re.search(r"\(([^()]*)\)", n)
        if not m:
            break
        n = n[:m.start()] + " " + n[m.end():]
    n = n.replace("–", "-").replace("—", "-").replace("&", "and")
    return re.sub(r"\s+", " ", n).strip().lower()


def parse_doc(text):
    """Groups in order, each with its section key, module count claim and modules in order."""
    groups = []
    prose = []
    cur = None
    mod = None
    for raw in text.splitlines():
        line = raw.rstrip()
        if line.startswith("## ") and not line.startswith("### "):
            cur = {"label": line[3:].strip(), "section": None, "modules_claimed": None, "modules": []}
            groups.append(cur)
            mod = None
            continue
        if line.startswith("### "):               # a module the doc keeps under a neighbouring group
            m = re.match(r"###\s+(.*?)\s+\(`(\w+)`\)", line)
            if m and cur is not None:
                cur.setdefault("subsections", []).append({"label": m.group(1), "section": m.group(2)})
            mod = None
            continue
        if cur is None:
            continue
        m = re.match(r"`(\w+)` section · \*\*(\d+) modules?\*\*", line)
        if m:
            cur["section"] = m.group(1)
            cur["modules_claimed"] = int(m.group(2))
            continue
        m = re.match(r"- \*\*(.+?)\*\* — (.+)$", line)
        if m:
            label, rest = m.group(1), m.group(2)
            if rest.strip().lower().startswith("direct link"):
                mod = {"label": label, "link": True, "claimed": 0, "pages": [], "note": rest}
            else:
                n = re.match(r"(\d+) pages?", rest)
                mod = {"label": label, "link": False, "claimed": int(n.group(1)) if n else None,
                       "pages": [], "note": rest}
            cur["modules"].append(mod)
            continue
        m = re.match(r"- (?!\*\*)(.+)$", line)
        if m:                                    # a group-level prose bullet, e.g. "Routed but not menued: …"
            prose.append(m.group(1).strip())
            continue
        m = re.match(r"\s+- (.+)$", line)
        if m and mod is not None:
            if not mod["pages"]:                 # the first sub-bullet is the page list…
                mod["pages"] = [p.strip() for p in m.group(1).split("·") if p.strip()]
            else:                                # …anything after it is a prose note
                notes.append("%s › %s: %s" % (cur["label"], mod["label"], m.group(1).strip()[:150]))
    return groups, prose


ROOT = os.path.abspath(os.path.join(HERE, "..", "..", ".."))


def menu_json():
    """The live registry, generated if it is not on disk: this harness is one command, not two.

    It is also regenerated when config/pms.php is newer than the dump. A dump is derived from that file, and reusing a
    stale one would diff the document against a menu that is no longer the one that ships — the exact drift this harness
    exists to catch, reported as a clean run. That is what happened when the version stamp moved to 7.50.0 and the check
    kept insisting the menu said 7.38.0.
    """
    cfg = os.path.join(ROOT, "config", "pms.php")
    if os.path.exists(LIVE) and (not os.path.exists(cfg) or os.path.getmtime(LIVE) >= os.path.getmtime(cfg)):
        return json.load(open(LIVE))
    if os.path.exists(LIVE):
        print("  (config/pms.php is newer than %s — re-dumping the live menu registry)" % LIVE)
    import subprocess
    env = dict(os.environ, TINKER='putenv("MENU_JSON=1"); require "/app/deploy/dev/audit/menu_inventory.php";')
    out = subprocess.run([os.path.join("deploy", "dev", "bin", "php"), "-f", os.path.join("deploy", "dev", "tinker.php")],
                         cwd=ROOT, env=env, capture_output=True, text=True)
    if out.returncode != 0 or not out.stdout.lstrip().startswith("{"):
        sys.exit("could not dump the menu registry:\n%s\n%s" % (out.stdout[:400], out.stderr[:400]))
    json.dump(json.loads(out.stdout), open(LIVE, "w"))
    print("  (dumped the live menu registry to %s)" % LIVE)
    return json.load(open(LIVE))


def main():
    live = menu_json()
    text = open(DOC, encoding="utf-8").read()
    doc, prose = parse_doc(text)

    print("Comparing docs/MODULES.md with the live menu registry (config('pms.version') = %s)\n" % live["version"])

    # ── the header table ──
    head = {label: int(num) for num, label in
            re.findall(r"\| \*\*(\d+)\*\* \| (sections|top-level groups|modules|pages) \|", text)}
    want = {"sections": live["sections"], "top-level groups": live["groups"],
            "modules": live["modules"], "pages": live["pages"]}
    for k, v in want.items():
        check("header table: %s" % k, head.get(k, -1), v)

    m = re.search(r"\*\*Version ([\d.]+?)\.?\*\*", text)   # the trailing full stop may sit outside the bold
    check("header: version the document claims", m.group(1) if m else None, live["version"])

    # ── groups ──
    live_groups = live["tree"]
    check("number of group headings", len(doc), len(live_groups))
    doc_labels = [g["label"] for g in doc]
    live_labels = [g["label"] for g in live_groups]
    check("group headings, in order", doc_labels, live_labels)

    for lg in live_groups:
        dg = next((g for g in doc if g["label"] == lg["label"]), None)
        if dg is None:
            fails.append("group %r is in the menu but the document has no heading for it" % lg["label"])
            continue
        check("%s: section key" % lg["label"], dg["section"], lg["section"])
        check("%s: module count" % lg["label"], dg["modules_claimed"], len(lg["modules"]))
        check("%s: module names, in order" % lg["label"], [m["label"] for m in dg["modules"]],
              [m["label"] for m in lg["modules"]])

        for lm in lg["modules"]:
            dm = next((m for m in dg["modules"] if m["label"] == lm["label"]), None)
            if dm is None:
                fails.append("%s › %s: in the menu, not in the document" % (lg["label"], lm["label"]))
                continue
            tag = "%s › %s" % (lg["label"], lm["label"])
            check("%s: direct link or page list" % tag, dm["link"], bool(lm["link"]))
            check("%s: page count" % tag, dm["claimed"], len(lm["pages"]))
            live_pages = [norm(p["label"]) for p in lm["pages"]]
            doc_pages = [norm(p) for p in dm["pages"]]
            check("%s: page names, in order" % tag, doc_pages, live_pages)
            extra = [p for p in doc_pages if p not in live_pages]
            gone = [p for p in live_pages if p not in doc_pages]
            if extra:
                fails.append("%s: the document names %d page(s) the menu does not have: %s"
                             % (tag, len(extra), ", ".join(extra)))
            if gone:
                fails.append("%s: the menu has %d page(s) the document does not name: %s"
                             % (tag, len(gone), ", ".join(gone)))

    # ── modules the document keeps under a neighbouring heading ──
    for dg in doc:
        for sub in dg.get("subsections", []):
            found = [g for g in live_groups if any(m["label"] == sub["label"] for m in g["modules"])]
            if not found:
                fails.append("%s: the document lists a module the menu does not have" % sub["label"])
            elif found[0]["label"] != dg["label"]:
                notes.append("%s is menued under %s but the document keeps it under %s"
                             % (sub["label"], found[0]["label"], dg["label"]))

    # ── permissions ──
    check("nodes with no perm key", live["no_perm"], [])

    # ── the prose notes: a page the document calls "routed but not menued" has to be routed ──
    if os.path.exists(ROUTES):
        routes = json.load(open(ROUTES))
        uris = {r["uri"].lstrip("/") for r in routes}
        claimed = set()
        for n in notes + prose:
            for uri in re.findall(r"`(/[^`]*)`", n):
                claimed.add(uri.lstrip("/"))
        for uri in sorted(claimed):
            pat = re.compile("^" + re.sub(r"\{[^}]+\}", "[^/]+", re.escape(uri).replace(r"\{", "{").replace(r"\}", "}")) + "$")
            hit = any(pat.match(u) for u in uris) or uri in uris
            check("prose claims a real route: /%s" % uri, hit, True)
        print("  (%d URI(s) named in prose checked against %d routes)" % (len(claimed), len(uris)))
    else:
        print("  (no %s — the URIs named in prose were not checked)" % ROUTES)

    if notes:
        print("Documented as prose, not counted as drift:")
        for n in notes:
            print("  · %s" % n)
        print()
    print("%d checks passed, %d discrepancies" % (oks, len(fails)))
    for f in fails:
        print("  ✗ %s" % f)
    return 1 if fails else 0


if __name__ == "__main__":
    sys.exit(main())
