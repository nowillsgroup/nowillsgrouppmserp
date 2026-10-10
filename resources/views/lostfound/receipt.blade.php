@extends('layouts.print')
@section('title', 'Return receipt '.$i->item_no)
@section('doctitle', 'Lost Property Return Receipt')
@section('docmeta')<div class="small"><b>{{ $i->item_no }}</b></div><div class="small">{{ $i->returned_date?->format('d M Y') }}</div>@endsection
@section('content')
<table class="table" style="margin-top:1rem" id="receiptTable">
  <tr><th style="width:30%">Item</th><td><b>{{ $i->item_name }}</b> ({{ $i->categoryLabel() }})</td></tr>
  <tr><th>Description</th><td>{{ $i->description ?: '—' }}</td></tr>
  <tr><th>Found</th><td>{{ $i->found_date->format('d M Y') }} · {{ $i->location ?? '—' }}</td></tr>
  <tr><th>Returned to</th><td>{{ $i->returned_to }}{{ $i->claimant_phone ? ' · '.$i->claimant_phone : '' }}</td></tr>
  @if($i->claimant_id_type)<tr><th>Identity checked</th><td>{{ \App\Services\LostFoundService::ID_TYPES[$i->claimant_id_type] ?? $i->claimant_id_type }} · {{ \App\Models\Guest::mask($i->claimant_id_no) }}</td></tr>@endif
  <tr><th>Method</th><td>{{ \App\Models\LostFoundItem::RETURN_METHODS[$i->return_method] ?? '—' }}{{ $i->courier ? ' · '.$i->courier.' '.$i->tracking_no : '' }}</td></tr>
  @if((float) $i->courier_charge > 0)<tr><th>Courier charge</th><td>{{ money($i->courier_charge) }}</td></tr>@endif
  <tr><th>Released by</th><td>{{ $i->releaser?->name ?? '—' }}</td></tr>
</table>
<p style="margin-top:1.5rem">I confirm that I have received the item described above in good order and that it belongs to me (or I am authorised to collect it).</p>
<table style="width:100%;margin-top:2.5rem"><tr><td style="width:50%">Signature of claimant: ______________________</td><td>For the hotel: ______________________</td></tr></table>
@endsection
