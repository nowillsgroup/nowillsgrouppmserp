{{-- v4.8 quotation lines + terms – shared by the buyer's "enter quote" page and the supplier portal --}}
@php $ql = $quote ? $quote->lines->keyBy('rfq_line_id') : collect(); @endphp
<div class="table-wrap"><table class="table table-compact" id="quoteLines"><thead><tr><th>#</th><th>Item</th><th class="num">Qty requested</th><th style="width:130px">Unit price (GH₵)</th><th style="width:90px">Tax %</th><th style="width:110px">Qty you can supply</th><th>Brand / remarks</th><th class="num">Amount</th></tr></thead><tbody>
@foreach($rfq->lines as $l)@php $x = $ql[$l->id] ?? null; @endphp
<tr data-qline data-qty="{{ (float) $l->qty }}"><td>{{ $loop->iteration }}</td><td class="bold">{{ $l->item->name }}@if($l->specification)<div class="tiny muted">{{ $l->specification }}</div>@endif</td><td class="num">{{ (float) $l->qty }} {{ $l->item->unit }}</td>
  <td><input class="input input-sm" type="number" step="0.01" min="0" name="lines[{{ $l->id }}][rate]" value="{{ old('lines.'.$l->id.'.rate', $x?->rate) }}" placeholder="no bid" data-q="rate" @disabled(! $editable)></td>
  <td><input class="input input-sm" type="number" step="0.01" min="0" max="50" name="lines[{{ $l->id }}][tax_rate]" value="{{ old('lines.'.$l->id.'.tax_rate', $x?->tax_rate ?? ($l->item->tax_rate ?? 0)) }}" data-q="tax" @disabled(! $editable)></td>
  <td><input class="input input-sm" type="number" step="0.01" min="0" max="{{ (float) $l->qty }}" name="lines[{{ $l->id }}][qty_offered]" value="{{ old('lines.'.$l->id.'.qty_offered', $x?->qty_offered !== null ? (float) $x->qty_offered : '') }}" placeholder="all" data-q="qty" @disabled(! $editable)></td>
  <td><input class="input input-sm" name="lines[{{ $l->id }}][remarks]" maxlength="255" value="{{ old('lines.'.$l->id.'.remarks', $x?->remarks) }}" @disabled(! $editable)></td>
  <td class="num" data-q="amt">—</td></tr>
@endforeach
</tbody><tfoot><tr class="bold"><td colspan="7" class="right">Total incl. tax</td><td class="num" id="quoteTotal">0.00</td></tr></tfoot></table></div>
<div class="form-grid mt-1">
  <x-field name="quote_ref" label="Your quotation reference" :value="$quote?->quote_ref"/>
  <x-field name="valid_until" label="Prices valid until" type="date" :value="$quote?->valid_until?->toDateString() ?? bdate()->copy()->addDays(30)->toDateString()"/>
  <x-field name="delivery_days" label="Delivery lead time (days)" type="number" min="0" :value="$quote?->delivery_days"/>
  <x-field name="payment_terms_days" label="Payment terms (days credit)" type="number" min="0" :value="$quote?->payment_terms_days ?? 30"/>
  <x-field name="notes" label="Notes / conditions" type="textarea" :value="$quote?->notes" class="span-all"/>
</div>
<script>
(function(){ const rows=[...document.querySelectorAll('[data-qline]')];
  function calc(){ let t=0; rows.forEach(r=>{ const rate=+r.querySelector('[data-q=rate]').value||0, tax=+r.querySelector('[data-q=tax]').value||0, q=r.querySelector('[data-q=qty]').value; const qty=q===''?+r.dataset.qty:+q; const a=rate?qty*rate*(1+tax/100):0; t+=a; r.querySelector('[data-q=amt]').textContent=rate?a.toFixed(2):'—'; }); document.getElementById('quoteTotal').textContent=t.toFixed(2); }
  rows.forEach(r=>r.querySelectorAll('input').forEach(i=>i.addEventListener('input',calc))); calc(); })();
</script>
