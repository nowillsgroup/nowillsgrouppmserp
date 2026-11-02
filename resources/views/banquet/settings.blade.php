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
    <x-field name="banquet_cancel_policy" label="Cancellation policy" :value="$s['banquet_cancel_policy']" maxlength="200" required hint="days-before:percent pairs, e.g. 60:0,30:25,14:50,7:75,0:100 = free 60+ days out, 25% from 30 days … 100% on the day."/>
  </div></div>
  <div class="card"><div class="card-head"><div><h3>Cancellation scale</h3><span class="tiny muted">Live preview · not saved</span></div></div><div class="card-body" id="polPreview" role="region" aria-live="polite" aria-atomic="true" aria-label="Cancellation policy preview"></div></div>
</div>
<div class="card"><div class="card-head"><h3>Terms printed on proposals &amp; contracts</h3></div><div class="card-body">
  <x-field name="banquet_terms" type="textarea" rows="8" maxlength="5000" :value="$s['banquet_terms']"/>
</div></div>
<div class="flex" style="justify-content:flex-end"><button class="btn btn-primary" id="saveBq"><x-icon name="check"/> Save settings</button></div>
</form>
<script>
(() => {
  const input = document.getElementById('f_banquet_cancel_policy');
  const preview = document.getElementById('polPreview');
  if (!input || !preview) return;

  const note = (text, className = 'tiny muted m-0') => {
    const p = document.createElement('p');
    p.className = className;
    p.textContent = text;
    return p;
  };
  const message = text => preview.replaceChildren(note(text, 'alert alert-warn m-0'));

  function draw() {
    const raw = input.value.trim();
    const syntax = /^\d+\s*:\s*\d+(\.\d+)?(?:\s*,\s*\d+\s*:\s*\d+(\.\d+)?)*$/;
    if (!raw) {
      message('Enter one or more days:percent tiers to preview the cancellation scale.');
      return;
    }
    if (!syntax.test(raw)) {
      message('Use days:percent pairs separated by commas, for example 60:0,30:25,14:50,7:75,0:100.');
      return;
    }

    const pairs = raw.split(',').map(pair => {
      const [days, pct] = pair.split(':').map(value => Number(value.trim()));
      return {days, pct};
    }).sort((a, b) => b.days - a.days);
    if (pairs.some(pair => pair.pct > 100)) {
      message('Each cancellation percentage must be 100% or less.');
      return;
    }

    const seenDays = new Set();
    let duplicateDays = false;
    pairs.forEach(pair => {
      if (seenDays.has(pair.days)) duplicateDays = true;
      else seenDays.add(pair.days);
    });
    if (duplicateDays) {
      message('Each days-before threshold should appear once. Remove duplicate thresholds to preview the scale clearly.');
      return;
    }

    const table = document.createElement('table');
    table.className = 'table bq-policy-preview';
    const caption = document.createElement('caption');
    caption.className = 'sr-only';
    caption.textContent = 'Preview of cancellation charges by days before the event';
    const head = document.createElement('thead');
    const headerRow = document.createElement('tr');
    ['Cancelled', 'Charge'].forEach(label => {
      const th = document.createElement('th');
      th.scope = 'col';
      th.textContent = label;
      headerRow.appendChild(th);
    });
    head.appendChild(headerRow);
    const body = document.createElement('tbody');

    pairs.forEach((pair, index) => {
      const row = document.createElement('tr');
      const range = index === 0
        ? `${pair.days}+ days before`
        : (pair.days === pairs[index - 1].days - 1
          ? `${pair.days} days before`
          : `${pair.days}–${pairs[index - 1].days - 1} days before`);
      const label = document.createElement('th');
      label.scope = 'row';
      label.textContent = range;
      const percentage = document.createElement('td');
      percentage.className = 'num';
      percentage.textContent = `${pair.pct}%`;
      row.append(label, percentage);
      body.appendChild(row);
    });

    table.append(caption, head, body);
    const content = [table];
    if (pairs[pairs.length - 1].days > 0) {
      content.push(note(`Nothing is charged closer than ${pairs[pairs.length - 1].days} days unless you add a 0-day tier.`));
    }
    preview.replaceChildren(...content);
  }

  input.addEventListener('input', draw);
  draw();
})();
</script>
@endsection
