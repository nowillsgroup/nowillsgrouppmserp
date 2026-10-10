@extends('layouts.print')
@section('title', 'Tag '.$i->item_no)
@section('doctitle', 'Lost & Found Tag')
@section('docmeta')<div class="small"><b style="font-size:1.3rem">{{ $i->item_no }}</b></div>@endsection
@section('content')
<table class="table" style="margin-top:1rem;font-size:1rem" id="tagTable">
  <tr><th style="width:30%">Item</th><td><b>{{ $i->item_name }}</b>{{ $i->valuable ? ' – VALUABLE' : '' }}</td></tr>
  <tr><th>Category</th><td>{{ $i->categoryLabel() }}</td></tr>
  <tr><th>Description</th><td>{{ $i->description ?: '—' }}</td></tr>
  <tr><th>Found</th><td>{{ $i->found_date->format('d M Y') }} · {{ $i->location ?? '—' }}</td></tr>
  <tr><th>Found by</th><td>{{ $i->found_by ?? '—' }}</td></tr>
  <tr><th>Store in</th><td>{{ $i->storage_location ?? '—' }}</td></tr>
  <tr><th>Keep until</th><td><b>{{ $i->retain_until?->format('d M Y') ?? '—' }}</b></td></tr>
</table>
<p class="small" style="margin-top:1.2rem">Attach this tag to the item (sealed bag for valuables). Logged by: ______________ &nbsp; Witness: ______________</p>
@endsection
