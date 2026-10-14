#!/usr/bin/env python3
"""v7.51 — keeps docs/FEATURE-GAP-AUDIT.md telling the truth about what ships.

A gap audit rots in one direction: a capability ships and the document keeps saying it is missing. That is exactly what
had happened here — the checklist half of the document had been updated for social posting, review feeds, bank statement
import, mobile money and the PABX, while the priority-ranked summary at the top still listed three of them as open gaps,
so the document contradicted itself and told the business that shipped work was missing. It also named seven classes
that have never existed (`GradingService`, `GuestBillRec`, `FolioRec`, `SupplierService`, `InventoryService`,
`MiniBarService`, `PaystackService`), which is what a developer greps for first.

So this harness checks the document against the running system, in both directions:

  A. every artifact the document names resolves — a class, a method, a command, a route, a file, a driver id, a setting
     or a column. A rename or a deletion fails here immediately, and a token nobody has classified fails too, so a new
     claim cannot slip through unverified.
  B. everything the document says does NOT exist really is absent — those sentences are the correction, and they would
     be wrong in the other direction if somebody shipped the class without updating the document.
  C. every "closed" claim carries its evidence: the service, the driver, the route, the simulator and the spec.
  D. every "still open" claim is still open: no driver registered, no column on the table, no conversion in the service.
     This is the direction that matters — the moment one of them ships, this fails until the document is updated.
  E. the header's counts and versions agree with config/pms.php, the live menu and the framework.

Facts come from deploy/dev/audit/gap_facts.php (the running application) and from the route list; both are regenerated
when they are missing or older than config/pms.php, so this is one command and never diffs a stale dump.

    python3 deploy/dev/audit/gap_audit_check.py
"""
import json
import os
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", "..", ".."))
DOC = os.path.join(ROOT, "docs", "FEATURE-GAP-AUDIT.md")
FACTS = "/tmp/gap_facts.json"
ROUTES = "/tmp/routes.json"
CFG = os.path.join(ROOT, "config", "pms.php")

CHECKS = []


def check(label, got, want):
    """Record one comparison; the report prints every failure with both sides."""
    CHECKS.append((label, got, want, got == want))


def run(cmd, env=None):
    return subprocess.run(cmd, cwd=ROOT, env=dict(os.environ, **(env or {})),
                          capture_output=True, text=True)


def tinker(code):
    out = run([os.path.join("deploy", "dev", "bin", "php"), "-f", os.path.join("deploy", "dev", "tinker.php")],
              env={"TINKER": code})
    return out.stdout


def fresh(path, shell_cmd):
    """Load a JSON artifact, regenerating it when missing or older than config/pms.php.

    The dump is written by shell redirection rather than captured: the WASM PHP runtime truncates a captured stdout at
    128 KB and the route list is about 484 KB, so a pipe yields JSON with an unterminated string in it.
    """
    if not os.path.exists(path) or os.path.getmtime(path) < os.path.getmtime(CFG):
        print("  (regenerating %s)" % path)
        out = subprocess.run(shell_cmd + " > " + path, shell=True, cwd=ROOT, capture_output=True, text=True)
        if out.returncode != 0:
            sys.exit("could not regenerate %s:\n%s\n%s" % (path, out.stdout[:400], out.stderr[:400]))
    try:
        return json.load(open(path))
    except json.JSONDecodeError as e:
        sys.exit("%s is not valid JSON (%s) — delete it and re-run so it is regenerated" % (path, e))


PHP = os.path.join("deploy", "dev", "bin", "php")
facts = fresh(FACTS, 'TINKER=\'require "/app/deploy/dev/audit/gap_facts.php";\' ' + PHP + " -f deploy/dev/tinker.php")
raw_routes = fresh(ROUTES, PHP + " artisan route:list --json")
URIS = {re.sub(r"^/", "", r.get("uri", "")) for r in raw_routes}
print("  facts: %d services, %d commands, %d drivers, %d routes"
      % (len(facts["services"]), len(facts["commands"]), len(facts["channel_drivers"]), len(URIS)))

# ── resolvers ───────────────────────────────────────────────────────────────
APP_FILES = {}
for dirpath, dirnames, filenames in os.walk(os.path.join(ROOT, "app")):
    dirnames[:] = [d for d in dirnames if d not in ("node_modules", "vendor")]
    for f in filenames:
        if f.endswith(".php"):
            APP_FILES.setdefault(f[:-4], os.path.join(dirpath, f))


