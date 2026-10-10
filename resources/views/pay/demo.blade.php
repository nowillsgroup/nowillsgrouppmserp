<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Test checkout · {{ $p->pay_no }}</title><link rel="stylesheet" href="{{ asset('css/app.css') }}">
<style>body{background:#eef2f7;min-height:100vh;display:grid;place-items:center;padding:1rem}.gw{background:#fff;border-radius:16px;max-width:430px;width:100%;box-shadow:0 20px 50px rgba(15,23,42,.15);overflow:hidden}
.gw-head{background:#0f172a;color:#fff;padding:1rem 1.2rem;display:flex;justify-content:space-between;align-items:center}.gw-head small{opacity:.7}.gw-body{padding:1.2rem}
.gw-amt{font-size:1.9rem;font-weight:800;margin:.2rem 0 1rem}.tabs3{display:grid;grid-template-columns:repeat(3,1fr);gap:.4rem;margin-bottom:1rem}.tabs3 label{border:1px solid var(--c-border);border-radius:10px;padding:.55rem;text-align:center;font-size:.85rem;cursor:pointer}
.tabs3 input{display:none}.tabs3 label:has(input:checked){border-color:#1d4ed8;background:#eff6ff;color:#1d4ed8;font-weight:700}.fake{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;padding:.8rem;font-family:ui-monospace,monospace;font-size:.9rem;color:#334155;margin-bottom:1rem}
.test-ribbon{background:#fef3c7;color:#92400e;text-align:center;font-size:.78rem;font-weight:700;padding:.35rem;letter-spacing:.04em}</style></head>
<body>@include('partials.icons')
<div class="gw">
  <div class="test-ribbon">TEST GATEWAY – NO REAL MONEY</div>
  <div class="gw-head"><div><b>{{ $hotel->name }}</b><br><small>{{ $p->pay_no }} · {{ $p->description }}</small></div><x-icon name="lock"/></div>
  <form class="gw-body" method="post" action="{{ route('pay.demo.submit', $p->token) }}">@csrf<input type="hidden" name="ref" value="{{ $ref }}">
    <div class="tiny muted">AMOUNT DUE</div><div class="gw-amt">{{ $p->currency }} {{ number_format((float) $p->amount, 2) }}</div>
    <div class="tabs3">
      <label><input type="radio" name="channel" value="card" checked> Card</label>
      <label><input type="radio" name="channel" value="mobile_money"> Mobile money</label>
      <label><input type="radio" name="channel" value="upi"> UPI</label>
    </div>
    <div class="fake">4242 4242 4242 4242 · 12/30 · 123<br><span class="tiny muted">Test details – nothing to type.</span></div>
    <button class="btn btn-primary btn-block" name="result" value="success" id="demoPay">Pay {{ $p->currency }} {{ number_format((float) $p->amount, 2) }}</button>
    <div class="flex gap-1 mt-1"><button class="btn btn-block" name="result" value="declined" id="demoDecline">Simulate decline</button><button class="btn btn-block" name="result" value="insufficient">Insufficient funds</button></div>
    <a class="small muted center" style="display:block;margin-top:.8rem" href="{{ route('pay.show', $p->token) }}">Cancel and return to {{ $hotel->name }}</a>
  </form>
</div></body></html>
