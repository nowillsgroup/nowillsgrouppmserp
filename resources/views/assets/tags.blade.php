<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Asset tags</title>
<style>
body{font:13px/1.35 system-ui,sans-serif;margin:0;padding:14px;color:#111}
.bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.sheet{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.tag{border:1.5px dashed #94a3b8;border-radius:8px;padding:8px;display:flex;gap:8px;align-items:center;break-inside:avoid;page-break-inside:avoid}
.tq{width:82px;flex:0 0 82px}.tq svg{width:100%;height:auto;display:block}
.th{font-size:9px;text-transform:uppercase;letter-spacing:.08em;color:#64748b}.tn{font-weight:800;font-size:16px;letter-spacing:.02em}.td{font-size:11px;line-height:1.25}
@media print{.bar{display:none}body{padding:0}.sheet{gap:4px}}
</style></head><body>
<div class="bar"><div><b>{{ \App\Support\Tenant::hotel()?->name }}</b> · {{ $assets->count() }} asset tag(s)</div><button onclick="window.print()">Print</button></div>
<div class="sheet" id="tagSheet">
@forelse($assets as $a)
<div class="tag"><div class="tq">{!! $qr[$a->id] !!}</div><div><div class="th">{{ \App\Support\Tenant::hotel()?->name }} · property of</div><div class="tn">{{ $a->asset_no }}</div><div class="td">{{ \Illuminate\Support\Str::limit($a->name, 48) }}</div><div class="td" style="color:#475569">{{ $a->placeLabel() }}{{ $a->serial_no ? ' · S/N '.$a->serial_no : '' }}</div></div></div>
@empty<p>No assets selected.</p>@endforelse
</div>
</body></html>
