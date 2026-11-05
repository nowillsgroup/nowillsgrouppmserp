<style>
:root{color-scheme:light;--doc-ink:#18211d;--doc-muted:#66736d;--doc-line:#d8e1dc;--doc-soft:#f3f7f4;--doc-accent:#176a43;--doc-accent-soft:#eaf5ee;--doc-warn:#8b5510;--doc-warn-soft:#fff6e7}
*{box-sizing:border-box}
html{background:#edf1ee}
body{font:13px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:24px auto;padding:30px;color:var(--doc-ink);max-width:900px;background:#fff;box-shadow:0 12px 40px rgba(20,44,30,.1)}
h1{font-size:19px;line-height:1.2;margin:0;letter-spacing:-.02em}.m{color:var(--doc-muted);font-size:11px}
.head{display:flex;justify-content:space-between;align-items:flex-start;gap:24px;border-bottom:2px solid var(--doc-accent);padding-bottom:14px}
.doc{text-align:right;min-width:180px}.doc h2{margin:1px 0 4px;font-size:22px;letter-spacing:.03em;line-height:1.15}
.kv{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px 18px;margin:14px 0}.kv div{min-width:0}
.kv div span,.lbl{display:block;font-size:10px;text-transform:uppercase;color:var(--doc-muted);letter-spacing:.055em;font-weight:700;margin-bottom:2px}
table{width:100%;border-collapse:collapse;margin-top:8px}td,th{padding:7px 9px;border-bottom:1px solid var(--doc-line);text-align:left;vertical-align:top}th{font-size:10px;color:#45544c;background:var(--doc-soft);text-transform:uppercase;letter-spacing:.045em}td.r,th.r{text-align:right;font-variant-numeric:tabular-nums}tfoot td{font-weight:700;border-top:2px solid var(--doc-ink)}
.stamp{display:inline-block;border:1px solid #9da9a2;border-radius:999px;padding:3px 10px;font-weight:750;font-size:10px;margin-top:5px;text-transform:uppercase;letter-spacing:.045em;background:var(--doc-soft);color:#405148}
.stamp.scheduled{background:#eef4ff;border-color:#b3c7ea;color:#26456f}.stamp.in_progress{background:#fff5df;border-color:#efcd82;color:#80500a}.stamp.completed{background:#e7f4eb;border-color:#9dc9a9;color:#165a34}.stamp.cancelled{background:#fceceb;border-color:#e4b1ad;color:#8d312a}
.prio-urgent{background:#fceceb;border-color:#e4b1ad;color:#8d312a}.prio-high{background:#fff5df;border-color:#efcd82;color:#80500a}
.box{border:1px solid var(--doc-line);border-radius:9px;padding:11px 13px;margin:12px 0;break-inside:avoid-page;page-break-inside:avoid}
.box.alert{background:var(--doc-warn-soft);border-color:#efcd82}
.pre{white-space:pre-line}
ul.checks{margin:4px 0 0;padding-left:18px}
.sig{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:22px;margin-top:40px}.sig div{border-top:1px solid #88948e;padding-top:6px;font-size:11px;min-height:38px}
.document-actions{max-width:900px;margin:0 auto 10px;display:flex;justify-content:flex-end;gap:8px}
.document-actions a,.document-actions button{appearance:none;border:1px solid #c5d1c9;border-radius:8px;padding:9px 13px;background:#fff;color:#20372b;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}
.document-actions a:hover,.document-actions button:hover{background:var(--doc-soft)}
.document-actions button:focus-visible,.document-actions a:focus-visible{outline:3px solid #86c39c;outline-offset:2px}
.muted{color:var(--doc-muted)}.note{font-size:11px;color:#536159}.sr-only{position:absolute!important;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
@page{size:A4 portrait;margin:13mm 12mm 14mm}
@media(max-width:700px){body{margin:12px;padding:18px}.kv{grid-template-columns:repeat(2,minmax(0,1fr))}.sig{grid-template-columns:1fr}}
@media print{html{background:#fff}body{max-width:none;width:auto;margin:0;padding:0;box-shadow:none;font-size:10pt;line-height:1.4;color:#111;-webkit-print-color-adjust:exact;print-color-adjust:exact}.document-actions,button,.noprint{display:none!important}.head,.box,.sig{break-inside:avoid-page;page-break-inside:avoid}thead{display:table-header-group}tr{break-inside:avoid-page;page-break-inside:avoid}}
</style>