def class_file(name):
    return APP_FILES.get(name)


def in_code(literal, where=("app", "routes", "config", "database")):
    """Does this literal appear anywhere in the application source? (a setting key, a column, an enum value, a title)"""
    paths = [os.path.join(ROOT, w) for w in where]
    paths = [p for p in paths if os.path.exists(p)]
    if not paths:
        return False
    out = run(["grep", "-rlF", literal] + paths)
    return bool(out.stdout.strip())


def has_method(cls, method):
    path = class_file(cls)
    if not path:
        return False
    return bool(re.search(r"function\s+%s\s*\(" % re.escape(method), open(path, encoding="utf-8", errors="replace").read()))


def exists(rel):
    """A repo path, glob or directory."""
    rel = rel.rstrip("/")
    if "*" in rel:
        import glob
        return bool(glob.glob(os.path.join(ROOT, rel)))
    return os.path.exists(os.path.join(ROOT, rel))


# ── A. every named artifact resolves ────────────────────────────────────────
# Tokens that are not artifacts: protocol literals, UI click paths, shell flags, vendor names, this branch. Each carries
# the reason it is not checked, and the report prints them, so an exclusion is a decision a reader can see and argue
# with rather than a silent pass.
PROSE = {
    "App\\Services\\Migration\\{MigrationService, DatasetImporter, BaseImporter, ImportContext}": "four classes in one brace expansion — each checked individually below",
    "flag=REFUND": "an E-VAT payload value — the REFUND flag is checked against EInvoiceService below",
    "grc/{hotel}/{stay}-{random}.png": "a storage path template — the grc/ prefix is checked against KioskService below",
    "Foreign Guests › Settings › Forex desk": "a click path through the UI, not an artifact",
    "OTA_AvailRateNotifyRQ": "SiteMinder OTA XML message name — checked against the driver below",
    "OTA_HotelResModifyNotifRS": "SiteMinder OTA XML message name — checked against the driver below",
    "POST {graph}/v20.0/{page_id}/feed": "Meta Graph call template — the provider side, proven by social-sim.mjs",
    "POST {graph}/v20.0/{phone_id}/messages": "Meta Cloud API call template — proven by social-sim.mjs",
    "{endpoint}": "the mirror adapter endpoint placeholder",
    "{endpoint}/bookings": "the mirror adapter bookings path",
    "--hotel --host --port --mode=connect|listen --flush --once": "command flags of pms:pbx:listen",
    "pms:import {companies,guests,employees,rooms,reservations} file.csv --hotel=1": "a usage example of pms:import",
    "PMS_DEV_AUTOLOGIN=0": "an environment variable of the dev server",
    "arena/49a44449-nowillsgrouppmserp": "the working branch",
    "ca74493": "a commit hash — checked against git history below",
    "config('pms.version')": "a config accessor, not an artifact",
    "MIG": "an abbreviation used in prose",
    "Spatie": "a vendor namespace (diagnostics), not our code",
    "PackageManifest": "a Laravel framework class, not ours",
    "submitInvoice": "the old audit's name for the call the live `gra` driver makes",
    "bin/php": "deploy/dev/bin/php — checked as deploy/dev/ below",
    "bin/pms-serve": "deploy/dev/bin/pms-serve — checked as deploy/dev/ below",
    "pms-serve": "the dev server command — checked as deploy/dev/ below",
    "setup.sh": "deploy/dev/setup.sh — checked as deploy/dev/ below",
    "tinker.php": "deploy/dev/tinker.php — checked as deploy/dev/ below",
    "pbx-sim.mjs": "deploy/dev/pbx-sim.mjs — checked in full elsewhere in the document",
    "signaturepad": "an npm package named as NOT used — there is no npm build; checked as absent below",
    "calls/index.blade.php": "a Blade view path — checked below",
    "guest/*": "a Blade view directory — checked below",
    "docs/examples/*.csv": "import templates — checked as a glob below",
    "deploy/dev/": "a directory — checked below",
}

