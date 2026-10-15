@extends('layouts.app')
@section('title', 'Ticket Outlet')
@section('crumbs') <a href="{{ route('club.index') }}">Wellness &amp; Recreation</a> / Ticket outlet @endsection
@section('actions')
<a class="btn" href="{{ route('recreation.sales') }}"><x-icon name="receipt"/> Ticket sales</a>
@if(can_do('club.manage'))<a class="btn" href="{{ route('recreation.types') }}"><x-icon name="settings"/> Ticket types</a>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Sales today" :value="$stats['sales']" icon="receipt" tone="blue"/>
  <x-stat label="Takings today" :value="money($stats['total'])" icon="money" tone="green" sub="incl. tax"/>
  <x-stat label="Tickets issued today" :value="$stats['tickets']" icon="users" tone="purple" sub="walk-in entries"/>
  <x-stat label="Items sold today" :value="$stats['items']" icon="trend"/>
</div>

<div class="flex gap-3" style="align-items:flex-start;flex-wrap:wrap">
  {{-- ── catalog ── --}}
  <div style="flex:1 1 520px;min-width:320px">
    @foreach($types as $cat => $list)
      <h3 class="mt-2 mb-1">{{ \App\Models\ClubTicketType::CATEGORIES[$cat] ?? $cat }}</h3>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px">
        @foreach($list as $t)
          <button type="button" class="card catalog-item" data-add="{{ $t->id }}" data-name="{{ $t->name }}" data-price="{{ $t->price }}" style="cursor:pointer;text-align:left;padding:14px;border:1px solid var(--line)">
            <div class="bold">{{ $t->name }}</div>
            <div class="flex gap-2" style="justify-content:space-between;align-items:center;margin-top:8px">
              <span class="badge badge-{{ $t->category === 'access' ? 'green' : ($t->category === 'rental' ? 'blue' : 'purple') }}">{{ \App\Models\ClubTicketType::CATEGORIES[$t->category] }}</span>
              <span class="bold" style="font-size:17px">{{ money($t->price, false) }}</span>
            </div>
            @if($t->issues_pass)<div class="tiny muted mt-1">issues an entry ticket</div>@endif
          </button>
        @endforeach
      </div>
    @endforeach
  </div>

  {{-- ── cart / till ── --}}
  <div class="card" id="till" style="flex:0 0 380px;max-width:100%;position:sticky;top:16px">
    <div class="card-head"><h3>Till</h3><button type="button" class="btn btn-sm btn-ghost" id="clearCart">Clear</button></div>
    <div class="card-body">
      <div class="form-grid">
        <x-field name="guest_name" label="Guest name" class="span-2" id="guest_name" placeholder="Walk-in name"/>
        <x-field name="phone" label="Phone" id="phone"/>
      </div>
      <table class="table mt-2" id="cartTable">
        <thead><tr><th>Item</th><th style="width:110px">Qty</th><th class="num">Amount</th><th></th></tr></thead>
        <tbody></tbody>
      </table>

      <div class="field mt-2"><label>Settlement</label>
        <div class="flex gap-2"><label class="check"><input type="radio" name="settle" id="settlePay" value="pay" checked> <span>Take payment</span></label>
        <label class="check"><input type="radio" name="settle" id="settleRoom" value="room"> <span>Charge to room</span></label></div>
      </div>
      <div class="field" id="roomField" hidden><label for="room_no">Room no.</label><input name="room_no" id="room_no" placeholder="101"></div>

      <div id="payBlock">
        <div class="flex gap-2" style="justify-content:space-between;align-items:center"><label>Payments</label><button type="button" class="btn btn-sm" id="addPay">+ Split payment</button></div>
        <div id="payRows" class="mt-1"></div>
      </div>

      <div class="mt-2" style="border-top:1px solid var(--line);padding-top:10px">
        <div class="flex gap-2" style="justify-content:space-between"><span>Subtotal</span><b id="sumSub">GH₵ 0.00</b></div>
        <div class="flex gap-2" style="justify-content:space-between"><span class="muted">Tax (VAT etc.)</span><span id="sumTax">GH₵ 0.00</span></div>
        <div class="flex gap-2" style="justify-content:space-between;font-size:19px"><span class="bold">Total</span><b id="sumTotal">GH₵ 0.00</b></div>
        <div class="flex gap-2" style="justify-content:space-between" id="changeRow" hidden><span class="muted">Change due</span><b id="sumChange">GH₵ 0.00</b></div>
      </div>
      <p class="tiny text-red mt-1" id="tillMsg" hidden></p>
      <button type="button" class="btn btn-primary mt-2" id="completeSale" style="width:100%;height:46px;font-size:16px">Complete sale</button>
      <p class="tiny muted mt-1">Walk-ins buy tickets here — members keep using their membership at the check-in desk. Prices are {{ \App\Services\TaxService::pricesInclusive() ? 'tax-inclusive — VAT, NHIL, GETFund and the Tourism Levy are extracted from the price' : 'exclusive of VAT/NHIL/GETFund/Tourism Levy ('.rtrim(rtrim(number_format($rate, 2), '0'), '.').'%)' }}.</p>
    </div>
  </div>
