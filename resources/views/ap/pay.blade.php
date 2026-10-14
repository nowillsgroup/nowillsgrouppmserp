@extends('layouts.app')
@section('title', 'Pay Supplier')
@section('crumbs') <a href="{{ route('ap.payments') }}">Supplier Payments</a> / New @endsection
@section('content')
@php
  $today = bdate()->toDateString();
  $lock = \App\Services\LedgerService::lockDate();
  $home = strtoupper(trim((string) (hotel()?->currency ?: 'GHS')));
  $fxOn = $fxOn ?? false; $fxPay = $fxPay ?? []; $fxDeal = $fxDeal ?? false;
  $fxCurrencies = array_values(array_filter($fxPay, fn ($c) => ! $c['home']));
@endphp
<form method="post" action="{{ route('ap.payments.store') }}" id="payForm">@csrf
<div class="card"><div class="card-body form-grid">
  <div class="field span-2"><label>Supplier <span class="req">*</span></label>
    <select name="supplier_id" required onchange="location.href='{{ route('ap.payments.create') }}?supplier=' + this.value">
      <option value="">— Select supplier —</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected($supplier?->id === $s->id)>{{ $s->name }}{{ $s->due > 0 ? ' · due '.money($s->due) : '' }}</option>@endforeach
    </select></div>
  @if($supplier)
  <div class="field"><label>Payment date <span class="req">*</span></label><input type="date" name="date" value="{{ old('date', $today) }}" max="{{ $today }}" @if($lock) min="{{ \Illuminate\Support\Carbon::parse($lock)->addDay()->toDateString() }}" @endif required></div>
  <div class="field"><label>Paid by <span class="req">*</span></label><select name="payment_mode_id" required>@foreach($modes as $m)<option value="{{ $m->id }}" @selected(old('payment_mode_id', $modes->firstWhere('type', 'bank')?->id) == $m->id)>{{ $m->name }}</option>@endforeach</select></div>
  @if(count($fxCurrencies))
    @if($fxOn)
    <div class="field span-2"><label>Paid in <span class="req">*</span></label>
      <select name="currency" id="payCurrency" data-home="{{ $home }}" data-rate-url="{{ route('ap.payments.fx-rate', [], false) }}">
        <option value="" data-rate="" @selected(old('currency', '') === '')>{{ $home }} — the property's own currency{{ isset($fxPay[$home]) ? ' · '.money($fxPay[$home]['booked']).' of bills open' : '' }}</option>
        @foreach($fxCurrencies as $c)
        <option value="{{ $c['currency'] }}" data-rate="{{ $c['rate'] !== null ? number_format($c['rate'], 4, '.', '') : '' }}" data-basis="{{ $c['basis'] }}" data-board="{{ $c['date'] }}" data-owed="{{ number_format($c['owed'], 2, '.', '') }}" data-booked="{{ number_format($c['booked'], 2, '.', '') }}" data-bills="{{ $c['bills'] }}" @selected(old('currency', '') === $c['currency'])>{{ $c['currency'] }} — {{ $c['bills'] }} bill(s) open, {{ $c['currency'] }} {{ number_format($c['owed'], 2) }} owed, carried at {{ money($c['booked']) }}{{ $c['rate'] !== null ? ' · board '.number_format($c['rate'], 4) : ' · no rate on the board' }}</option>
        @endforeach
      </select>
      <div class="tiny muted" id="payFxNote">A bill billed in a foreign currency can be settled in it: the bank's figure is recorded, the cedis it cost are derived at the rate the bank used, and the difference against what the bill was booked at is posted to Exchange Gain / Loss.</div>
    </div>
    <div class="field hidden" id="fxAmountField"><label>Amount paid in <span id="fxCcyLabel" class="bold">—</span> <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="fx_amount" id="fxAmount" value="{{ old('fx_amount') }}" disabled><div class="tiny muted">As the bank's advice states it, net of any GRA withholding.</div></div>
    <div class="field hidden" id="fxRateField"><label>Board rate ({{ $home }} per unit)</label><input type="number" step="0.0001" id="fxBoardRate" value="" readonly disabled><div class="tiny muted" id="fxRateNote">Looking up the board for the payment date…</div>
      @if($fxDeal)<label class="check mt-1"><input type="checkbox" name="fx_deal" value="1" id="fxDealSwitch" @checked(old('fx_deal'))> Use the bank's deal rate from its advice</label><input type="number" step="0.0001" min="0.0001" max="9999" name="fx_rate" id="fxRate" value="{{ old('fx_rate') }}" placeholder="Tick above, then type the bank's rate" disabled><div class="tiny muted">Leave this blank to use the board rate. A rate you type is saved as a deal rate and compared with the board.</div>@endif
    </div>
    @else
    <div class="field span-2"><label>Foreign-currency payments</label><div class="tiny muted">This supplier has open bills in {{ implode(', ', array_column($fxCurrencies, 'currency')) }}, but foreign settlement is switched off. Pay in {{ $home }} or turn it on under <a href="{{ route('fg.settings') }}">Foreign Guests › Settings › Forex desk</a>.</div></div>
    @endif
  @endif

  <div class="field"><label id="payAmountLabel">{{ $whtOn ? 'Amount settled (before tax withheld)' : 'Amount paid' }} <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="amount" id="payAmount" value="{{ old('amount') }}" required></div>
  <div class="field"><label>Reference</label><input name="reference" maxlength="80" value="{{ old('reference') }}" placeholder="Cheque / transfer / MoMo ref."></div>
  <div class="field span-2"><label>Notes</label><input name="notes" maxlength="300" value="{{ old('notes') }}"></div>
  @if($supplier->bank_details)<div class="field span-2"><label>Supplier bank details</label><div class="pad-input small" style="white-space:pre-line">{{ $supplier->bank_details }}</div></div>@endif
  @endif