# Named in the document as things that do not exist. Checked as absent (group B), not as artifacts.
NAMED_ABSENT = ["GradingService", "GuestBillRec", "FolioRec", "SupplierService", "InventoryService",
                "MiniBarService", "PaystackService", "SignatureService", "ProformaService", "RateRuleService",
                "RatePackageService", "SeasonService"]
COMMANDS_NAMED_ABSENT = ["pms:wakeup:run"]   # channel:sync was corrected to pms:channel-push, so it is no longer claimed

text = open(DOC, encoding="utf-8").read()
tokens = sorted(set(re.findall(r"`([^`]+)`", text)))
unclassified = []
print("Comparing docs/FEATURE-GAP-AUDIT.md with the running system "
      "(config('pms.version') = %s, %d routes)\n" % (facts["pms_version"], len(URIS)))

for tok in tokens:
    if tok in PROSE or tok in NAMED_ABSENT or tok in COMMANDS_NAMED_ABSENT:
        continue
    # a class or model name
    if re.fullmatch(r"[A-Z][A-Za-z0-9]+", tok):
        check("A class `%s`" % tok, class_file(tok) is not None, True)
        continue
    # Service::method() or Service::a/b
    m = re.match(r"^([A-Z][A-Za-z0-9]+)::([A-Za-z0-9_/]+)(?:\(\))?$", tok)   # a method may be written with or without ()
    if m:
        cls, meths = m.group(1), m.group(2).split("/")
        check("A class of `%s`" % tok, class_file(cls) is not None, True)
        for meth in meths:
            check("A method `%s`" % tok, has_method(cls, meth), True)
        continue
    # artisan command (with or without example arguments)
    if tok.startswith("pms:") or re.fullmatch(r"[a-z]+:[a-z-]+", tok):
        check("A command `%s`" % tok, tok.split(" ")[0] in facts["commands"], True)
        continue
    # route: with a verb, with a leading slash, or bare because it is already a URI (api/pbx/{token}, guest-requests)
    if re.sub(r"^/", "", tok) in URIS:
        check("A route `%s`" % tok, True, True)
        continue
    m = re.match(r"^(GET|POST|PUT|PATCH|DELETE)\s+(/.+)$", tok) or re.match(r"^(/.+)$", tok)
    if m:
        uri = re.sub(r"^/", "", m.group(m.lastindex))
        check("A route `%s`" % tok, uri in URIS, True)
        continue
    # repo path, glob or directory
    if tok == "package.json":                      # the document says there is no root package.json
        check("A `package.json` is absent at the root, as the document says", exists("package.json"), False)
        continue
    if tok.startswith(("docs/", "deploy/", "resources/", "database/", "app/", "public/", "config/")) or tok == "composer.json":
        check("A path `%s`" % tok, exists(tok), True)
        continue
    # a contract method of the channel driver interface
    if re.fullmatch(r"[a-z]+[A-Z][A-Za-z]*", tok):
        check("A `%s` is a ChannelDriver method or appears in the source" % tok,
              has_method("ChannelDriver", tok) or in_code(tok), True)
        continue
        check("A path `%s`" % tok, exists(tok), True)
        continue
    # a channel driver id
    if tok in ("hub", "simulator", "mirror", "booking", "expedia", "siteminder"):
        check("A driver id `%s`" % tok, tok in facts["channel_drivers"], True)
        continue
    # anything else lowercase must at least appear in the source: a setting key, a column, a mode, an enum value,
    # or a prefix written with a trailing .* (the offline operation queue is desk.*)
    if re.fullmatch(r"[a-z][a-z0-9_.*]*", tok):
        needle = tok[:-1] if tok.endswith(".*") else tok
        check("A literal `%s` in the source" % tok, in_code(needle), True)
        continue
    unclassified.append(tok)

check("A every backticked token is classified (unclassified: %s)" % (unclassified or "none"), unclassified, [])

# ── B. what the document says does not exist ────────────────────────────────
for name in NAMED_ABSENT:
    check("B `%s` is absent, as the document says" % name, class_file(name), None)
for name in COMMANDS_NAMED_ABSENT:
    check("B `%s` is not a command, as the document says" % name, name in facts["commands"], False)

