#!/usr/bin/env python3
"""Dump every form (action + field names + select options) on a set of pages.

Used while writing the write-flow probes so payloads match the real UI contract
instead of guessed field names.

Run: python3 deploy/dev/audit/form_dump.py /cro /concierge ...
"""
import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from pmshttp import Pms                                              # noqa: E402


def dump(p, path):
    html = p.get(path)
    print("=" * 100)
    print("PAGE", path, "(%d bytes)" % len(html))
    for m in re.finditer(r'<form[^>]*action="([^"]*)"[^>]*>(.*?)</form>', html, re.S | re.I):
        action = re.sub(r"^https?://[^/]+", "", m.group(1))
        body = m.group(2)
        meth = re.search(r'@method\(\s*\'([A-Z]+)\'', body)
        fields = []
        for i in re.finditer(r'<(input|select|textarea)\b([^>]*)>(.*?)</\1>|<(input|select|textarea)\b([^>]*)/?>', body, re.S | re.I):
            tag = (i.group(1) or i.group(4) or "").lower()
            attrs = i.group(2) if i.group(1) else (i.group(5) or "")
            inner = i.group(3) or ""
            nm = re.search(r'name="([^"]+)"', attrs)
            if not nm or nm.group(1) == "_token":
                continue
            typ = (re.search(r'type="([^"]*)"', attrs) or [None, ""])[1]
            if tag == "select":
                opts = re.findall(r'<option[^>]*value="([^"]*)"', inner)
                fields.append("%s[select:%s]" % (nm.group(1), ",".join([o for o in opts if o][:6])))
            elif tag == "textarea":
                fields.append("%s[textarea]" % nm.group(1))
            else:
                val = re.search(r'value="([^"]*)"', attrs)
                fields.append("%s[%s%s]" % (nm.group(1), typ or "text",
                                            ("=" + val.group(1)) if val and val.group(1) else ""))
        if action in ("/logout", "/search") or not fields:
            continue
        print("  FORM %-8s %s" % (meth.group(1) if meth else "POST", action or "(self)"))
        for f in fields:
            print("        ", f)


if __name__ == "__main__":
    p = Pms()
    for path in sys.argv[1:]:
        dump(p, path)