</div></div>

@if($supplier)
<div class="card flush"><div class="card-head"><h3>Allocate to bills</h3>
  <div class="flex gap-1"><button type="button" class="btn btn-sm" id="autoAlloc"><x-icon name="refresh" size="sm"/> Oldest due first</button><button type="button" class="btn btn-sm" id="payAll">Pay all</button><button type="button" class="btn btn-sm btn-ghost" id="clearAlloc">Clear</button></div></div>
<div class="card-body table-wrap"><table class="table ap-alloc" id="allocTable">
<thead><tr><th style="width:32px"></th><th>Bill</th><th>Supplier inv.</th><th>Date</th><th>Due</th><th class="num">Total</th><th class="num">Outstanding</th><th class="num">Pay now</th></tr></thead><tbody>
@forelse($bills as $b)
@php $od = $b->daysOverdue(); $pre = in_array($b->id, $preselect); @endphp
<tr data-out="{{ $b->outstanding() }}" data-ccy="{{ $b->isForeign() ? $b->currency : $home }}" data-fx-rate="{{ $b->isForeign() ? number_format((float) $b->fx_rate, 4, '.', '') : '' }}">
  <td><input type="checkbox" class="pick" @checked($pre || old('alloc.'.$b->id))></td>
  <td><a href="{{ route('ap.bills.show', $b) }}" target="_blank" class="bold">{{ $b->doc_no }}</a><div class="tiny muted">{{ $b->kind === 'goods' ? $b->purchaseOrder?->po_no : 'Expense' }}</div></td>
  <td>{{ $b->supplier_ref }}</td><td class="nowrap">{{ $b->doc_date->format('d M Y') }}</td>
  <td class="nowrap {{ $od ? 'text-red bold' : '' }}">{{ $b->due_date->format('d M Y') }}@if($od)<div class="tiny">{{ $od }}d overdue</div>@endif</td>
  <td class="num">{{ money($b->total, false) }}</td><td class="num">{{ money($b->outstanding(), false) }}</td>
  <td class="num"><input type="number" step="0.01" min="0" max="{{ $b->outstanding() }}" class="input input-sm num alloc" name="alloc[{{ $b->id }}]" value="{{ old('alloc.'.$b->id, $pre ? $b->outstanding() : '') }}"></td>