</div>

{{-- success modal --}}
<div class="modal" id="doneModal"><div class="modal-box" style="max-width:520px">
  <div class="modal-head"><h3>Sale complete</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div class="flex gap-3" style="flex-wrap:wrap">
      <div class="card" style="flex:1 1 160px;padding:12px"><span class="tiny muted">Sale no.</span><div class="bold" style="font-size:19px" id="doneSaleNo">—</div></div>
      <div class="card" style="flex:1 1 160px;padding:12px"><span class="tiny muted">Total</span><div class="bold" style="font-size:19px" id="doneTotal">—</div></div>
    </div>
    <div id="doneTickets" class="mt-2"></div>
    <p class="mt-2" id="doneChange"></p>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn" id="doneNew">New sale</button>
    <a class="btn btn-primary" id="doneReceipt" href="#" target="_blank"><x-icon name="print"/> Print receipt &amp; tickets</a>
  </div>
</div></div>

<form id="tillForm" hidden>@csrf</form>
@endsection

@push('scripts')
<script>
(() => {
  const money = n => 'GH₵ ' + (Math.round((n + Number.EPSILON) * 100) / 100).toFixed(2);
  const rate = {{ $rate }};
  const cart = [];
  let paySeq = 0;
  const modes = @json($modes->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values());
  const $ = s => document.querySelector(s);
  const emptyCopy = '<tr><td colspan="4"><div class="muted" style="padding:18px;text-align:center">Tap items on the left to add them.</div></td></tr>';
  const token = $('#tillForm [name=_token]').value;

  function lineNet(l) { return l.price * l.qty; }
  function taxOf(net) { return Math.round(net * rate) / 100; }
  function settleMode() { return $('#settleRoom').checked ? 'room' : 'pay'; }

  function render() {
    const t = $('#cartTable tbody');
    t.innerHTML = cart.length ? '' : emptyCopy;
    cart.forEach((l, i) => {
      const net = lineNet(l), tr = document.createElement('tr');
      tr.innerHTML = '<td>' + l.name + '</td>' +
        '<td><button type="button" class="btn btn-sm" data-dec="' + i + '">−</button> <b>' + l.qty + '</b> <button type="button" class="btn btn-sm" data-inc="' + i + '">+</button></td>' +
        '<td class="num">' + money(net + taxOf(net)) + '</td>' +
        '<td class="num"><button type="button" class="btn btn-sm btn-ghost" data-del="' + i + '">✕</button></td>';
      t.appendChild(tr);
    });
    totals();
  }

  function totals() {
    const sub = cart.reduce((s, l) => s + lineNet(l), 0);
    const tax = cart.reduce((s, l) => s + taxOf(lineNet(l)), 0);
    const total = sub + tax;
    $('#sumSub').textContent = money(sub);
    $('#sumTax').textContent = money(tax);
    $('#sumTotal').textContent = money(total);
    let paid = 0;
    document.querySelectorAll('.pay-amount').forEach(el => paid += parseFloat(el.value || 0));
    const change = Math.max(0, paid - total);
    $('#changeRow').hidden = !(settleMode() === 'pay' && paid > 0.004);
    $('#sumChange').textContent = money(change);
    const first = document.querySelector('.pay-amount');
    if (first && first.dataset.auto === '1' && settleMode() === 'pay' && cart.length) first.value = total.toFixed(2);
  }

  document.querySelectorAll('[data-add]').forEach(btn => btn.addEventListener('click', () => {
    const id = +btn.dataset.add, found = cart.find(l => l.id === id);
    if (found) found.qty++; else cart.push({ id, name: btn.dataset.name, price: parseFloat(btn.dataset.price), qty: 1 });
    render();
  }));
  $('#cartTable').addEventListener('click', e => {
    const b = e.target.closest('button'); if (!b) return;
    if (b.dataset.inc !== undefined) cart[+b.dataset.inc].qty++;
    if (b.dataset.dec !== undefined) { const l = cart[+b.dataset.dec]; l.qty--; if (l.qty < 1) cart.splice(+b.dataset.dec, 1); }
    if (b.dataset.del !== undefined) cart.splice(+b.dataset.del, 1);
    render();
  });
  $('#clearCart').addEventListener('click', () => { cart.length = 0; render(); });

  document.querySelectorAll('input[name=settle]').forEach(r => r.addEventListener('change', () => {
    $('#roomField').hidden = settleMode() !== 'room';
    $('#payBlock').hidden = settleMode() !== 'pay';
    totals();
  }));

  function addPayRow(amount) {
    const id = ++paySeq, wrap = document.createElement('div');
    wrap.className = 'flex gap-2 mt-1';
    wrap.innerHTML = '<select class="pay-mode" style="flex:1 1 46%">' +
      modes.map(m => '<option value="' + m.id + '">' + m.name + '</option>').join('') + '</select>' +
      '<input class="pay-amount" type="number" step="0.01" min="0" placeholder="0.00" value="' + (amount || '') + '" data-auto="1" style="flex:1 1 26%">' +
      '<input class="pay-ref" placeholder="Ref" style="flex:1 1 22%">' +
      '<button type="button" class="btn btn-sm btn-ghost" data-paydel="1">✕</button>';
    wrap.querySelector('[data-paydel]').addEventListener('click', () => { wrap.remove(); totals(); });
    wrap.querySelector('.pay-amount').addEventListener('input', e => { e.target.dataset.auto = '0'; totals(); });
    $('#payRows').appendChild(wrap);
    totals();
  }
  $('#addPay').addEventListener('click', () => addPayRow(''));
  addPayRow('');

  function msg(text) { const m = $('#tillMsg'); m.hidden = !text; m.textContent = text || ''; }

  $('#completeSale').addEventListener('click', async () => {
    msg('');
    if (!cart.length) return msg('Add at least one item to the till.');
    const payments = Array.from(document.querySelectorAll('#payRows > div'))
      .map(row => ({
        payment_mode_id: +row.querySelector('.pay-mode').value,
        amount: parseFloat(row.querySelector('.pay-amount').value || 0),
        reference: row.querySelector('.pay-ref').value,
      }))
      .filter(p => p.amount > 0);
    const body = {
      _token: token,
      guest_name: $('#guest_name').value,
      phone: $('#phone').value,
      settle: settleMode(),
      room_no: $('#room_no').value,
      items: cart.map(l => ({ type_id: l.id, qty: l.qty })),
      payments: settleMode() === 'pay' ? payments : [],
    };
    let j;
    try {
      const res = await fetch('{{ route('recreation.sell') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(body),
      });
      j = await res.json();
    } catch (e) { j = { ok: false, message: 'The server did not answer.' }; }
    if (!j.ok) return msg(j.message || 'The sale could not be completed.');
    $('#doneSaleNo').textContent = j.sale_no;
    $('#doneTotal').textContent = j.total;
    $('#doneTickets').innerHTML = (j.tickets && j.tickets.length)
      ? '<div class="bold">Entry tickets</div>' + j.tickets.map(t =>
          '<div class="flex gap-2" style="justify-content:space-between;border-bottom:1px solid var(--line);padding:4px 0"><code class="bold">' + t.pass_no + '</code><span>' + t.name + ' · ' + t.pax + ' pax</span></div>').join('')
      : '<div class="muted">No entry tickets on this sale (rentals / services only).</div>';
    $('#doneChange').innerHTML = (j.change && j.change !== 'GH₵ 0.00') ? '<b>Change due: ' + j.change + '</b>' : '';
    $('#doneReceipt').href = j.receipt_url;
    $('#doneModal').classList.add('open');
    cart.length = 0; render();
  });

  $('#doneNew').addEventListener('click', () => location.reload());

  render();
})();
</script>
@endpush
