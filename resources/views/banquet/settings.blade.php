@extends('layouts.app')
@section('title', 'Banquet Settings')
@section('crumbs') <a href="{{ route('banquet.index') }}">Banquet</a> / Settings @endsection
@section('actions')
<a class="btn" href="{{ route('masters.index', 'banquet-halls') }}"><x-icon name="building"/> Venues</a>
<a class="btn" href="{{ route('masters.index', 'banquet-packages') }}"><x-icon name="cup"/> Menu packages</a>
<a class="btn" href="{{ route('masters.index', 'banquet-services') }}"><x-icon name="tool"/> Services &amp; equipment</a>
@endsection
@section('content')
<form method="post" action="{{ route('banquet.settings.save') }}" id="bqSettings">@csrf
<div class="grid g-2-1">
  <div class="card"><div class="card-head"><h3>Sales &amp; operations rules</h3></div><div class="card-body form-grid">
    <x-field name="banquet_guarantee_days" label="Guarantee due (days before)" type="number" min="0" max="60" :value="$s['banquet_guarantee_days']" required hint="After this the guaranteed number can only go up – we charge for it even if fewer attend."/>
    <x-field name="banquet_turnaround_minutes" label="Turn-around between functions (min)" type="number" min="0" max="480" :value="$s['banquet_turnaround_minutes']" required hint="Clearing and re-setting time kept free in a venue between two functions."/>
    <x-field name="banquet_half_day_hours" label="Half-day rent up to (hours)" type="number" step="0.5" min="1" max="24" :value="$s['banquet_half_day_hours']" required hint="Longer functions get the venue's full-day rate."/>
    <x-field name="banquet_deposit_pct" label="Suggested deposit (%)" type="number" step="0.5" min="0" max="100" :value="$s['banquet_deposit_pct']" required hint="Of the estimated total, suggested on proposals."/>
    <x-field name="banquet_hall_tax_rate" label="Tax on venue rent &amp; cancellation fees (%)" type="number" step="0.01" min="0" max="50" :value="$s['banquet_hall_tax_rate']" required/>
    <x-field name="banquet_cancel_policy" label="Cancellation policy" :value="$s['banquet_cancel_policy']" required hint="days-before:percent pairs, e.g. 60:0,30:25,14:50,7:75,0:100 = free 60+ days out, 25% from 30 days … 100% on the day."/>
  </div></div>
  <div class="card"><div class="card-head"><h3>Cancellation scale</h3></div><div class="card-body" id="polPreview"></div></div>
</div>
<div class="card"><div class="card-head"><h3>Terms printed on proposals &amp; contracts</h3></div><div class="card-body">
  <x-field name="banquet_terms" type="textarea" rows="8" :value="$s['banquet_terms']"/>
</div></div>
<div class="flex" style="justify-content:flex-end"><button class="btn btn-primary" id="saveBq"><x-icon name="check"/> Save settings</button></div>
</form>
<script>
(function () {
  const inp = document.getElementById('f_banquet_cancel_policy'), out = document.getElementById('polPreview');
  function draw() {
    const pairs = inp.value.split(',').map(t => t.split(':').map(x => parseFloat(x))).filter(p => p.length === 2 && !isNaN(p[0]) && !isNaN(p[1])).sort((a, b) => b[0] - a[0]);
    if (!pairs.length) { out.innerHTML = '<p class="muted small m-0">Enter at least one days:percent pair.</p>'; return; }
    out.innerHTML = '<table class="table"><thead><tr><th>Cancelled</th><th class="num">Charge</th></tr></thead><tbody>' + pairs.map((p, i) =>
      `<tr><td>${i === 0 ? p[0] + '+ days before' : (p[0] === pairs[i - 1][0] - 1 ? p[0] : p[0] + '–' + (pairs[i - 1][0] - 1)) + ' days before'}</td><td class="num"><b class="${p[1] > 100 ? 'text-red' : ''}">${p[1]}%</b></td></tr>`).join('') + '</tbody></table>' +
      (pairs[0][0] > 0 && pairs[pairs.length - 1][0] > 0 ? '<p class="tiny muted m-0">Nothing is charged closer than ' + pairs[pairs.length - 1][0] + ' days unless you add a 0: entry.</p>' : '');
  }
  inp.addEventListener('input', draw); draw();
})();
</script>
@endsection
