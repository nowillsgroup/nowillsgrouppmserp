#!/usr/bin/env python3
"""Build the four statement fixtures used by deploy/dev/audit/bank_formats.php.

One story, four formats: an Ecobank current account for August 2026, nine transactions, the same opening and closing
balances in each. Written by hand (an .xlsx is a ZIP of XML) so the Excel one really does carry its dates as serials
with a date number format, the way a bank export does -- that is the part the reader has to get right.

    python3 deploy/dev/audit/bank_fixtures.py
"""
import os
import re
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
OUT = os.path.join(HERE, "fixtures")

ACCOUNT = "1441000235678"
BANK = "ECOBANK GHANA LIMITED"
HOLDER = "NOWILLS HOSPITALITY GROUP LIMITED"
CCY = "GHS"
OPENING = 125000.50

# date, description, reference, signed amount (money in is positive, the way the app stores it)
TXNS = [
    ("2026-08-03", "Guest settlement folio 4471 - room and F&B", "INV-4471", 18450.00),
    ("2026-08-05", "EFT to Accra Foods Ltd - produce invoice 2291", "AFD-2291", -9320.50),
    ("2026-08-07", "Bank charge - EFT commission", "CHG-000881", -145.00),
    ("2026-08-10", "MoMo merchant settlement", "MOMO-7741203", 6240.75),
    ("2026-08-12", "Cheque 004412 deposited - Nowills Group HQ", "CHQ-004412", 25000.00),
    ("2026-08-14", "Reversal - EFT posted in error", "REV-AFD2291", 9320.50),
    ("2026-08-17", "Salary batch August 2026 - A Mensah", "PAY-2026-08", -30000.00),
    ("2026-08-17", "Salary batch August 2026 - J Thomas", "PAY-2026-08", -12180.00),
    ("2026-08-21", "Standing order - rent, Airport City", "SO-RENT-08", -15000.00),
]
CLOSING = round(OPENING + sum(t[3] for t in TXNS), 2)


def rows():
    """Date / description / reference / debit / credit / balance, as the importer likes to see them."""
    bal, out = OPENING, []
    for d, desc, ref, amt in TXNS:
        bal = round(bal + amt, 2)
        out.append([d, desc, ref,
                    "" if amt > 0 else f"{-amt:.2f}",
                    "" if amt < 0 else f"{amt:.2f}",
                    f"{bal:.2f}"])
    return out


def esc(s):
    return (str(s).replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
            .replace('"', "&quot;"))


def serial(d):
    """Excel's day number: 1 Jan 1900 is 1, and 1900 is wrongly treated as a leap year."""
    y, m, dd = (int(x) for x in d.split("-"))
    days = [31, 29 if (y % 4 == 0 and (y % 100 != 0 or y % 400 == 0)) else 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]
    n = dd
    for i in range(m - 1):
        n += days[i]
    for yy in range(1900, y):
        n += 366 if (yy % 4 == 0 and (yy % 100 != 0 or yy % 400 == 0)) else 365
    return n + 1


def write_csv():
    p = os.path.join(OUT, "ecobank_aug26.csv")
    with open(p, "w", newline="", encoding="utf-8") as fh:
        fh.write(f"{BANK}\n")
        fh.write(f"Account {ACCOUNT} - {HOLDER}\n")
        fh.write("Statement period: 01 August 2026 to 31 August 2026\n\n")
        fh.write("Date,Description,Reference,Debit,Credit,Balance\n")
        for r in rows():
            fh.write(",".join('"%s"' % f if ("," in f or '"' in f) else f for f in r) + "\n")
        fh.write(",,,,Total,%s\n" % f"{sum(t[3] for t in TXNS):.2f}")
    return p


