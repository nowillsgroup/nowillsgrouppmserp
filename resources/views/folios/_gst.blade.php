{{-- v4.3 GRA VAT document panel: buyer snapshot, E-VAT stamp (SDC) status and actions, credit notes / original invoice --}}
@php
  $isCn = $folio->isCreditNote();
  $einv = \App\Services\EInvoiceService::enabled();
  $gt = $folio->gstTotals();
  $notes = $isCn ? collect() : $folio->creditNotes()->orderBy('id')->get();
@endphp
<div class="card" id="gstPanel"><div class="card-head"><h3><x-icon name="qr" size="sm"/> {{ $isCn ? 'VAT credit note' : 'VAT invoice' }}</h3>{!! $folio->irnBadge() !!}</div><div class="card-body">
  <dl class="kv">
    <dt>{{ $isCn ? 'Note no.' : 'Invoice no.' }}</dt><dd class="bold">{{ $folio->invoice_no }}</dd>
    <dt>Dated</dt><dd>{{ $folio->invoice_date?->format('d M Y') ?? '—' }}</dd>
    @if($isCn && $folio->original)<dt>Against</dt><dd><a href="{{ route('folios.show', $folio->original) }}">{{ $folio->original->invoice_no }}</a> <span class="tiny muted">{{ $folio->original->invoice_date?->format('d M Y') }}</span></dd>
      <dt>Reason</dt><dd>{{ \App\Models\Folio::NOTE_REASONS[$folio->note_reason] ?? $folio->note_reason }}@if($folio->note_remarks)<div class="tiny muted">{{ $folio->note_remarks }}</div>@endif</dd>@endif
    <dt>Supply</dt><dd>{!! $folio->supply_type === 'B2B' ? '<span class="badge badge-blue">B2B</span>' : '<span class="badge badge-slate">B2C</span>' !!}</dd>
    <dt>Buyer</dt><dd>{{ $folio->buyer_name ?? $folio->guest?->full_name }}@if($folio->buyer_gstin)<div class="mono tiny">TIN {{ $folio->buyer_gstin }}</div>@endif @if($folio->buyer_address)<div class="tiny muted">{{ $folio->buyer_address }}</div>@endif</dd>
    <dt>Taxable</dt><dd>{{ money(abs($gt['taxable'])) }} <span class="tiny muted">+ taxes {{ money(abs($gt['tax'])) }}</span></dd>
  </dl>
  @if($folio->hasActiveIrn())
    <div class="mt-1" id="irnBox" style="display:flex;gap:.75rem;align-items:flex-start">
      @if($folio->irn_qr)<div style="width:92px;flex:none" title="E-VAT QR code">{!! \App\Services\EInvoiceService::qrSvg($folio->irn_qr) !!}</div>@endif
      <div class="tiny" style="min-width:0"><div class="muted">SDC ID · receipt no.</div><div class="mono" style="word-break:break-all">{{ $folio->irn }} · {{ $folio->irn_ack_no }}</div>
        <div class="muted mt-1">Stamped</div><div>{{ $folio->irn_ack_at?->format('d M Y H:i:s') }}</div>
        @if($folio->evat_signature)<div class="muted mt-1">Internal data · signature</div><div class="mono" style="word-break:break-all">{{ $folio->evat_intdata }} · {{ $folio->evat_signature }}</div>@endif
        @if(\App\Services\EInvoiceService::driver() === 'sandbox')<div class="muted mt-1">sandbox – not reported to GRA</div>@endif</div>
    </div>
  @elseif($folio->irn_status === 'failed')
    <div class="alert alert-error mt-1" id="irnError"><x-icon name="alert"/><div class="small">{{ $folio->irn_error }}
      @if($folio->irn_retryable && $folio->irn_next_try_at)<div class="tiny">Automatic retry {{ $folio->irn_next_try_at->diffForHumans() }} (attempt {{ $folio->irn_attempts }}).</div>@elseif(! $folio->irn_retryable)<div class="tiny">Correct the data, then refresh the buyer details or retry.</div>@endif</div></div>
  @elseif($einv && $folio->status === 'closed')
    <p class="small muted mt-1">Not stamped by E-VAT yet.</p>
  @endif
  @if($notes->count())
    <div class="mt-1"><div class="tiny muted">Credit notes</div>
    @foreach($notes as $n)<div class="small"><a href="{{ route('folios.show', $n) }}">{{ $n->invoice_no }}</a> · {{ $n->invoice_date?->format('d M') }} · {{ money(abs($n->gstTotals()['value'])) }} {!! $n->status === 'cancelled' ? '<span class="badge badge-slate">cancelled</span>' : $n->irnBadge() !!}</div>@endforeach</div>
  @endif
  @if($folio->status === 'closed' && ! $isCn && ($why = \App\Services\GstService::reopenBlocker($folio)))<p class="tiny muted mt-1" id="reopenWhy"><x-icon name="lock" size="sm"/> {{ $why }}</p>@endif
</div>
@if($folio->status === 'closed')
<div class="card-foot flex gap-1" style="flex-wrap:wrap">
  @if(can_do('gst.manage') && $einv && ! $folio->hasActiveIrn())<form method="post" action="{{ route('gst.generate', $folio) }}">@csrf<button class="btn btn-sm btn-primary" id="genIrn"><x-icon name="qr" size="sm"/> {{ $folio->irn_status === 'failed' ? 'Retry E-VAT' : 'Submit to E-VAT' }}</button></form>@endif
  @if(can_do('gst.manage') && ! $folio->hasActiveIrn() && ! $isCn)<form method="post" action="{{ route('gst.buyer', $folio) }}">@csrf<button class="btn btn-sm" title="Re-read the TIN / address from the company or guest profile"><x-icon name="refresh" size="sm"/> Refresh buyer</button></form>@endif
  @if(can_do('gst.view'))<a class="btn btn-sm btn-ghost" href="{{ route('gst.payload', $folio) }}" target="_blank" title="GRA E-VAT request JSON">JSON</a>@endif
</div>
@endif
</div>