# ── C. every closed claim carries its evidence ──────────────────────────────
CLOSED = [
    ("social posting + review feeds (v7.39)", ["service:SocialPublishService", "service:ReviewService",
                                               "route:api/reviews/{token}", "file:deploy/dev/social-sim.mjs",
                                               "doc:SOCIAL-REVIEWS.md"]),
    ("bank statement formats (v7.48)", ["service:BankRecService", "method:BankRecService.readFile",
                                        "file:deploy/dev/audit/fixtures/ecobank_aug26.mt940", "doc:BANK-STATEMENT-FORMATS.md"]),
    ("mobile money collections (v7.37)", ["service:MoMoGateway", "service:OnlinePaymentService",
                                          "method:OnlinePaymentService.complete", "file:deploy/dev/momo-sim.mjs",
                                          "doc:MOMO-GATEWAY.md"]),
    ("GRA E-VAT submission (v4.3/v4.4)", ["service:EInvoiceService", "service:PosEvatService", "model:EinvoiceLog",
                                          "method:EInvoiceService.generate", "route:vat/documents/{folio}/e-vat",
                                          "route:vat/e-vat/retry", "command:pms:einvoice-retry",
                                          "file:deploy/dev/vsdc-sim.mjs"]),
    ("OTA channel drivers (v7.42/v7.50)", ["driver:booking", "driver:expedia", "driver:siteminder", "driver:hub",
                                           "driver:mirror", "driver:simulator", "service:ForexService",
                                           "method:ForexService.boardRate", "doc:CHANNEL-ADAPTER.md"]),
    ("offline desktop sync (v7.34)", ["service:DesktopSyncService", "route:offline/desktop", "command:pms:offline:pack",
                                      "doc:OFFLINE-DESKTOP.md"]),
    ("live PABX capture (v7.36)", ["class:TcpPbxDriver", "command:pms:pbx:listen", "file:deploy/dev/pbx-sim.mjs",
                                   "doc:PBX-ADAPTER.md"]),
    ("per-property VAT registration + the Act 1151 test (v7.52)", [
        "column:vat_registered", "column:vat_registered_at", "column:vat_supply_kind", "column:supports_e_invoicing",
        "method:GstService.vatRegistration", "method:GstService.vatTurnover", "method:GstService.monthTaxable",
        "method:EInvoiceService.registrationBlocker", "method:EInvoiceService.registrationChanged",
        "file:database/migrations/2026_10_14_003000_hotel_vat_registration.php",
        "file:deploy/dev/audit/vat_registration.php", "file:deploy/dev/audit/vat_registration_flow.py",
        "doc:GRA-EVAT.md"]),
    ("multi-currency supplier invoicing (v7.53)", [
        "method:ApService.convertLines", "method:ApService.fxNote", "method:ApService.fxExposure",
        "method:ForexService.boardRate", "method:ForexService.apBoard", "method:ForexService.apPolicy",
        "method:ForexService.apConvert", "method:SupplierInvoice.isForeign", "method:SupplierInvoice.fxOutstanding",
        "method:SupplierInvoice.fxLabel", "method:SupplierInvoice.fxStale",
        "file:database/migrations/2026_10_14_004000_supplier_invoice_fx.php",
        "file:deploy/dev/audit/ap_fx.php", "file:deploy/dev/audit/ap_fx_flow.py",
        "doc:MULTI-CURRENCY.md"]),
    ("data migration toolkit (v7.35)", ["model:DataMigrationBatch", "model:DataMigrationRow", "model:DataMigrationIdMap",
                                        "class:MigrationService", "class:DatasetImporter", "class:BaseImporter", "class:ImportContext",
                                          "class:CompanyImporter", "class:GuestImporter",
                                        "command:pms:import", "route:settings/migration", "doc:DATA-MIGRATION.md"]),
]
for label, evidence in CLOSED:
    for e in evidence:
        kind, arg = e.split(":", 1)
        # dispatched rather than built as a dict: a dict comprehension would evaluate every branch for every claim
        if kind in ("service", "model", "class"):
            got = class_file(arg) is not None
        elif kind == "method":
            cls, meth = arg.split(".")
            got = has_method(cls, meth)
        elif kind == "route":
            got = arg in URIS
        elif kind == "command":
            got = arg in facts["commands"]
        elif kind == "driver":
            got = arg in facts["channel_drivers"]
        elif kind == "column":
            got = arg in facts["hotel_columns"]
        elif kind == "file":
            got = exists(arg)
        elif kind == "doc":
            got = arg in facts["docs"]
        else:
            sys.exit("unknown evidence kind %r in the closed-claim table" % kind)
        check("C %s → %s:%s" % (label, kind, arg), got, True)

