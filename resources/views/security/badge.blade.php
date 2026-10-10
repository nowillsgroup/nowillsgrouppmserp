<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Visitor badge {{ $v->pass_no }}</title>
<style>
body{font:13px/1.35 system-ui,sans-serif;margin:0;padding:14px;color:#111;background:#f1f5f9}
.bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;max-width:340px}
.badge{width:320px;background:#fff;border:2px solid #0f172a;border-radius:14px;overflow:hidden}
.bh{background:#0f172a;color:#fff;padding:10px 14px;display:flex;justify-content:space-between;align-items:center}
.bh b{font-size:15px;letter-spacing:.02em}.bh span{font-size:10px;text-transform:uppercase;letter-spacing:.12em;opacity:.8}
.vis{font-size:30px;font-weight:900;letter-spacing:.3em;text-align:center;padding:8px 0 2px;color:#b91c1c}
.bb{padding:4px 14px 12px;display:flex;gap:12px;align-items:center}.bq{width:96px;flex:0 0 96px}.bq svg{width:100%;height:auto;display:block}
.nm{font-size:18px;font-weight:800;line-height:1.15}.k{font-size:9px;text-transform:uppercase;letter-spacing:.1em;color:#64748b;margin-top:6px}.v{font-size:12.5px;font-weight:600}
.ft{border-top:1px dashed #cbd5e1;padding:6px 14px;font-size:10px;color:#475569;display:flex;justify-content:space-between}
@media print{.bar{display:none}body{padding:0;background:#fff}}
</style></head><body>
<div class="bar"><div><b>{{ $hotel->name }}</b> · visitor badge</div><button onclick="window.print()">Print</button></div>
<div class="badge" id="visitorBadge">
  <div class="bh"><b>{{ $hotel->name }}</b><span>Pass {{ $v->pass_no }}</span></div>
  <div class="vis">VISITOR</div>
  <div class="bb"><div class="bq">{!! $qr !!}</div><div>
    <div class="nm">{{ $v->name }}</div>@if($v->persons > 1)<div class="v">+ {{ $v->persons - 1 }} accompanying</div>@endif
    <div class="k">Visiting</div><div class="v">{{ $v->hostLabel() }}</div>
    <div class="k">Valid</div><div class="v">{{ $v->checked_in_at?->format('d M H:i') ?? ($v->expected_at?->format('d M H:i') ?? '') }} – {{ $v->valid_until?->format('H:i') }}</div>
  </div></div>
  <div class="ft"><span>Wear this badge at all times</span><span>Return it at the exit</span></div>
</div>
</body></html>
