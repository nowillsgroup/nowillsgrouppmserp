"""Batch E - the last device/JSON corners of Front Office & Guest Services."""
import sys, json, re
sys.path.insert(0, '/home/user/nowillsgrouppmserp/deploy/dev/audit')
from flows_lib import Pms, record, save, payload, submit, day

R = []
def chk(name, ok, detail):
    record(R, name, "PASS" if ok else "FAIL", detail)
    print(("PASS " if ok else "FAIL ") + name + " :: " + detail[:160])

p = Pms()

# ---- 1. SMDR import in the documented column order -----------------------
smdr = "\n".join([
    "%s,09:14:22,101,T1,0302778899,00:04:11" % day(0),
    "%s,11:02:07,103,T2,0244123456,00:00:52" % day(0),
    "%s,13:45:55,206,T1,+442071234567,00:12:40" % day(0),
])
res = submit(p, "/calls/import", {"text": smdr}, action_pattern=r"/calls/import", read="/calls?tab=import")
fl = res[1] or ""
chk("calls: SMDR import (documented format)", "import" in fl.lower() and "fix the following" not in fl.lower(), fl[:150])

# ---- 2. extensions sync ---------------------------------------------------
res = submit(p, "/calls/extensions-sync", {}, action_pattern=r"/calls/extensions-sync", read="/calls?tab=extensions")
fl = res[1] or ""
chk("calls: room extensions sync", ("extension" in fl.lower()) and "fix the following" not in fl.lower(), fl[:150])

# ---- 3. IPTV settings with the language checkboxes -----------------------
h = p.get("/iptv/settings")
data, fields = payload(h, r"/iptv/settings")
data.pop("_token", None)
for l in ("en", "fr", "es"):
    data.setdefault("langs[]", [])
data["langs[]"] = ["en", "fr", "es"]
data["default_language"] = "en"
res = submit(p, "/iptv/settings", data, action_pattern=r"/iptv/settings", read="/iptv/settings")
fl = res[1] or ""
chk("iptv: settings saved with langs[]", "saved" in fl.lower() or "updated" in fl.lower(), fl[:150])

# ---- 4. IPTV device command + status -------------------------------------
for cmd, extra in (("reboot", {}), ("volume", {"level": "35"}), ("power", {"command2": ""})):
    res = submit(p, "/iptv/devices/1/command", dict(extra, command=cmd),
                 action_pattern=r"/iptv/devices/1/command", read="/iptv/devices")
    fl = res[1] or ""
    chk("iptv: device command '%s'" % cmd, ("sent" in fl.lower() or "command" in fl.lower()) and "fix the following" not in fl.lower(), fl[:130])
res = submit(p, "/iptv/devices/1/status", {"status": "online"},
             action_pattern=r"/iptv/devices/1/status", read="/iptv/devices")
fl = res[1] or ""
chk("iptv: device status set online", "updated" in fl.lower(), fl[:130])

# ---- 5. IPTV emergency all-clear -----------------------------------------
res = submit(p, "/iptv/emergency/11/clear", {}, action_pattern=r"/iptv/emergency/11/clear", read="/iptv/messages")
fl = res[1] or ""
chk("iptv: emergency all-clear (TVM-00011)", ("clear" in fl.lower() or "cancelled" in fl.lower()) and "fix the following" not in fl.lower(), fl[:150])

# ---- 6. master key sign-out then sign-in ---------------------------------
mk = re.search(r'/master-keys/(\d+)/out', p.get("/key-cards?tab=master"))
if mk:
    mid = mk.group(1)
    res = submit(p, "/master-keys/%s/out" % mid, {"user_id": "1", "holder": "Audit Supervisor",
                 "purpose": "Audit floor walk", "hours": "4"},
                 action_pattern=r"/master-keys/%s/out" % mid, read="/key-cards?tab=master")
    fl = res[1] or ""
    chk("keys: master key sign-out", "signed out" in fl.lower(), fl[:150])
    loan = re.search(r'/key-loans/(\d+)/in', p.get("/key-cards?tab=master"))
    if loan:
        lid = loan.group(1)
        res = submit(p, "/key-loans/%s/in" % lid, {"note": "Returned after the audit walk"},
                     action_pattern=r"/key-loans/%s/in" % lid, read="/key-cards?tab=master")
        fl = res[1] or ""
        chk("keys: master key sign-in", "signed in" in fl.lower(), fl[:150])
    else:
        chk("keys: master key sign-in", False, "no open loan row rendered after sign-out")
else:
    chk("keys: master key sign-out", False, "no /master-keys/{id}/out form on ?tab=master")

save(R, "/home/user/nowillsgrouppmserp/deploy/dev/audit/flows_e.json")
print("\n%d/%d PASS" % (sum(1 for r in R if r[1] == "PASS"), len(R)))
