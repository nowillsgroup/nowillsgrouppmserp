@extends('layouts.app')
@section('title', $mode === 'debit_note' ? 'New Debit Note' : 'Enter Supplier Bill')
@section('crumbs') <a href="{{ route('ap.bills') }}">Supplier Bills</a> / New @endsection
@section('content')
@php
  $today = bdate()->toDateString();
  $minDate = \App\Services\LedgerService::lockDate() ? \Illuminate\Support\Carbon::parse(\App\Services\LedgerService::lockDate())->addDay()->toDateString() : null;
  $tab = fn ($m) => route('ap.bills.create', array_filter(['mode' => $m, 'supplier' => $m !== 'goods' ? $supplier?->id : null]));
@endphp
<div class="tabs" id="billModes">
  <a href="{{ $tab('goods') }}" class="{{ $mode === 'goods' ? 'active' : '' }}">Goods – match receipt ({{ $receipts->count() }})</a>
  <a href="{{ $tab('expense') }}" class="{{ $mode === 'expense' ? 'active' : '' }}">Expense / service bill</a>
  <a href="{{ $tab('debit_note') }}" class="{{ $mode === 'debit_note' ? 'active' : '' }}">Debit note</a>
</div>

<form method="post" action="{{ route('ap.bills.store') }}" id="billForm">@csrf
<input type="hidden" name="mode" value="{{ $mode }}">

@if($mode === 'goods')
  @if(! $po)
  <div class="card"><div class="card-head"><h3>1 · Choose the goods receipt</h3></div><div class="card-body">
    @if($receipts->isEmpty())
      <p class="muted">Every received purchase order already has a bill. Receive goods on a <a href="{{ route('purchases.index') }}">purchase order</a> first, or enter an expense bill.</p>
    @else
    <table class="table table-compact"><thead><tr><th>PO</th><th>Supplier</th><th>Received</th><th>Supplier inv.</th><th class="num">GRN value</th><th></th></tr></thead><tbody>
    @foreach($receipts as $r)<tr><td class="bold">{{ $r->po_no }}</td><td>{{ $r->supplier->name }}</td><td>{{ dmy($r->grnDate()) }}</td><td>{{ $r->invoice_no ?? '—' }}</td><td class="num">{{ money($r->receiptValue ?? $r->total) }}@if($r->status === 'closed')<div class="tiny muted">short-closed</div>@endif</td><td class="right"><a class="btn btn-sm btn-primary" href="{{ route('ap.bills.create', ['po' => $r->id]) }}">Select</a></td></tr>@endforeach
    </tbody></table>
    @endif
  </div></div>
  @else
  <input type="hidden" name="purchase_order_id" value="{{ $po->id }}">
  @php [$recSub, $recTax] = $po->receivedSplit(); $recVal = $po->receivedValue() ?: (float) $po->total; $byGrn = $po->hasGrns(); @endphp
  <div class="card"><div class="card-head"><h3>Three-way match · {{ $po->po_no }} · {{ $po->supplier->name }}</h3><a class="small" href="{{ route('ap.bills.create', ['mode' => 'goods']) }}">change</a></div>
  <div class="card-body grid g-2">
    <div class="table-wrap"><table class="table table-compact"><thead><tr><th>Item received</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th></tr></thead><tbody>
      @foreach($po->items as $l)@php $q = $byGrn ? (float) $l->received_qty : (float) $l->qty; @endphp<tr><td>{{ $l->item->name }}@if($byGrn && $q < (float) $l->qty)<div class="tiny muted">ordered {{ (float) $l->qty }}</div>@endif</td><td class="num">{{ $q }} {{ $l->item->unit }}</td><td class="num">{{ money($l->rate, false) }}</td><td class="num">{{ money($byGrn ? round($q * $l->rate, 2) : $l->amount, false) }}</td></tr>@endforeach
    </tbody><tfoot><tr><td colspan="3" class="right">Subtotal</td><td class="num">{{ money($recSub, false) }}</td></tr><tr><td colspan="3" class="right">Tax</td><td class="num">{{ money($recTax, false) }}</td></tr><tr><td colspan="3" class="right bold">Goods receipt value</td><td class="num bold" id="grnTotal" data-v="{{ $recVal }}">{{ money($recVal, false) }}</td></tr></tfoot></table></div>
    <div>
      <p class="small muted" style="margin-top:0">Enter the amounts exactly as printed on the supplier's invoice. If the invoice total differs from the goods receipt by more than <b>{{ money($tolerance) }}</b> the bill is saved <b>on hold</b> and must be approved before it can be paid.</p>
      <div class="form-grid">
        <div class="field"><label>Invoice subtotal <span class="req">*</span></label><input type="number" step="0.01" min="0.01" name="subtotal" id="invSub" value="{{ old('subtotal', $recSub) }}" required></div>
        <div class="field"><label>Invoice tax</label><input type="number" step="0.01" min="0" name="tax" id="invTax" value="{{ old('tax', $recTax) }}"></div>
      </div>
      <div class="match-box" id="matchBox" data-tol="{{ $tolerance }}"><span>Invoice total <b id="invTotal">0.00</b></span><span>Variance <b id="invVar">0.00</b></span><span id="matchState" class="badge">—</span></div>
    </div>
  </div></div>
  @endif