</tr>
@empty<tr><td colspan="8" class="empty">No open bills. The whole payment will be held as an advance / supplier credit.</td></tr>@endforelse
</tbody></table>
<div class="gl-balance" style="padding-right:1rem"><span>Allocated <span id="tAlloc">0.00</span></span><span>Paid <span id="tPaid">0.00</span></span><span id="tLeft" class="ok">Unapplied 0.00</span><span id="fxDiffBox" class="hidden">Exchange difference <span id="fxDiffVal" class="bold">0.00</span></span></div>
</div></div>

@if($whtOn)
<div class="card" id="whtBox" data-url="{{ route('ap.wht.quote') }}" data-supplier="{{ $supplier->id }}"><div class="card-head"><h3><x-icon name="receipt"/> Withholding tax (GRA)</h3><span class="small muted">{{ \App\Services\WhtService::label($supplier->wht_category) }}@if($supplier->wht_exempt_ref) · exemption {{ $supplier->wht_exempt_ref }}@endif · <a href="{{ route('ap.wht') }}">WHT return</a></span></div>
  <div class="card-body">
    <dl class="kv" style="grid-template-columns:repeat(4,auto 1fr)">
      <dt>Taxable (excl. VAT)</dt><dd id="whtBase">—</dd><dt>WHT <span id="whtRate"></span></dt><dd id="whtAmt" class="bold">—</dd>
      <dt>Withholding VAT</dt><dd id="wvatAmt">—</dd><dt id="whtNetLabel">Pay the supplier</dt><dd id="whtNet" class="bold text-green">—</dd>
    </dl>
    <p class="small muted mt-1" id="whtNote"></p>
    <div id="whtSkipRow" class="hidden"><label class="check"><input type="checkbox" name="wht_skip" value="1" id="whtSkip" @checked(old('wht_skip'))> Do not withhold on this payment</label>
      <input name="wht_reason" id="whtReason" maxlength="200" class="input mt-1" placeholder="Reason, e.g. GRA exemption certificate no. / tax already withheld" value="{{ old('wht_reason') }}"></div>
  </div></div>
@endif

@if($held->count() || $unbilled->count())
<div class="alert alert-info"><x-icon name="alert"/><div>
  @if($held->count())<div><b>{{ $held->count() }} bill(s) on hold</b> ({{ $held->pluck('doc_no')->join(', ') }}) – not payable until the price variance is approved.</div>@endif
  @if($unbilled->count())<div><b>{{ money($unbilled->sum('receiptValue')) }}</b> of goods received is not yet billed ({{ $unbilled->pluck('po_no')->join(', ') }}). Match the supplier invoice first, or pay on account.</div>@endif
</div></div>
@endif

