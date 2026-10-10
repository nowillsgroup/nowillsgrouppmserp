@php $qf = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); @endphp
<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>{{ $issue ? $issue->issue_no : $r->req_no }}</title>
<style>
body{font:13px/1.45 system-ui,sans-serif;margin:0;padding:24px;color:#111;max-width:820px}
h1{font-size:18px;margin:0}.m{color:#555;font-size:11px}.head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #111;padding-bottom:8px}
.doc{text-align:right}.doc h2{margin:0;font-size:22px;letter-spacing:.03em}.kv{display:grid;grid-template-columns:repeat(4,1fr);gap:6px 16px;margin:12px 0}
.kv div span{display:block;font-size:10px;text-transform:uppercase;color:#666;letter-spacing:.04em}
table{width:100%;border-collapse:collapse;margin-top:6px}td,th{padding:5px 6px;border-bottom:1px solid #ccc;text-align:left;vertical-align:top}th{font-size:11px;color:#444;background:#f3f4f6}.r{text-align:right}
tfoot td{font-weight:700;border-top:2px solid #111}.sig{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-top:48px}.sig div{border-top:1px solid #111;padding-top:4px;font-size:11px}
.stamp{display:inline-block;border:2px solid #111;border-radius:6px;padding:2px 8px;font-weight:700;font-size:11px;margin-top:4px}
@media print{button{display:none}body{padding:0}}
</style></head><body>
<button onclick="window.print()" style="float:right">Print</button>
<div class="head">
  <div><h1>{{ $hotel->name }}</h1><div class="m">{{ $hotel->address ?? '' }}</div></div>
  <div class="doc"><div class="m">{{ $issue ? 'STORE ISSUE NOTE' : 'STORE REQUISITION' }}</div><h2>{{ $issue ? $issue->issue_no : $r->req_no }}</h2><div class="stamp">{{ $issue ? ($issue->toStore ? 'TRANSFER' : 'ISSUE') : strtoupper($r->statusLabel()) }}</div></div>
</div>
<div class="kv">
  <div><span>Department</span>{{ $r->department?->name }}</div>
  <div><span>{{ $issue ? 'Against requisition' : 'Date' }}</span>{{ $issue ? $r->req_no : $r->req_date->format('d M Y') }}</div>
  <div><span>From store</span>{{ $r->fromStore?->name }}</div>
  <div><span>Deliver to</span>{{ $r->destination() }}</div>
  <div><span>{{ $issue ? 'Issue date' : 'Needed by' }}</span>{{ $issue ? $issue->date->format('d M Y') : ($r->required_by?->format('d M Y') ?? '—') }}</div>
  <div><span>Priority</span>{{ ucfirst($r->priority) }}</div>
  <div style="grid-column:span 2"><span>Purpose</span>{{ $r->purpose ?: '—' }}</div>
</div>
@if($issue)
<table><thead><tr><th>#</th><th>Item</th><th>Unit</th><th class="r">Approved</th><th class="r">Issued now</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead><tbody>
@foreach($issue->lines as $k => $l)<tr><td>{{ $k + 1 }}</td><td>{{ $l->item?->name }}</td><td>{{ $l->item?->unit }}</td><td class="r">{{ $qf($l->requisitionLine?->qty_approved) }}</td><td class="r"><b>{{ $qf($l->qty) }}</b></td><td class="r">{{ money($l->rate, false) }}</td><td class="r">{{ money($l->amount, false) }}</td></tr>@endforeach
</tbody><tfoot><tr><td colspan="6" class="r">Total at cost</td><td class="r">{{ money($issue->value) }}</td></tr></tfoot></table>
@if($issue->notes)<p class="m">Notes: {{ $issue->notes }}</p>@endif
@if($issue->received_at)<p class="m">Received by {{ $issue->receiver?->name }} on {{ $issue->received_at->format('d M Y H:i') }}{{ $issue->receipt_note ? ' – '.$issue->receipt_note : '' }}</p>@endif
<div class="sig"><div>Requested by<br><b>{{ $r->raiser?->name }}</b></div><div>Approved by (HOD)<br><b>{{ $r->approver?->name ?? ($r->approved_at ? 'Auto-approved' : '') }}</b></div><div>Issued by (Store)<br><b>{{ $issue->issuer?->name }}</b></div><div>Received by<br><b>{{ $issue->receiver?->name }}</b></div></div>
@else
<table><thead><tr><th>#</th><th>Item</th><th>Unit</th><th class="r">Requested</th><th class="r">Approved</th><th class="r">Issued</th><th class="r">Est. value</th><th>Remarks</th></tr></thead><tbody>
@foreach($r->lines as $k => $l)<tr><td>{{ $k + 1 }}</td><td>{{ $l->item?->name }}</td><td>{{ $l->item?->unit }}</td><td class="r">{{ $qf($l->qty_requested) }}</td><td class="r">{{ $l->qty_approved === null ? '' : $qf($l->qty_approved) }}</td><td class="r">{{ (float) $l->qty_issued ? $qf($l->qty_issued) : '' }}</td><td class="r">{{ money($l->allowed() * (float) $l->item?->last_cost, false) }}</td><td class="m">{{ $l->remarks }}</td></tr>@endforeach
</tbody><tfoot><tr><td colspan="6" class="r">Estimated value</td><td class="r">{{ money($r->est_value) }}</td><td></td></tr></tfoot></table>
@if($r->issues->count())<p class="m">Issue notes: {{ $r->issues->map(fn ($i) => $i->issue_no.' ('.$i->date->format('d M').', '.money($i->value, false).')')->join(', ') }}</p>@endif
<div class="sig"><div>Requested by<br><b>{{ $r->raiser?->name }}</b></div><div>Approved by (HOD)<br><b>{{ $r->approver?->name ?? ($r->approved_at ? 'Auto-approved' : '') }}</b></div><div>Issued by (Store)</div><div>Received by</div></div>
@endif
<p class="m" style="margin-top:24px">Printed {{ now()->format('d M Y H:i') }} · {{ config('app.name') }}</p>
</body></html>
