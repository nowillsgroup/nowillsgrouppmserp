@extends('layouts.app')
@section('title', 'Ticket Outlet')
@section('crumbs') <a href="{{ route('club.index') }}">Wellness &amp; Recreation</a> / Ticket outlet @endsection
@section('actions')
<a class="btn" href="{{ route('recreation.sales') }}"><x-icon name="receipt"/> Ticket sales</a>
<a class="btn" href="{{ route('recreation.gate') }}"><x-icon name="check"/> Gate check</a>
<a class="btn" href="{{ route('recreation.shift') }}"><x-icon name="wallet"/> Shift{{ $shift ? ' · open' : '' }}</a>
<a class="btn" href="{{ route('recreation.day') }}"><x-icon name="chart"/> Day report</a>
@if(can_do('club.manage'))<a class="btn" href="{{ route('recreation.types') }}"><x-icon name="settings"/> Ticket types</a>@endif
@endsection
@section('content')
<style>
.pos-strip{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;justify-content:space-between;border:1px solid var(--c-border);border-radius:12px;padding:.6rem .8rem;background:var(--c-surface,#fff);margin-bottom:.7rem}
.pos-strip .who{font-size:.85rem}
.pos-strip .dot{width:8px;height:8px;border-radius:999px;display:inline-block;margin-right:.35rem}
.pos-grid{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:1rem;align-items:start}
@media (max-width:1024px){.pos-grid{grid-template-columns:1fr}}
.pos-tools{display:flex;flex-wrap:wrap;gap:.45rem;align-items:center;margin-bottom:.6rem}
.pos-chips{display:flex;flex-wrap:wrap;gap:.35rem}
.pos-chip{border:1px solid var(--c-border);background:#fff;border-radius:999px;padding:.28rem .7rem;font-size:.8rem;cursor:pointer}
.pos-chip.on{background:#166534;border-color:#166534;color:#fff}
.pos-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(168px,1fr));gap:.55rem}
.pos-tile{text-align:left;border:1px solid var(--c-border);border-radius:12px;background:#fff;padding:.6rem .65rem;cursor:pointer;display:flex;flex-direction:column;gap:.35rem;min-height:96px}
.pos-tile:hover{border-color:#166534;box-shadow:0 2px 10px rgba(22,101,52,.12)}
.pos-tile .nm{font-weight:600;font-size:.86rem;line-height:1.25}
.pos-tile .pr{margin-top:auto;display:flex;justify-content:space-between;align-items:center;font-weight:700;font-size:.95rem}
.pos-tile .cat{font-size:.66rem;text-transform:uppercase;letter-spacing:.04em;color:#166534}
.pos-tile .pass{font-size:.66rem;color:#64748b}
.pos-till{position:sticky;top:12px}
.pos-lines{width:100%;border-collapse:collapse}
.pos-lines td{padding:.32rem 0;border-bottom:1px solid #f1f5f9;font-size:.82rem;vertical-align:middle}
.pos-lines td.n{text-align:right;white-space:nowrap}
.pos-qty{display:inline-flex;align-items:center;gap:.25rem}
.pos-qty button{width:24px;height:24px;border-radius:6px;border:1px solid var(--c-border);background:#fff;cursor:pointer;line-height:1}
.pos-total{display:flex;justify-content:space-between;font-size:.85rem;padding:.15rem 0}
.pos-total.grand{font-size:1.15rem;font-weight:800;border-top:2px solid var(--c-border);margin-top:.3rem;padding-top:.4rem}
.pos-tenderz{display:flex;flex-wrap:wrap;gap:.35rem;margin:.35rem 0}
.pos-tender{border:1px solid var(--c-border);background:#fff;border-radius:9px;padding:.34rem .6rem;font-size:.78rem;cursor:pointer;font-weight:600}
.pos-tender.on{background:#166534;border-color:#166534;color:#fff}
.pos-cash{display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.3rem}
.pos-cash button{border:1px dashed var(--c-border);background:#fff;border-radius:8px;padding:.25rem .55rem;font-size:.75rem;cursor:pointer}
.pos-pay-row{display:grid;grid-template-columns:1fr 96px 84px 26px;gap:.3rem;margin-top:.3rem;align-items:center}
.pos-change{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:.4rem .6rem;font-weight:700;display:flex;justify-content:space-between;margin-top:.4rem}
.pos-key{font-size:.72rem;color:#64748b;margin-top:.4rem}
</style>

{{-- ── the shift strip: whose drawer is taking this money ── --}}
<div class="pos-strip" id="posShift" data-shift-required="{{ $shiftRequired ? '1' : '0' }}" data-shift-open="{{ $shift ? '1' : '0' }}">
  @if($shift)
    <div class="who"><span class="dot" style="background:#16a34a"></span><b>{{ $shift->user?->name ?? auth()->user()->name }}</b> on the {{ $shift->shift }} shift · open {{ $shift->opened_at->format('H:i') }}</div>
    <div class="who muted">Drawer expected <b>{{ money($shiftSummary['expected_cash'] ?? 0) }}</b> · takings on this shift <b>{{ money($shiftSummary['total'] ?? 0) }}</b></div>
    <a class="btn btn-sm" href="{{ route('recreation.shift') }}">X report / close</a>
  @else
    <div class="who"><span class="dot" style="background:#f59e0b"></span><b>No outlet shift open</b> — start one so the takings and the drawer are tied to you.</div>
    <div class="who muted">{{ $shiftRequired ? 'Sales are blocked until a shift is open.' : 'You can still sell; the sale will not be tied to a shift.' }}</div>
    <a class="btn btn-sm btn-primary" href="{{ route('recreation.shift') }}">Open a shift</a>
  @endif
</div>

<div class="stats">
  <x-stat label="Sales today" :value="$stats['sales']" icon="receipt" tone="blue"/>
  <x-stat label="Takings today" :value="money($stats['total'])" icon="money" tone="green" sub="incl. tax"/>
  <x-stat label="Tickets issued today" :value="$stats['tickets']" icon="users" tone="purple" sub="walk-in entries"/>
  <x-stat label="Items sold today" :value="$stats['items']" icon="trend"/>
</div>

<div class="pos-grid">
  {{-- ── catalog ── --}}
  <div>
    <div class="pos-tools">
      <input id="posSearch" class="input input-sm" style="max-width:280px" placeholder="Search tickets, rentals, services…" autocomplete="off">
      <div class="pos-chips" id="posChips">
        <button type="button" class="pos-chip on" data-cat="">All</button>
        @foreach($types as $cat => $list)
          <button type="button" class="pos-chip" data-cat="{{ $cat }}">{{ \App\Models\ClubTicketType::CATEGORIES[$cat] ?? $cat }} <span class="tiny">({{ $list->count() }})</span></button>
        @endforeach
      </div>
    </div>

    @foreach($types as $cat => $list)
      <section class="pos-cat" data-cat="{{ $cat }}">
        <h3 class="mt-2 mb-1">{{ \App\Models\ClubTicketType::CATEGORIES[$cat] ?? $cat }}</h3>
        <div class="pos-tiles">
          @foreach($list as $t)
            <button type="button" class="pos-tile" data-add="{{ $t->id }}" data-name="{{ $t->name }}"
              data-price="{{ $t->price }}" data-tax="{{ $t->tax_rate }}" data-cat="{{ $t->category }}" data-search="{{ strtolower($t->name.' '.$t->code) }}">
              <span class="nm">{{ $t->name }}</span>
              <span class="pr"><span>{{ money($t->price, false) }}</span>@if($t->issues_pass)<span class="pass">ticket</span>@endif</span>
            </button>
          @endforeach
        </div>
      </section>
    @endforeach
    <p class="muted small" id="posEmpty" hidden>No item matches that search.</p>
  </div>

  {{-- ── till ── --}}
  <div class="card pos-till" id="till">
    <div class="card-head"><h3>Till</h3><button type="button" class="btn btn-sm btn-ghost" id="clearCart">Clear</button></div>
    <div class="card-body">
      <div class="form-grid">
        <x-field name="guest_name" label="Guest name" class="span-2" id="guest_name" placeholder="Walk-in name"/>
        <x-field name="phone" label="Phone" id="phone"/>
      </div>

      <table class="pos-lines mt-2" id="cartTable">
        <tbody></tbody>
      </table>

      <div class="field mt-2"><label>Settlement</label>
        <div class="pos-tenderz">
          <button type="button" class="pos-tender on" data-settle="pay">Take payment</button>
          <button type="button" class="pos-tender" data-settle="room">Charge to room</button>
        </div>
      </div>
      <div class="field" id="roomField" hidden><label for="room_no">Room no.</label><input name="room_no" id="room_no" placeholder="101" class="input" inputmode="numeric"></div>

      <div id="payBlock">
        <div class="flex gap-2" style="justify-content:space-between;align-items:center">
          <label class="small">Payments</label>
          <span class="tiny muted">split tender: add a second row</span>
        </div>
        <div id="payRows" class="mt-1"></div>
        <div class="pos-cash" id="quickCash"></div>
      </div>

      <div class="mt-2">
        <div class="pos-total"><span>Subtotal (net)</span><b id="sumSub">GH₵ 0.00</b></div>
        <div class="pos-total"><span class="muted" id="taxLabel">Tax</span><span id="sumTax">GH₵ 0.00</span></div>
        <div class="pos-total grand"><span>Total</span><b id="sumTotal">GH₵ 0.00</b></div>
        <div class="pos-change" id="changeRow" hidden><span>Change due</span><b id="sumChange">GH₵ 0.00</b></div>
      </div>
      <p class="tiny text-red mt-1" id="tillMsg" hidden></p>
      <button type="button" class="btn btn-primary mt-2" id="completeSale" style="width:100%;height:46px;font-size:16px">Complete sale</button>
      <p class="pos-key">
        Prices are {{ \App\Services\TaxService::pricesInclusive() ? 'tax-inclusive — VAT, NHIL, GETFund and the Tourism Levy are extracted from the price' : 'exclusive of VAT/NHIL/GETFund/Tourism Levy ('.rtrim(rtrim(number_format($rate, 2), '0'), '.').'%)' }}.
        Members keep using their membership at the check-in desk.
      </p>
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
  const inclusive = {{ \App\Services\TaxService::pricesInclusive() ? 'true' : 'false' }};
  const defaultRate = {{ $rate }};
  const cart = [];
  let paySeq = 0;
  const modes = @json($modesJson);
  const $ = s => document.querySelector(s);
  const emptyCopy = '<tr><td class="muted" style="padding:18px;text-align:center">Tap items on the left to add them.</td></tr>';
  const token = document.querySelector('#tillForm [name=_token]')?.value || '';

  // ── pricing: the same rule the server applies (exclusive adds, inclusive extracts) ──
  const rateOf = l => (l.tax === null || l.tax === undefined || l.tax === '') ? defaultRate : parseFloat(l.tax);
  function split(l) {
    const entered = l.price * l.qty, rate = rateOf(l);
    if (!inclusive) return { net: entered, tax: Math.round(entered * rate) / 100 };
    const net = Math.round((entered / (1 + rate / 100)) * 100) / 100;
    return { net, tax: Math.round((entered - net) * 100) / 100 };
  }
  const settleMode = () => ($('#roomField').hidden ? 'pay' : 'room');

  function render() {
    const t = $('#cartTable tbody');
    t.innerHTML = cart.length ? '' : emptyCopy;
    cart.forEach((l, i) => {
      const s = split(l), tr = document.createElement('tr');
      tr.innerHTML = '<td><b>' + l.name + '</b><div class="tiny muted">' + money(l.price) + ' each</div></td>' +
        '<td class="n"><span class="pos-qty"><button type="button" data-dec="' + i + '">−</button><b>' + l.qty + '</b><button type="button" data-inc="' + i + '">+</button></span></td>' +
        '<td class="n"><b>' + money(s.net + s.tax) + '</b></td>' +
        '<td class="n"><button type="button" class="btn btn-sm btn-ghost" data-del="' + i + '" aria-label="Remove">✕</button></td>';
      t.appendChild(tr);
    });
    totals();
  }

  function paidTotal() {
    return Array.from(document.querySelectorAll('.pay-amount')).reduce((sum, el) => sum + parseFloat(el.value || 0), 0);
  }

  function totals() {
    let net = 0, tax = 0;
    cart.forEach(l => { const s = split(l); net += s.net; tax += s.tax; });
    const total = net + tax;
    $('#sumSub').textContent = money(net);
    $('#sumTax').textContent = money(tax);
    $('#taxLabel').textContent = 'Tax' + (defaultRate ? ' (' + defaultRate + '%)' : '') + (inclusive ? ' — included' : '');
    $('#sumTotal').textContent = money(total);
    const paid = paidTotal();
    const change = Math.max(0, paid - total);
    $('#changeRow').hidden = !(settleMode() === 'pay' && paid > 0.004);
    $('#sumChange').textContent = money(change);
    const first = document.querySelector('.pay-amount');
    if (first && first.dataset.auto === '1' && settleMode() === 'pay' && cart.length) first.value = total.toFixed(2);
    renderQuickCash(total);
  }

  // ── catalogue: search + category chips ──
  const filter = () => {
    const q = ($('#posSearch').value || '').trim().toLowerCase();
    const chip = document.querySelector('.pos-chip.on');
    const cat = chip ? chip.dataset.cat : '';
    let shown = 0;
    document.querySelectorAll('.pos-tile').forEach(tile => {
      const matchQ = !q || tile.dataset.search.includes(q) || tile.dataset.name.toLowerCase().includes(q);
      const matchC = !cat || tile.dataset.cat === cat;
      const visible = matchQ && matchC;
      tile.hidden = !visible;
      if (visible) shown++;
    });
    document.querySelectorAll('.pos-cat').forEach(section => {
      section.hidden = !Array.from(section.querySelectorAll('.pos-tile')).some(tile => !tile.hidden);
    });
    $('#posEmpty').hidden = shown > 0;
  };
  $('#posSearch').addEventListener('input', filter);
  document.querySelectorAll('.pos-chip').forEach(chip => chip.addEventListener('click', () => {
    document.querySelectorAll('.pos-chip').forEach(c => c.classList.remove('on'));
    chip.classList.add('on');
    filter();
  }));

  document.querySelectorAll('[data-add]').forEach(btn => btn.addEventListener('click', () => {
    const id = +btn.dataset.add, found = cart.find(l => l.id === id);
    if (found) found.qty++;
    else cart.push({ id, name: btn.dataset.name, price: parseFloat(btn.dataset.price), tax: btn.dataset.tax, qty: 1 });
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

  document.querySelectorAll('[data-settle]').forEach(btn => btn.addEventListener('click', () => {
    document.querySelectorAll('[data-settle]').forEach(b => b.classList.remove('on'));
    btn.classList.add('on');
    const toRoom = btn.dataset.settle === 'room';
    $('#roomField').hidden = !toRoom;
    $('#payBlock').hidden = toRoom;
    totals();
  }));

  // ── tenders ──
  function addPayRow(amount) {
    const id = ++paySeq, wrap = document.createElement('div');
    wrap.className = 'pos-pay-row';
    wrap.innerHTML = '<select class="pay-mode" aria-label="Payment method">' +
      modes.map(m => '<option value="' + m.id + '" data-type="' + m.type + '">' + m.name + '</option>').join('') + '</select>' +
      '<input class="pay-amount" type="number" step="0.01" min="0" placeholder="0.00" value="' + (amount || '') + '" data-auto="1" aria-label="Amount">' +
      '<input class="pay-ref" placeholder="Ref" aria-label="Reference" maxlength="80">' +
      '<button type="button" class="btn btn-sm btn-ghost" data-paydel="1" aria-label="Remove tender">✕</button>';
    wrap.querySelector('[data-paydel]').addEventListener('click', () => { wrap.remove(); totals(); });
    wrap.querySelector('.pay-amount').addEventListener('input', e => { e.target.dataset.auto = '0'; totals(); });
    $('#payRows').appendChild(wrap);
    totals();
  }
  function renderQuickCash(total) {
    const box = $('#quickCash');
    if (!total) { box.innerHTML = ''; return; }
    const round = v => Math.ceil(total / v) * v;
    const options = [['Exact', total]];
    [10, 20, 50, 100].forEach(step => { const v = round(step); if (v > total && v <= total + 200) options.push(['+' + step, v]); });
    box.innerHTML = options.map(([label, value]) =>
      '<button type="button" data-cash="' + value.toFixed(2) + '">' + label + ' · ' + money(value) + '</button>').join('');
  }
  $('#quickCash').addEventListener('click', e => {
    const b = e.target.closest('button[data-cash]'); if (!b) return;
    const first = document.querySelector('.pay-amount') || (addPayRow(''), document.querySelector('.pay-amount'));
    first.value = b.dataset.cash; first.dataset.auto = '0';
    totals();
  });
  addPayRow('');

  function msg(text) { const m = $('#tillMsg'); m.hidden = !text; m.textContent = text || ''; }

  // When the outlet requires a shift, the till cannot take money until one is open — the
  // server refuses it too, this just stops the cashier ringing a sale that will bounce.
  const strip = $('#posShift');
  const shiftGated = strip?.dataset.shiftRequired === '1' && strip.dataset.shiftOpen !== '1';
  if (shiftGated) {
    $('#completeSale').disabled = true;
    $('#completeSale').textContent = 'Open a shift to take money';
    msg('Open your outlet shift before taking money at the counter.');
  }

  $('#completeSale').addEventListener('click', async () => {
    msg('');
    if (shiftGated) return msg('Open your outlet shift before taking money at the counter.');
    if (!cart.length) return msg('Add at least one item to the till.');
    const settle = settleMode();
    const payments = Array.from(document.querySelectorAll('#payRows > div')).map(row => ({
      payment_mode_id: +row.querySelector('.pay-mode').value,
      amount: parseFloat(row.querySelector('.pay-amount').value || 0),
      reference: row.querySelector('.pay-ref').value,
    })).filter(p => p.amount > 0);
    if (settle === 'pay' && !payments.length) return msg('Take payment or choose a payment method.');
    if (settle === 'room' && !($('#room_no').value || '').trim()) return msg('Enter the room number to charge.');
    const body = {
      _token: token, guest_name: $('#guest_name').value, phone: $('#phone').value,
      settle, room_no: $('#room_no').value,
      items: cart.map(l => ({ type_id: l.id, qty: l.qty })),
      payments: settle === 'pay' ? payments : [],
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
    $('#doneModal').classList.add('show');
    cart.length = 0; render();
  });

  $('#doneNew').addEventListener('click', () => location.reload());

  render();
})();
</script>
@endpush