<div class="flex gap-2" style="justify-content:flex-end"><a class="btn" href="{{ route('ap.payments') }}">Cancel</a><button class="btn btn-primary" id="savePay"><x-icon name="check"/> Record payment</button></div>
<p class="small muted">Posts Dr Accounts Payable / Cr the cash or bank account of the chosen mode on the payment date{{ $whtOn ? ' (net of tax withheld, which is credited to WHT Payable and owed to GRA)' : '' }}. Any amount not allocated stays as supplier credit and can be applied to future bills.@if(count($fxCurrencies)) A payment made in a foreign currency posts Dr Accounts Payable for what the bills were booked at, Cr the bank for what it cost in {{ $home }}, and the difference to Exchange Gain / Loss — so the control account still agrees with the bills. It has to be allocated to bills in that same currency, and it does not go on account.@endif</p>
@endif
</form>
@endsection
@push('scripts')<script>
(() => {
  const tbl = document.getElementById('allocTable'); if (!tbl) return;
  const amt = document.getElementById('payAmount'), save = document.getElementById('savePay');
  const rows = [...tbl.querySelectorAll('tbody tr[data-out]')];
  const f = v => Math.round((parseFloat(v) || 0) * 100) / 100;
  // v7.54 a payment in the currency the supplier billed in: the cedi amount is derived, never typed
  const cur = document.getElementById('payCurrency'), fxA = document.getElementById('fxAmount'), fxR = document.getElementById('fxRate');
  const fxBoard = document.getElementById('fxBoardRate'), dealSwitch = document.getElementById('fxDealSwitch');
  const isFx = () => !!cur && cur.value !== '';
  const opt = () => (cur && cur.selectedOptions[0]) || null;
  const board = () => parseFloat(boardReady ? ((fxBoard && fxBoard.value) || '0') : ((opt() && opt().dataset.rate) || '0')) || 0;
  const rate = () => (dealSwitch && dealSwitch.checked && fxR && parseFloat(fxR.value || '0') > 0) ? parseFloat(fxR.value) : board();
  let fxTax = 0, fxWvat = 0, rateSeq = 0, boardReady = false;
  const withheldNow = () => (document.getElementById('whtSkip') && document.getElementById('whtSkip').checked) ? 0 : fxTax + fxWvat;
  function recompute() {
    if (!isFx()) return;
    const r = rate();
    amt.value = r > 0 ? (Math.round(f(fxA.value) * r * 100) / 100).toFixed(2) : '';
  }
  function loadBoard() {
    if (!isFx() || !cur.dataset.rateUrl) return;
    const my = ++rateSeq, date = document.querySelector('[name=date]').value;
    boardReady = false;
    if (!(dealSwitch && dealSwitch.checked && fxR && parseFloat(fxR.value || '0') > 0)) amt.value = '';
    total();
    const q = new URLSearchParams({ currency: cur.value, date });
    fetch(cur.dataset.rateUrl + '?' + q.toString(), { headers: { Accept: 'application/json' } }).then(r => r.ok ? r.json() : null).then(j => {
      if (!j || my !== rateSeq) return;
      boardReady = true;
      if (fxBoard) fxBoard.value = j.rate === null ? '' : Number(j.rate).toFixed(4);
      const note = document.getElementById('fxRateNote');
      if (note) note.textContent = j.rate === null
        ? 'No rate on the board for ' + date + ' or inside the carry-forward window. Set one under Foreign Guests › Exchange Rates.'
        : (j.basis + ' board of ' + j.date + (j.stale ? ' — carried forward.' : '.') + (dealSwitch ? ' Leave the deal-rate box unticked to use this rate.' : ''));
      if (fxR && dealSwitch && !dealSwitch.checked) fxR.value = '';
      if (j.rate !== null || (dealSwitch && dealSwitch.checked && fxR && parseFloat(fxR.value || '0') > 0)) recompute();
      else amt.value = '';
      total(); wht();
    });
  }
  function paintFx() {
    if (!cur) return;
    const on = isFx(), o = opt();
    document.getElementById('fxAmountField').classList.toggle('hidden', !on);
    document.getElementById('fxRateField').classList.toggle('hidden', !on);
    fxA.disabled = !on;
    if (fxR) fxR.disabled = !on || !dealSwitch || !dealSwitch.checked;
    amt.readOnly = on; amt.classList.toggle('pad-input', on);
    document.getElementById('payAmountLabel').firstChild.textContent = on ? 'Cedis transferred ' : (whtLabel);
    document.getElementById('fxDiffBox').classList.toggle('hidden', !on);
    if (on) document.getElementById('fxCcyLabel').textContent = cur.value;
    // only the bills booked in the currency being paid can be settled by it
    rows.forEach(r => {
      const i = r.querySelector('.alloc'), wrong = on && r.dataset.ccy !== cur.value;
      if (wrong) { i.value = ''; i.disabled = true; r.classList.add('muted-row'); } else { i.disabled = false; r.classList.remove('muted-row'); }
    });
    if (on) { loadBoard(); total(); wht(); } else { boardReady = false; if (fxBoard) fxBoard.value = ''; total(); wht(); }
  }
  function total() {
    let a = 0;
    rows.forEach(r => { const i = r.querySelector('.alloc'); if (f(i.value) > f(r.dataset.out)) i.value = f(r.dataset.out); a += f(i.value); r.classList.toggle('picked', f(i.value) > 0); r.querySelector('.pick').checked = f(i.value) > 0; });
    a = f(a); const p = f(amt.value), left = f(p + (isFx() ? withheldNow() : 0) - a), el = document.getElementById('tLeft');
    document.getElementById('tAlloc').textContent = a.toFixed(2); document.getElementById('tPaid').textContent = p.toFixed(2);
    if (isFx()) {
      // the gap between what the payment cost and what the bills were booked at is the realised exchange difference
      el.textContent = left === 0 ? 'No exchange difference' : (left > 0 ? 'Exchange loss ' : 'Exchange gain ') + Math.abs(left).toFixed(2);
      el.className = left === 0 ? 'ok' : (left > 0 ? 'off' : 'ok');
      document.getElementById('fxDiffVal').textContent = (left > 0 ? '−' : left < 0 ? '+' : '') + Math.abs(left).toFixed(2) + (left > 0 ? ' loss' : left < 0 ? ' gain' : '');
      save.disabled = !(p > 0 && a > 0 && Math.abs(left) <= a + 0.01);
    } else {
      el.textContent = left < 0 ? 'Over-allocated by ' + (-left).toFixed(2) : 'Unapplied (credit) ' + left.toFixed(2); el.className = left < 0 ? 'off' : 'ok';
      document.getElementById('fxDiffVal').textContent = '0.00';
      save.disabled = !(p > 0 && left >= 0);
    }
  }
  function auto() {
    if (isFx()) {
      // settling a foreign position means taking each bill's whole booked figure: what is left over is the exchange move
      rows.forEach(r => { const i = r.querySelector('.alloc'); i.value = i.disabled ? '' : f(r.dataset.out).toFixed(2); });
      total();

      return;
    }
    let left = f(amt.value);
    rows.forEach(r => { const take = Math.min(left, f(r.dataset.out)); r.querySelector('.alloc').value = take > 0 ? take.toFixed(2) : ''; left = f(left - take); });
    total();
  }
  tbl.addEventListener('input', total);
  tbl.addEventListener('change', e => {
    if (!e.target.classList.contains('pick')) return;
    const r = e.target.closest('tr'), i = r.querySelector('.alloc');
    i.value = e.target.checked ? f(r.dataset.out).toFixed(2) : '';
    if (e.target.checked && !isFx() && !f(amt.value)) amt.value = rows.reduce((s, x) => s + f(x.querySelector('.alloc').value), 0).toFixed(2);
    total();
  });
  amt.addEventListener('input', total);
  document.getElementById('autoAlloc').onclick = auto;
  document.getElementById('payAll').onclick = () => { rows.forEach(r => { const i = r.querySelector('.alloc'); i.value = i.disabled ? '' : f(r.dataset.out).toFixed(2); }); if (!isFx()) amt.value = rows.reduce((s, r) => s + f(r.dataset.out), 0).toFixed(2); total(); wht(); };
  document.getElementById('clearAlloc').onclick = () => { rows.forEach(r => r.querySelector('.alloc').value = ''); total(); };
  // v4.4 WHT preview (server-side quote)
  const box = document.getElementById('whtBox'); let tmr = null, seq = 0;
  function wht() {
    if (!box) return;
    clearTimeout(tmr);
    tmr = setTimeout(() => {
      const allocated = rows.reduce((sum, r) => sum + f(r.querySelector('.alloc').value), 0);
      const q = new URLSearchParams({ supplier_id: box.dataset.supplier, amount: isFx() ? f(allocated) : f(amt.value), date: document.querySelector('[name=date]').value });
      rows.forEach(r => { const i = r.querySelector('.alloc'); if (f(i.value) > 0) q.append(i.name, f(i.value)); });
      const my = ++seq;
      fetch(box.dataset.url + '?' + q.toString(), { headers: { Accept: 'application/json' } }).then(r => r.ok ? r.json() : null).then(j => {
        if (!j || my !== seq) return;
        fxTax = f(j.wht); fxWvat = f(j.wvat);
        document.getElementById('whtBase').textContent = j.base_f;
        document.getElementById('whtRate').textContent = j.rate > 0 ? '(' + j.rate + '%)' : '';
        document.getElementById('whtAmt').textContent = j.wht_f;
        document.getElementById('wvatAmt').textContent = j.wvat_f;
        document.getElementById('whtNetLabel').textContent = isFx() ? 'Cedis transferred to supplier' : 'Pay the supplier';
        document.getElementById('whtNet').textContent = isFx()
          ? (cur.dataset.home + ' ' + new Intl.NumberFormat('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(f(amt.value)))
          : j.net_f;
        document.getElementById('whtNote').textContent = j.note || '';
        document.getElementById('whtSkipRow').classList.toggle('hidden', !j.overridable);
        if (isFx() && fxA && !fxA.dataset.touched) {
          const payRate = rate();
          // Each GHS allocation corresponds to its own invoice-date foreign amount. Withholding is kept in GHS,
          // then translated at the settlement rate before reducing the amount sent to the supplier.
          const foreignGross = rows.reduce((sum, r) => {
            const i = r.querySelector('.alloc'), bookedRate = parseFloat(r.dataset.fxRate || '0') || 0;
            return sum + (bookedRate > 0 ? f(i.value) / bookedRate : 0);
          }, 0);
          const taxCut = document.getElementById('whtSkip')?.checked ? 0 : fxTax + fxWvat;
          const netFx = Math.max(0, foreignGross - taxCut / payRate);
          if (payRate > 0 && allocated > 0) { fxA.value = (Math.round(netFx * 100) / 100).toFixed(2); recompute(); }
        }
        total();
      });
    }, 250);
  }
  tbl.addEventListener('input', wht); tbl.addEventListener('change', wht); amt.addEventListener('input', wht);
  ['autoAlloc', 'payAll', 'clearAlloc'].forEach(id => document.getElementById(id).addEventListener('click', wht));
  document.querySelector('[name=date]').addEventListener('change', wht);
  const skip = document.getElementById('whtSkip'); if (skip) { const rs = document.getElementById('whtReason'); const t = () => { rs.required = skip.checked; rs.classList.toggle('hidden', !skip.checked); total(); wht(); }; skip.addEventListener('change', t); t(); }
  if (!f(amt.value) && !isFx()) { const pre = rows.reduce((s, r) => s + f(r.querySelector('.alloc').value), 0); if (pre) amt.value = pre.toFixed(2); }
  // v7.54 the foreign-currency wiring
  const whtLabel = document.getElementById('payAmountLabel').firstChild.textContent;
  if (cur) {
    cur.addEventListener('change', () => { fxA.dataset.touched = ''; if (fxR) fxR.value = ''; if (dealSwitch) dealSwitch.checked = false; paintFx(); });
    fxA.addEventListener('input', () => { fxA.dataset.touched = '1'; recompute(); total(); wht(); });
    if (fxR) fxR.addEventListener('input', () => { recompute(); total(); wht(); });
    if (dealSwitch) dealSwitch.addEventListener('change', () => { fxR.disabled = !dealSwitch.checked; if (!dealSwitch.checked) fxR.value = ''; recompute(); total(); wht(); });
    document.querySelector('[name=date]').addEventListener('change', () => { loadBoard(); total(); wht(); });
  }
  paintFx();
  total(); wht();
})();
</script>@endpush