@else
  <div class="card"><div class="card-head"><h3>Supplier</h3></div><div class="card-body form-grid">
    <div class="field span-2"><label>Supplier <span class="req">*</span></label>
      <select name="supplier_id" id="supplierSel" required onchange="location.href='{{ route('ap.bills.create', ['mode' => $mode]) }}&supplier=' + this.value">
        <option value="">— Select supplier —</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected($supplier?->id === $s->id)>{{ $s->name }}</option>@endforeach
      </select></div>
    @if($supplier)
    <div class="field"><label>Payment terms</label><div class="pad-input">{{ (int) $supplier->payment_terms_days }} days</div></div>
    <div class="field"><label>Default expense account</label><div class="pad-input">{{ $supplier->expenseHead?->label ?? '—' }}</div></div>
    @endif
    @if($mode === 'debit_note' && $supplier)
    <div class="field span-2"><label>Against bill <span class="muted small">(the debit note is applied to it automatically)</span></label>
      <select name="against_invoice_id"><option value="">— Not linked (held as supplier credit) —</option>@foreach($againstOptions as $b)<option value="{{ $b->id }}" @selected(old('against_invoice_id', request('against')) == $b->id)>{{ $b->doc_no }} · {{ $b->supplier_ref }} · {{ money($b->total) }} · {{ \App\Models\SupplierInvoice::STATUSES[$b->status] }}</option>@endforeach</select></div>
    @endif
  </div></div>
@endif

@if(($mode === 'goods' && $po) || ($mode !== 'goods' && $supplier))
<div class="card"><div class="card-head"><h3>{{ $mode === 'debit_note' ? 'Debit note details' : 'Supplier invoice details' }}</h3></div><div class="card-body form-grid">
  <div class="field"><label>{{ $mode === 'debit_note' ? 'Supplier credit note no.' : 'Supplier invoice no.' }} @if($mode !== 'debit_note')<span class="req">*</span>@endif</label><input name="supplier_ref" maxlength="60" value="{{ old('supplier_ref', $po?->invoice_no) }}" @required($mode !== 'debit_note')></div>
  <div class="field"><label>{{ $mode === 'debit_note' ? 'Date' : 'Invoice date' }} <span class="req">*</span></label><input type="date" name="doc_date" id="docDate" value="{{ old('doc_date', $po?->grnDate() && $po->grnDate() >= ($minDate ?? '0') ? $po->grnDate() : $today) }}" max="{{ $today }}" @if($minDate) min="{{ $minDate }}" @endif required data-terms="{{ (int) ($po?->supplier ?? $supplier)->payment_terms_days }}"></div>
  @if($mode !== 'debit_note')<div class="field"><label>Due date</label><input type="date" name="due_date" id="dueDate" value="{{ old('due_date') }}"><div class="tiny muted">Auto: invoice date + {{ (int) ($po?->supplier ?? $supplier)->payment_terms_days }} days</div></div>@endif
  @if($mode !== 'goods')
  <div class="field"><label>Billed in</label>
    <select name="currency" id="billCurrency" data-home="{{ $home }}">
      <option value="{{ $home }}" data-rate="" @selected(old('currency', $home) === $home)>{{ $home }} – the property's own currency</option>
      @if($fxOn)@foreach($fxBoard as $code => $b)@if($code !== $home)
      <option value="{{ $code }}" data-rate="{{ $b['rate'] }}" data-date="{{ $b['date'] }}" data-basis="{{ $b['basis'] }}" data-stale="{{ $b['stale'] ? '1' : '' }}" @selected(old('currency') === $code)>{{ $code }} · {{ $b['name'] }} – {{ number_format($b['rate'], 4) }} (board {{ \Illuminate\Support\Carbon::parse($b['date'])->format('d M Y') }})</option>
      @endif @endforeach @endif
    </select>
    <div class="tiny muted">@if($fxOn)The lines are entered in the currency the supplier billed in, and each one is converted at the board for the invoice date. @else Foreign-currency bills are switched off – turn them on under Foreign Guests › Settings › Forex desk. @endif</div>
  </div>
  @endif
  <div class="field {{ $mode === 'debit_note' ? 'span-2' : '' }}"><label>Notes</label><input name="notes" maxlength="500" value="{{ old('notes') }}" placeholder="{{ $mode === 'debit_note' ? 'e.g. 3 kg tomatoes returned – spoiled' : '' }}"></div>
  @if($mode === 'expense' && ($bctl = \App\Services\BudgetService::cfg()['control']) !== 'off')<div class="field span-2"><label>Reason if over budget @if($bctl === 'block')<span class="tiny muted">(required to exceed the budget – budget override permission)</span>@endif</label><input name="budget_reason" id="budgetReason" maxlength="255" value="{{ old('budget_reason') }}" placeholder="Only needed when the bill takes an expense account over the active budget ({{ \App\Services\BudgetService::cfg()['control_basis'] === 'ytd' ? 'year to date' : \App\Services\BudgetService::cfg()['control_basis'] }})"></div>@endif