def write_xlsx():
    """A minimal but genuine workbook: shared strings, one date number format, one sheet."""
    data = [["Date", "Description", "Reference", "Debit", "Credit", "Balance"]] + rows()
    strings, index = [], {}

    def sid(text):
        if text not in index:
            index[text] = len(strings)
            strings.append(text)
        return index[text]

    sheet_rows = []
    # three preamble rows, the way a bank export actually arrives: the reader has to find the heading row itself
    for i, txt in enumerate([f"{BANK}  |  {HOLDER}", f"Account {ACCOUNT}  ({CCY})", "Statement period: 01 August 2026 to 31 August 2026"]):
        sheet_rows.append(f'<row r="{i + 1}"><c r="A{i + 1}" t="s"><v>{sid(txt)}</v></c></row>')
    for i, r in enumerate(data):
        n = i + 5
        cells = []
        for j, v in enumerate(r):
            col = chr(65 + j)
            if v == "":
                continue
            if j == 0 and i > 0:                       # a real export stores the date as a serial with a date format
                cells.append(f'<c r="{col}{n}" s="1"><v>{serial(v)}</v></c>')
            elif j > 2 and i > 0:
                cells.append(f'<c r="{col}{n}"><v>{v}</v></c>')
            else:
                cells.append(f'<c r="{col}{n}" t="s"><v>{sid(v)}</v></c>')
        sheet_rows.append(f'<row r="{n}">{"".join(cells)}</row>')
    n = len(data) + 5
    sheet_rows.append(f'<row r="{n}"><c r="A{n}" t="s"><v>{sid("Total")}</v></c>'
                      f'<c r="F{n}"><v>{sum(t[3] for t in TXNS):.2f}</v></c></row>')

    ss = "".join(f"<si><t>{esc(t)}</t></si>" for t in strings)
    parts = {
        "[Content_Types].xml": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            '<Default Extension="xml" ContentType="application/xml"/>'
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            "</Types>"),
        "_rels/.rels": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            "</Relationships>"),
        "xl/workbook.xml": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            '<sheets><sheet name="Statement" sheetId="1" r:id="rId1"/></sheets></workbook>'),
        "xl/_rels/workbook.xml.rels": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            "</Relationships>"),
        # cellXfs index 1 is the date format: numFmtId 14 is the built-in dd/mm/yyyy
        "xl/styles.xml": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            '<borders count="1"><border/></borders>'
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            '<xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            "</styleSheet>"),
        "xl/sharedStrings.xml": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            f'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="{len(strings)}" uniqueCount="{len(strings)}">{ss}</sst>'),
        "xl/worksheets/sheet1.xml": (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            f'<sheetData>{"".join(sheet_rows)}</sheetData></worksheet>'),
    }
    p = os.path.join(OUT, "ecobank_aug26.xlsx")
    with zipfile.ZipFile(p, "w", zipfile.ZIP_DEFLATED) as z:
        for name, body in parts.items():
            z.writestr(name, body)
    return p


def write_mt940():
    """SWIFT MT940: tagged text, two-digit years, comma decimals, no running balance."""
    out = [
        "{1:F01ECOCGHACAXXX0000000000}{2:I940ECOCGHACAXXXN}{4:",
        ":20:STMT20260831",
        f":25:{ACCOUNT}",
        ":28C:8/1",
        ":60F:C260801%s%s" % (CCY, f"{OPENING:.2f}".replace(".", ",")),
    ]
    for n, (d, desc, ref, amt) in enumerate(TXNS):
        ymd = d[2:4] + d[5:7] + d[8:10]
        cd = "C" if amt > 0 else "D"
        kind = "NRTI" if amt > 0 else "NTRF"
        # the customer reference comes first; after // is the bank's own, which is a different number
        out.append(":61:%s%s%s%s%s//%s" % (ymd, cd, f"{abs(amt):.2f}".replace(".", ","), kind, ref[:16], "EB%s%06d" % (cd, n + 1)))
        out.append(":86:%s" % desc)
    out += [
        ":62F:C260831%s%s" % (CCY, f"{CLOSING:.2f}".replace(".", ",")),
        ":64:C260831%s%s" % (CCY, f"{CLOSING:.2f}".replace(".", ",")),
        "-}",
        "{5:CHK123456789ABC}",
    ]
    p = os.path.join(OUT, "ecobank_aug26.mt940")
    open(p, "w", encoding="utf-8", newline="\r\n").write("\n".join(out) + "\n")
    return p


