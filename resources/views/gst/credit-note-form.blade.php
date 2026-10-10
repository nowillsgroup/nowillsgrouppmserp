@extends('layouts.app')
@section('title', 'Issue credit note – '.$inv->invoice_no)
@section('crumbs') Billing / <a href="{{ route('folios.show', $inv) }}">{{ $inv->invoice_no }}</a> / Credit note @endsection
@section('content')
@if($filed)<div class="alert alert-error"><x-icon name="alert"/><div>The VAT return for {{ bdate()->format('F Y') }} is already marked as filed – credit notes cannot be dated in this month.</div></div>@endif
<form method="post" action="{{ route('gst.credit_note.store', $inv) }}" id="cnForm">@csrf
<div class="grid g-2-1">
  <div class="card flush"><div class="card-head"><h3><x-icon name="swap"/> Lines to credit</h3><span class="tiny muted">amounts before tax · VAT, NHIL, GETFund and tourism levy are reversed at the rates charged</span></div>
    <div class="card-body table-wrap"><table class="table" id="cnLines">
      <thead><tr><th>Date</th><th>Charge</th><th>Category</th><th class="num">Rate</th><th class="num">Charged</th><th class="num">Credited</th><th class="num" style="width:9rem">Credit now</th><th class="num">Tax</th></tr></thead><tbody>
      @forelse($lines as $l)
      <tr data-rate="{{ (float) $l->tax_rate }}" data-max="{{ $l->creditable }}" data-full="{{ (float) $l->amount }}" data-fulltax="{{ (float) $l->tax_amount }}" data-credited="{{ $l->credited }}">
        <td class="small nowrap">{{ $l->business_date->format('d M') }}</td><td class="small">{{ $l->description }}</td><td class="small">{{ $l->cat_label }}</td><td class="num small">{{ (float) $l->tax_rate }}%</td>
        <td class="num">{{ money($l->amount, false) }}</td><td class="num muted">{{ $l->credited ? money($l->credited, false) : '—' }}</td>
        <td class="num">@if($l->creditable > 0)<input type="number" step="0.01" min="0" max="{{ $l->creditable }}" name="amounts[{{ $l->id }}]" value="{{ old('amounts.'.$l->id) }}" class="cn-amt" style="width:8rem;text-align:right" placeholder="0.00">
          <a href="#" class="tiny cn-full" title="Credit the whole remaining amount">all</a>@else<span class="badge badge-slate">fully credited</span>@endif</td>
        <td class="num cn-tax muted">—</td></tr>
      @empty<tr><td colspan="8"><x-empty text="This invoice has no charges." icon="receipt"/></td></tr>@endforelse
      </tbody><tfoot><tr><td colspan="6" class="right">Credit note total</td><td class="num bold" id="cnTaxable">0.00</td><td class="num bold" id="cnTax">0.00</td></tr>
      <tr><td colspan="6" class="right bold">Document value (incl. taxes)</td><td colspan="2" class="num bold big" id="cnTotal">0.00</td></tr></tfoot></table>
      @error('amounts')<div class="err" style="padding:0 1rem 1rem">{{ $message }}</div>@enderror @error('credit')<div class="err" style="padding:0 1rem 1rem">{{ $message }}</div>@enderror</div></div>
  <div>
    <div class="card"><div class="card-head"><h3><x-icon name="receipt"/> Invoice {{ $inv->invoice_no }}</h3></div><div class="card-body">
      <ul class="bq-list">
        <li><span>Dated</span><b>{{ $inv->invoice_date?->format('d M Y') }}</b></li>
        <li><span>Buyer</span><b>{{ $inv->buyer_name ?? $inv->guest?->full_name }}</b></li>
        <li><span>Supply</span><b>{{ $inv->supply_type ?? 'B2C' }} @if($inv->buyer_gstin)<span class="mono tiny">{{ $inv->buyer_gstin }}</span>@endif</b></li>
        <li><span>E-VAT</span><b>{!! $inv->irnBadge() ?: '—' !!}</b></li>
      </ul>
      @if($notes->count())<div class="tiny muted mt-1">Earlier credit notes: @foreach($notes as $n)<a href="{{ route('folios.show', $n) }}">{{ $n->invoice_no }}</a>{{ $n->status === 'cancelled' ? ' (cancelled)' : '' }}@if(! $loop->last), @endif @endforeach</div>@endif
    </div></div>
    <div class="card mt-1"><div class="card-head"><h3><x-icon name="edit"/> Details</h3></div><div class="card-body">
      <x-field name="note_reason" label="Reason" type="select" :options="\App\Models\Folio::NOTE_REASONS" required/>
      <x-field name="note_remarks" label="Remarks (printed on the credit note)" type="textarea" placeholder="e.g. Corporate contract rate applied – 4 nights"/>
      <div class="field"><label>Settle the credit</label>
        @if($cl)<label class="check"><input type="radio" name="settle" value="city_ledger" @checked(old('settle', 'city_ledger') === 'city_ledger')> Reduce {{ $cl->company?->name }}'s account (city ledger)</label>@endif
        <label class="check"><input type="radio" name="settle" value="refund" @checked(old('settle', $cl ? 'city_ledger' : 'refund') === 'refund')> Refund the guest</label>
        @error('settle')<span class="err">{{ $message }}</span>@enderror</div>
      <x-field name="payment_mode_id" label="Refund paid by" type="select" :options="$modes" hint="Only for refunds"/>
    </div>
    <div class="card-foot"><a class="btn btn-ghost" href="{{ route('folios.show', $inv) }}">Back</a> <button class="btn btn-primary" id="issueCn" @disabled($filed)><x-icon name="check"/> Issue credit note</button></div></div>
  </div>
</div>
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const fmt = n => n.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  function recalc() {
    let base = 0, tax = 0;
    document.querySelectorAll('#cnLines tbody tr[data-rate]').forEach(tr => {
      const inp = tr.querySelector('.cn-amt'); if (!inp) return;
      const a = Math.min(parseFloat(inp.value) || 0, parseFloat(tr.dataset.max));
      const full = parseFloat(tr.dataset.credited) === 0 && Math.abs(a - parseFloat(tr.dataset.full)) < 0.001;
      const t = a > 0 ? (full ? parseFloat(tr.dataset.fulltax) : Math.round(a * parseFloat(tr.dataset.rate)) / 100) : 0;
      tr.querySelector('.cn-tax').textContent = a > 0 ? fmt(t) : '—';
      base += a; tax += t;
    });
    document.getElementById('cnTaxable').textContent = fmt(base);
    document.getElementById('cnTax').textContent = fmt(tax);
    document.getElementById('cnTotal').textContent = fmt(base + tax);
  }
  document.querySelectorAll('.cn-amt').forEach(i => i.addEventListener('input', recalc));
  document.querySelectorAll('.cn-full').forEach(a => a.addEventListener('click', e => { e.preventDefault(); const tr = a.closest('tr'); tr.querySelector('.cn-amt').value = tr.dataset.max; recalc(); }));
  recalc();
});
</script>
@endsection
