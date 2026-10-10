@extends('layouts.app')
@section('title', 'Buy Foreign Currency')
@section('crumbs') Foreign Guests / <a href="{{ route('fg.fx') }}">Forex</a> / Buy @endsection
@section('actions') <a class="btn" href="{{ route('fg.rates') }}"><x-icon name="trend"/> Today's rates</a> @endsection
@section('content')
@unless($enabled)<div class="alert alert-warn"><x-icon name="alert"/><div>The forex desk is switched off.</div></div>@endunless
@if($rates->isEmpty())<div class="alert alert-danger" id="noRates"><x-icon name="alert"/><div>No exchange rates set for {{ bdate()->format('d M Y') }}. <a href="{{ route('fg.rates') }}">Set today's rates</a> before buying currency.</div></div>@endif
<div class="grid g-2-1">
<form method="post" action="{{ route('fg.fx.store') }}" id="fxForm" class="card">@csrf
  <div class="card-head"><h3>Exchange</h3></div>
  <div class="card-body form-grid">
    <div class="field span-all"><label for="f_stay">In-house guest <span class="req">*</span></label>
      <select name="reservation_room_id" id="f_stay" required class="@error('reservation_room_id') is-invalid @enderror"><option value="">— Choose the room —</option>
        @foreach($stays as $r)<option value="{{ $r['stay']->id }}" @selected((string) old('reservation_room_id', $stay?->id) === (string) $r['stay']->id)>{{ $r['stay']->room?->room_no }} · {{ $r['guest']->full_name }} ({{ $r['guest']->nationality ?: 'nationality?' }})</option>@endforeach
      </select>
      @if($stays->isEmpty())<span class="hint">No foreign guests are in house.</span>@endif
    </div>
    <div class="field"><label for="f_currency">Currency <span class="req">*</span></label>
      <select name="currency_id" id="f_currency" required>
        @foreach($currencies as $c)<option value="{{ $c->id }}" data-unit="{{ $c->unit }}" data-code="{{ $c->code }}" @selected((string) old('currency_id') === (string) $c->id)>{{ $c->code }} – {{ $c->name }}{{ isset($rates[$c->id]) ? ' @ '.rtrim(rtrim(number_format($rates[$c->id]->buy_rate, 4), '0'), '.').($c->unit > 1 ? ' / '.$c->unit : '') : ' (no rate)' }}</option>@endforeach
      </select></div>
    <x-field name="fc_amount" label="Amount in foreign currency" type="number" step="0.01" min="0.01" required/>
    <x-field name="instrument" label="Tendered as" type="select" :options="['notes' => 'Currency notes', 'tc' => 'Travellers’ cheques']" :placeholder="false" value="notes"/>
    <div class="field"><label>Pay the guest</label>
      <div class="flex" style="gap:1rem;padding-top:.35rem">
        <label class="check"><input type="radio" name="payout" value="cash" @checked(old('payout', $payout) === 'cash')> Cedis in cash</label>
        <label class="check"><input type="radio" name="payout" value="folio" @checked(old('payout', $payout) === 'folio')> Credit the room bill</label>
      </div></div>
    <x-field name="passport_no" label="Passport no." hint="blank = the one on the guest profile"/>
    <label class="check"><input type="checkbox" name="certificate" value="1" @checked(old('certificate', '1'))> Issue an encashment certificate</label>
    <x-field name="notes" label="Notes" class="span-all"/>
  </div>
  <div class="card-foot"><button class="btn btn-primary" id="fxSubmit" disabled><x-icon name="check"/> Buy currency</button> <span class="small muted" id="fxHint">Choose the guest and enter the amount.</span></div>
</form>

