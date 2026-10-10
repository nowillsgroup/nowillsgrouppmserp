<div class="modal" id="gstCancelModal"><div class="modal-box"><form method="post" action="{{ route('gst.cancel', $folio) }}">@csrf
  <div class="modal-head"><h3>Cancel {{ $folio->isCreditNote() ? 'credit note' : 'invoice' }} {{ $folio->invoice_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small">@if($folio->isCreditNote())The credit note's lines and settlement are voided.@else The folio re-opens with its charges and payments; when it is settled again it gets a <b>new</b> invoice number.@endif
      The number {{ $folio->invoice_no }} is kept in the cancelled-documents register and never reused. Only documents not yet stamped by GRA E-VAT can be cancelled.</p>
    <x-field name="reason_code" label="Reason" type="select" :options="\App\Models\GstCancelledDoc::REASONS" required/>
    <x-field name="remarks" label="Remarks" required placeholder="e.g. Billed to the wrong company"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger" id="confirmGstCancel">Cancel document</button></div>
</form></div></div>