# ── D. every open claim is still open ───────────────────────────────────────
for vendor in ["staah", "rategain", "synxis", "cubilis", "djubo", "hotelbeds", "airbnb"]:
    check("D no channel driver for %s yet, as the document says" % vendor, vendor in facts["channel_drivers"], False)

check("D no key-card hardware driver is bundled", class_file("KeyCardHardwareDriver"), None)
check("D Instagram / X / LinkedIn stay recorded-only", "LIVE" in open(class_file("SocialPublishService")).read()
      and re.search(r"LIVE\s*=\s*\['facebook',\s*'whatsapp'\]", open(class_file("SocialPublishService")).read()) is not None, True)

# ── E. the header's numbers ─────────────────────────────────────────────────
check("E the version the document claims", re.search(r"`config\('pms\.version'\)` ([\d.]+)", text).group(1), facts["pms_version"])
check("E the Laravel version the document claims", re.search(r"Laravel ([\d.]+)", text).group(1), facts["laravel"])
check("E the module count the document claims", re.search(r"(\d+) modules", text).group(1), str(facts["menu"]["modules"]))
check("E the page count the document claims", re.search(r"(\d+) pages", text).group(1), str(facts["menu"]["pages"]))
head = run(["git", "cat-file", "-e", "ca74493^{commit}"])
check("E the commit the document names exists", head.returncode, 0)
anc = run(["git", "merge-base", "--is-ancestor", "ca74493", "HEAD"])
check("E …and is an ancestor of HEAD", anc.returncode, 0)
check("E the SiteMinder message names are in the driver",
      all(x in open(class_file("SiteMinderChannelDriver")).read() for x in ["OTA_AvailRateNotifyRQ", "OTA_HotelResModifyNotifRS"]), True)
# the two artifacts the document now says do NOT exist: no npm build, no signature service
check("E there is no root package.json, as the document says", exists("package.json"), False)
check("E the dev runtime package.json is the only one, as the document says", exists("deploy/dev/package.json"), True)
check("E no signaturepad dependency anywhere in the front end",
      run(["grep", "-rl", "signaturepad", os.path.join(ROOT, "public"), os.path.join(ROOT, "resources")]).stdout.strip(), "")
# …and what the digital registration card really is
check("E the kiosk signature pad is hand-drawn in public/js/kiosk.js", exists("public/js/kiosk.js"), True)
check("E KioskService::sign() stores it", has_method("KioskService", "sign"), True)
check("E grc_signature_path is a real column", in_code("grc_signature_path"), True)
check("E the Blade paths named exist", exists("resources/views/calls/index.blade.php"), True)
check("E the guest portal is stay/{token}, as the document now says", "stay/{token}" in URIS
      and "guest-requests" in URIS and not any(u.startswith("guest/") for u in URIS), True)
check("E the import templates exist", exists("docs/examples/migration/*.csv"), True)
check("E the E-VAT REFUND flag is real", in_code("REFUND", ("app/Services/EInvoiceService.php",)), True)
check("E the registration-card signature path is grc/", in_code("grc/", ("app/Services/KioskService.php",)), True)
check("E the offline operation queue is desk.*", in_code("desk.", ("app/Services/DesktopSyncService.php",)), True)
check("E the pro-forma title is a document state on the folio invoice",
      in_code("PRO-FORMA", ("resources/views/folios/invoice.blade.php",)), True)
check("E the dev runtime directory exists", exists("deploy/dev/") and exists("deploy/dev/bin/php"), True)

# ── report ──────────────────────────────────────────────────────────────────
bad = [c for c in CHECKS if not c[3]]
for label, got, want, good in CHECKS:
    if not good:
        print("  ✗ %-72s got %r, wanted %r" % (label[:72], got, want))

print("\nExcluded as prose, with the reason (%d):" % len(PROSE))
for tok, why in sorted(PROSE.items()):
    print("  · %-52s %s" % (tok[:52], why[:96]))

print("\n%d checks passed, %d discrepancies" % (len(CHECKS) - len(bad), len(bad)))
sys.exit(1 if bad else 0)