<style>#fxQuote .table th{white-space:normal;padding-left:.5rem;padding-right:.5rem;width:42%}#fxQuote .table td{padding-left:.5rem;padding-right:.5rem;white-space:normal;word-break:break-word}#fxQuote{min-width:0}</style>
<div class="card" id="fxQuote"><div class="card-head"><h3>Quote</h3></div><div class="card-body">
  <div id="qGuest" class="small muted mb-1"></div>
  <table class="table table-compact">
    <tr><th>Buy rate</th><td class="num" id="qRate">—</td></tr>
    <tr><th>Cedi value</th><td class="num" id="qInr">—</td></tr>
    <tr class="q-cash"><th>Service fee</th><td class="num" id="qFee">—</td></tr>
    <tr><th id="qNetLabel">Pay the guest</th><td class="num bold" style="font-size:1.2rem" id="qNet">—</td></tr>
    <tr class="q-cash"><th>Cash limit left</th><td class="num" id="qLimit" style="white-space:pre-line">—</td></tr>
    <tr class="q-folio" hidden><th>Balance due on the bill</th><td class="num" id="qBalance">—</td></tr>
  </table>
  <div id="qCarried" class="small text-amber" hidden>Today's rate was carried from yesterday – confirm it with the bank.</div>
  <ul id="qErrors" class="small text-red" style="padding-left:1rem;margin:.5rem 0 0"></ul>
  <p class="tiny muted mt-1">Cash: max USD {{ number_format($cfg['cash_cap_usd']) }} per guest within {{ $cfg['aggregate_days'] }} days · passport copy on file from {{ money($cfg['id_copy_inr']) }} · currency exchange is VAT-exempt. Bill credits carry no conversion charges.</p>
</div></div>
</div>
@endsection
@push('scripts')
<script>
(() => {
  const f = document.getElementById('fxForm'), btn = document.getElementById('fxSubmit'), hint = document.getElementById('fxHint');
  const $ = id => document.getElementById(id);
  let timer = null, seq = 0, sending = false;
  const run = async () => {
    if (sending) return; // no quote requests once the form is posted (they would eat the flash message)
    const fd = new FormData(f), my = ++seq;
    const params = new URLSearchParams({reservation_room_id: fd.get('reservation_room_id') || '', currency_id: fd.get('currency_id') || '', fc_amount: fd.get('fc_amount') || 0,
      payout: fd.get('payout') || 'cash', passport_no: fd.get('passport_no') || ''});
    const folio = (fd.get('payout') === 'folio');
    document.querySelectorAll('.q-cash').forEach(el => el.hidden = folio);
    document.querySelectorAll('.q-folio').forEach(el => el.hidden = !folio);
    $('qNetLabel').textContent = folio ? 'Credit to the bill' : 'Pay the guest';
    try {
      const r = await fetch('{{ route('fg.fx.quote', [], false) }}?' + params, {headers: {'Accept': 'application/json'}});
      if (my !== seq) return;
      const q = await r.json();
      $('qRate').textContent = q.rate ? q.rate + (q.unit > 1 ? ' per ' + q.unit : '') + ' (ref ' + q.reference + ')' : '—';
      $('qInr').textContent = q.inr ? q.fmt.inr : '—';
      $('qFee').textContent = q.fmt.fee;
      $('qNet').textContent = q.net > 0 ? q.fmt.net : '—';
      $('qLimit').textContent = q.remaining !== null ? 'USD ' + Number(q.remaining).toFixed(2) + ' of ' + Number(q.cap).toFixed(0) + (q.usd ? '\nthis: USD ' + Number(q.usd).toFixed(2) : '') : '—';
      $('qBalance').textContent = q.fmt.balance || '—';
      $('qCarried').hidden = !q.carried;
      $('qGuest').textContent = q.guest ? q.guest.name + ' · ' + (q.guest.nationality || 'nationality not set') + (q.guest.passport ? ' · passport ' + q.guest.passport : ' · no passport on file') : '';
      $('qErrors').innerHTML = '';
      (q.errors || []).forEach(e => { const li = document.createElement('li'); li.textContent = e; $('qErrors').appendChild(li); });
      btn.disabled = (q.errors || []).length > 0;
      hint.textContent = btn.disabled ? 'Fix the points in the quote first.' : (folio ? 'The credit posts to the room bill.' : 'Count the currency, then pay the guest ' + q.fmt.net + '.');
    } catch (e) { hint.textContent = 'Could not get a quote – check the connection.'; }
  };
  f.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(run, 250); });
  f.addEventListener('change', () => { clearTimeout(timer); timer = setTimeout(run, 50); });
  f.addEventListener('submit', () => { sending = true; clearTimeout(timer); btn.disabled = true; });
  run();
})();
</script>
@endpush