</div></div>
@endif

@if($mode !== 'goods' && $supplier)
@php $rows = old('lines') ?: [['account_head_id' => $supplier->expense_head_id]] + array_fill(1, 2, []); @endphp
<div class="card flush"><div class="card-head"><h3>Lines <span class="badge badge-blue" id="lineCurrency">{{ old('currency', $home) }}</span></h3><button type="button" class="btn btn-sm" id="addLine"><x-icon name="plus" size="sm"/> Add line</button></div>
<div class="card-body table-wrap"><table class="table gl-lines" id="billLines">
<thead><tr><th style="width:34%">Account</th><th>Description</th><th class="num" style="width:13%">Amount</th><th class="num" style="width:9%">Tax %</th><th class="num" style="width:11%">Tax</th><th style="width:40px"></th></tr></thead><tbody>
@foreach($rows as $i => $r)
<tr><td><select class="input input-sm" name="lines[{{ $i }}][account_head_id]"><option value="">— Account —</option>@foreach($heads->groupBy('group_name') as $g => $hs)<optgroup label="{{ $g ?: 'Other' }}">@foreach($hs as $h)<option value="{{ $h->id }}" @selected(($r['account_head_id'] ?? null) == $h->id)>{{ $h->label }}</option>@endforeach</optgroup>@endforeach</select></td>
<td><input class="input input-sm" name="lines[{{ $i }}][description]" maxlength="200" value="{{ $r['description'] ?? '' }}"></td>
<td><input type="number" step="0.01" min="0" class="input input-sm num amt" name="lines[{{ $i }}][amount]" value="{{ $r['amount'] ?? '' }}"></td>
<td><input type="number" step="0.01" min="0" max="100" class="input input-sm num rate" name="lines[{{ $i }}][tax_rate]" value="{{ $r['tax_rate'] ?? '' }}"></td>
<td class="num tax">0.00</td>
<td><button type="button" class="btn btn-ghost btn-sm del" title="Remove line"><x-icon name="trash" size="sm"/></button></td></tr>
@endforeach
</tbody><tfoot><tr><td colspan="2" class="right">Totals</td><td class="num" id="tSub">0.00</td><td></td><td class="num" id="tTax">0.00</td><td></td></tr><tr><td colspan="4" class="right bold">{{ $mode === 'debit_note' ? 'Debit note total' : 'Bill total' }}</td><td class="num bold" id="tTotal">0.00</td><td></td></tr>
<tr id="fxRow" style="display:none"><td colspan="2" class="right">Booked in {{ $home }} · net</td><td class="num" id="fxSub">0.00</td><td class="right" colspan="2">at <b id="fxRate">0.0000</b></td><td class="num bold" id="fxTotal">0.00</td></tr></tfoot></table></div>
<div class="tiny muted" id="fxPanel" style="display:none;padding:.55rem .9rem .8rem"><b id="fxText"></b> <span id="fxStale" class="badge badge-amber" style="display:none">board carried forward</span>
  <div id="fxNote">The rate quoted is the board for today; the bill is booked at the board for its invoice date, within {{ (int) $fxPolicy['stale_days'] }} days, on the {{ $fxPolicy['basis'] }} side.</div></div></div>
@endif