def write_camt():
    """ISO 20022 camt.053, with the two salary payments batched into one entry the way a bank sends a payroll batch."""
    bal = OPENING
    entries = []
    i = 0
    while i < len(TXNS):
        d, desc, ref, amt = TXNS[i]
        # a payroll batch arrives as one entry carrying its payments: same day, same end-to-end id, all debits
        batch = [TXNS[i]]
        j = i + 1
        while (j < len(TXNS) and amt < 0 and TXNS[j][0] == d and TXNS[j][2] == ref
               and TXNS[j][3] < 0 and len(batch) < 2):
            batch.append(TXNS[j])
            j += 1
        if len(batch) > 1:                                  # one <Ntry> carrying its transactions under <TxDtls>
            total = round(sum(b[3] for b in batch), 2)
            bal = round(bal + total, 2)
            tx = "".join(
                "<TxDtls><Refs><EndToEndId>{ref}</EndToEndId><AcctSvcrRef>BANK{n:06d}</AcctSvcrRef></Refs>"
                "<Amt Ccy=\"{ccy}\">{amt:.2f}</Amt><CdtDbtInd>{cd}</CdtDbtInd>"
                "<BookgDt><Dt>{d}</Dt></BookgDt><RmtInf><Ustrd>{desc}</Ustrd></RmtInf></TxDtls>".format(
                    ref=esc(b[2]), ccy=CCY, amt=abs(b[3]), cd="CRDT" if b[3] > 0 else "DBIT", d=b[0],
                    desc=esc(b[1]), n=len(entries) * 10 + k + 1)
                for k, b in enumerate(batch))
            entries.append(
                "<Ntry><Amt Ccy=\"{ccy}\">{amt:.2f}</Amt><CdtDbtInd>{cd}</CdtDbtInd><Sts><Cd>BOOK</Cd></Sts>"
                "<BookgDt><Dt>{d}</Dt></BookgDt><ValDt><Dt>{d}</Dt></ValDt>"
                "<BkTxCd><DomnCd><Cd>PMNT</Cd></DomnCd><Cd><Cd>RCDT</Cd></Cd></BkTxCd>"
                "<AddtlNtryInf>Salary batch August 2026</AddtlNtryInf>"
                "<NtryDtls>{tx}</NtryDtls></Ntry>".format(ccy=CCY, amt=abs(total), cd="DBIT", d=d, tx=tx))
            i = j
            continue
        i += 1
        bal = round(bal + amt, 2)
        entries.append(
            "<Ntry><Amt Ccy=\"{ccy}\">{amt:.2f}</Amt><CdtDbtInd>{cd}</CdtDbtInd><Sts><Cd>BOOK</Cd></Sts>"
            "<BookgDt><Dt>{d}</Dt></BookgDt><ValDt><Dt>{d}</Dt></ValDt>"
            "<BkTxCd><DomnCd><Cd>PMNT</Cd></DomnCd><Cd><Cd>{fam}</Cd></Cd></BkTxCd>"
            "<NtryDtls><TxDtls><Refs><EndToEndId>{ref}</EndToEndId><AcctSvcrRef>BANK{n:06d}</AcctSvcrRef></Refs>"
            "<RmtInf><Ustrd>{desc}</Ustrd></RmtInf></TxDtls></NtryDtls>"
            "<AddtlNtryInf>{desc}</AddtlNtryInf></Ntry>".format(
                ccy=CCY, amt=abs(amt), cd="CRDT" if amt > 0 else "DBIT", d=d, ref=esc(ref), n=len(entries) + 1,
                fam="RCDT" if amt > 0 else "PMNT", desc=esc(desc)))
    assert len(entries) == len(TXNS) - 1, (len(entries), len(TXNS))
    assert abs(bal - CLOSING) < 0.005, (bal, CLOSING)

    xml = (
        '<?xml version="1.0" encoding="UTF-8"?>'
        '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02">'
        "<BkToCstmrStmt><GrpHdr><MsgId>ECOCGHAC-2026-08-31-01</MsgId>"
        "<CreDtTm>2026-08-31T23:55:00</CreDtTm></GrpHdr>"
        "<Stmt><Id>STMT-2026-08</Id><ElctrncSeqNb>8</ElctrncSeqNb><CreDtTm>2026-08-31T23:55:00</CreDtTm>"
        "<FrToDt><FrDt>2026-08-01</FrDt><ToDt>2026-08-31</ToDt></FrToDt>"
        "<Acct><Id><Othr><Id>{acct}</Id></Othr></Id><Ccy>{ccy}</Ccy>"
        "<Svcr><FinInstnId><BICFI>ECOCGHAC</BICFI></FinInstnId></Svcr>"
        "<Ownr><Nm>{holder}</Nm></Ownr></Acct>"
        '<Bal><Tp><CdOrPrtry><Cd>OPBD</Cd></CdOrPrtry></Tp><Amt Ccy="{ccy}">{op:.2f}</Amt>'
        "<CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-08-01</Dt></Dt></Bal>"
        '<Bal><Tp><CdOrPrtry><Cd>CLBD</Cd></CdOrPrtry></Tp><Amt Ccy="{ccy}">{cl:.2f}</Amt>'
        "<CdtDbtInd>CRDT</CdtDbtInd><Dt><Dt>2026-08-31</Dt></Dt></Bal>"
        "{entries}"
        "</Stmt></BkToCstmrStmt></Document>").format(
            acct=ACCOUNT, ccy=CCY, holder=esc(HOLDER), op=OPENING, cl=CLOSING, entries="".join(entries))
    p = os.path.join(OUT, "ecobank_aug26.camt053.xml")
    open(p, "w", encoding="utf-8").write(re.sub(r">\s+<", "><", xml))
    return p


def main():
    os.makedirs(OUT, exist_ok=True)
    made = [write_csv(), write_xlsx(), write_mt940(), write_camt()]
    print("opening %s%.2f -> closing %s%.2f over %d transactions" % (CCY, OPENING, CCY, CLOSING, len(TXNS)))
    for p in made:
        print("  %-56s %6d bytes" % (os.path.relpath(p, os.path.dirname(HERE)), os.path.getsize(p)))


if __name__ == "__main__":
    main()
