<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Gate pass {{ $p->pass_no }}</title>
<style>
body{font:12.5px/1.4 system-ui,sans-serif;margin:0;padding:18px;color:#111}
.bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.pass{border:2px solid #111;border-radius:6px;padding:14px 16px;max-width:760px}
.hd{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1.5px solid #111;padding-bottom:8px;margin-bottom:10px}
.hd h1{font-size:18px;margin:0}.hd .t{font-size:20px;font-weight:900;letter-spacing:.06em;text-transform:uppercase}.q{width:86px}.q svg{width:100%;height:auto}
table{width:100%;border-collapse:collapse;margin:8px 0}td,th{border:1px solid #333;padding:4px 6px;text-align:left}th{background:#f1f5f9}.r{text-align:right}
.meta{display:grid;grid-template-columns:1fr 1fr;gap:3px 18px}.meta b{display:inline-block;min-width:110px;color:#475569;font-weight:600}
.sig{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:34px}.sig div{border-top:1px solid #111;padding-top:3px;font-size:11px;text-align:center}
.stamp{display:inline-block;border:2px solid #b91c1c;color:#b91c1c;font-weight:800;padding:2px 8px;transform:rotate(-4deg);letter-spacing:.08em}
@media print{.bar{display:none}body{padding:0}}
</style></head><body>
<div class="bar"><div><b>{{ $hotel->name }}</b> · gate pass</div><button onclick="window.print()">Print</button></div>
<div class="pass" id="gatePassPrint">
  <div class="hd"><div><h1>{{ $hotel->name }}</h1><div class="t">{{ $p->type === 'returnable' ? 'Returnable' : 'Non-returnable' }} gate pass</div>
    <div>No. <b>{{ $p->pass_no }}</b> · {{ $p->created_at->format('d M Y') }}</div></div><div class="q">{!! $qr !!}</div></div>
  <div class="meta">
    <div><b>Issued to</b> {{ $p->party_name }}{{ $p->party_phone ? ' ('.$p->party_phone.')' : '' }}</div>
    <div><b>Purpose</b> {{ \App\Models\GatePass::PURPOSES[$p->purpose] ?? $p->purpose }}</div>
    <div><b>Department</b> {{ $p->department?->name ?? '–' }}</div>
    <div><b>Carried by</b> {{ $p->carrier_name ?? '________________' }}</div>
    <div><b>Vehicle</b> {{ $p->vehicle_no ?? '________________' }}</div>
    @if($p->type === 'returnable')<div><b>Return by</b> <span class="stamp">{{ $p->expected_return_date?->format('d M Y') }}</span></div>@endif
  </div>
  <table><thead><tr><th>#</th><th>Description</th><th>Asset</th><th class="r">Qty</th><th class="r">Est. value</th></tr></thead><tbody>
  @foreach($p->items as $it)<tr><td>{{ $loop->iteration }}</td><td>{{ $it->description }}</td><td>{{ $it->asset?->asset_no }}{{ $it->asset?->serial_no ? ' · S/N '.$it->asset->serial_no : '' }}</td><td class="r">{{ rtrim(rtrim(number_format((float) $it->quantity, 2), '0'), '.') }} {{ $it->unit }}</td><td class="r">{{ $it->est_value ? money($it->est_value) : '' }}</td></tr>@endforeach
  </tbody></table>
  @if($p->reason)<div><b>Remarks:</b> {{ $p->reason }}</div>@endif
  <div class="sig"><div>Requested – {{ $p->requester?->name }}</div><div>Approved – {{ $p->approver?->name }}</div><div>Security (out){{ $p->outUser ? ' – '.$p->outUser->name : '' }}{{ $p->out_at ? ' · '.$p->out_at->format('d M H:i') : '' }}</div><div>Receiver's signature</div></div>
</div>
</body></html>