@if(($mode === 'goods' && $po) || ($mode !== 'goods' && $supplier))
<div class="flex gap-2" style="justify-content:flex-end"><a class="btn" href="{{ route('ap.bills') }}">Cancel</a><button class="btn btn-primary" id="saveBill"><x-icon name="check"/> Save {{ $mode === 'debit_note' ? 'debit note' : 'bill' }}</button></div>
<p class="small muted">@if($mode === 'goods')A matched bill posts nothing new to the ledger – the goods receipt already credited Accounts Payable. Only a price variance is posted (to Purchase Price Variance).@elseif($mode === 'expense')Posts Dr expense accounts{{ setting('gl_input_tax', '1') === '1' ? ' + Dr Input Tax Credit' : ' (tax included in cost)' }} / Cr Accounts Payable on the invoice date.@else Posts Dr Accounts Payable / Cr the chosen accounts{{ setting('gl_input_tax', '1') === '1' ? ' and Input Tax Credit' : '' }}. Unapplied debit notes stay as supplier credit and can be applied to any bill.@endif</p>
@endif
</form>
@endsection
@push('scripts')<script>
(() => {
  const f = v => Math.round((parseFloat(v) || 0) * 100) / 100, fx = n => n.toFixed(2);
  const doc = document.getElementById('docDate'), due = document.getElementById('dueDate');
  if (doc && due) {
    const setDue = () => { if (due.dataset.touched) return; const d = new Date(doc.value + 'T00:00:00'); if (isNaN(d)) return; d.setDate(d.getDate() + (+doc.dataset.terms || 0)); due.value = d.toISOString().slice(0, 10); };
    due.addEventListener('input', () => due.dataset.touched = 1);
    doc.addEventListener('change', setDue); if (!due.value) setDue(); else due.dataset.touched = 1;
  }
  const box = document.getElementById('matchBox');
  if (box) {
    const sub = document.getElementById('invSub'), tax = document.getElementById('invTax'), grn = +document.getElementById('grnTotal').dataset.v, tol = +box.dataset.tol;
    const upd = () => {
      const t = f(f(sub.value) + f(tax.value)), v = f(t - grn), st = document.getElementById('matchState');
      document.getElementById('invTotal').textContent = fx(t); document.getElementById('invVar').textContent = (v > 0 ? '+' : '') + fx(v);
      st.textContent = v === 0 ? 'Matched' : (Math.abs(v) <= tol ? 'Within tolerance' : 'Will be held for approval');
      st.className = 'badge ' + (v === 0 ? 'badge-green' : (Math.abs(v) <= tol ? 'badge-amber' : 'badge-red'));
    };
    sub.addEventListener('input', upd); tax.addEventListener('input', upd); upd();
  }
  const body = document.querySelector('#billLines tbody');
  if (body) {
    const cur = document.getElementById('billCurrency');
    // the board rate of the currency chosen, or null for the property's own currency
    const rate = () => { if (!cur) return null; const o = cur.selectedOptions[0], r = parseFloat(o && o.dataset.rate); return cur.value !== cur.dataset.home && r > 0 ? r : null; };
    const total = () => {
      const r = rate(); let s = 0, t = 0, hs = 0, ht = 0;
      body.querySelectorAll('tr').forEach(tr => {
        const a = f(tr.querySelector('.amt').value), x = f(a * f(tr.querySelector('.rate').value) / 100);
        tr.querySelector('.tax').textContent = fx(x); s += a; t += x;
        // each line is converted and rounded on its own, exactly as ApService::convertLines() books it
        if (r) { hs += Math.round(a * r * 100) / 100; ht += Math.round(x * r * 100) / 100; }
      });
      document.getElementById('tSub').textContent = fx(s); document.getElementById('tTax').textContent = fx(t); document.getElementById('tTotal').textContent = fx(s + t);
      const row = document.getElementById('fxRow');
      if (row) {
        if (r) { row.style.display = ''; document.getElementById('fxRate').textContent = r.toFixed(4); document.getElementById('fxSub').textContent = fx(hs); document.getElementById('fxTotal').textContent = fx(hs + ht); }
        else { row.style.display = 'none'; }
      }
      document.getElementById('saveBill').disabled = s <= 0;
    };
    if (cur) {
      const paint = () => {
        const o = cur.selectedOptions[0], r = rate(), panel = document.getElementById('fxPanel');
        document.getElementById('lineCurrency').textContent = cur.value;
        if (panel) {
          if (r) {
            panel.style.display = '';
            document.getElementById('fxText').textContent = '1 ' + cur.value + ' = ' + r.toFixed(4) + ' {{ $home }} on the ' + (o.dataset.basis || 'reference') + ' board of ' + o.dataset.date + '.';
            document.getElementById('fxStale').style.display = o.dataset.stale ? '' : 'none';
          } else { panel.style.display = 'none'; }
        }
        total();
      };
      cur.addEventListener('change', paint); paint();
    }
    body.addEventListener('input', total);
    body.addEventListener('click', e => { const b = e.target.closest('.del'); if (b && body.rows.length > 1) { b.closest('tr').remove(); total(); } });
    document.getElementById('addLine').addEventListener('click', () => {
      const tr = body.rows[body.rows.length - 1].cloneNode(true), n = Date.now();
      tr.querySelectorAll('[name]').forEach(el => { el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + n + ']'); el.value = ''; });
      body.appendChild(tr); total();
    });
    total();
  }
})();
</script>@endpush
