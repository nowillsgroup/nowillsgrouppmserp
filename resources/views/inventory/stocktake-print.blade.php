@extends('layouts.print')
@section('title', ($blind ? 'Count sheet ' : 'Stock variance ').$take->take_no)
@section('content')
<h2 class="center" style="margin:.5rem 0">{{ $blind ? 'PHYSICAL STOCK COUNT SHEET' : 'STOCK TAKE VARIANCE REPORT' }}</h2>
<p class="center small">{{ $take->take_no }} · Store: <b>{{ $take->store->name }}</b> · Count date: {{ $take->count_date->format('d M Y') }} · Status: {{ ucfirst($take->status) }}</p>
<table class="table table-compact" style="margin-top:1rem"><thead><tr><th>#</th><th>Item</th><th>Unit</th>@unless($blind)<th class="num">System</th>@endunless<th class="num">Counted</th>@unless($blind)<th class="num">Variance</th><th class="num">Value</th>@endunless<th>Remarks</th></tr></thead><tbody>
@foreach($take->lines->sortBy(fn($l) => ($l->item->category->name ?? 'zzz').$l->item->name) as $l)
<tr><td>{{ $loop->iteration }}</td><td>{{ $l->item->name }} <span class="tiny">{{ $l->item->category->name ?? '' }}</span></td><td>{{ $l->item->unit }}</td>
@unless($blind)<td class="num">{{ (float) $l->system_qty }}</td>@endunless
<td class="num" style="{{ $blind ? 'min-width:90px;border-bottom:1px solid #999' : '' }}">{{ $blind ? '' : ($l->counted_qty === null ? '—' : (float) $l->counted_qty) }}</td>
@unless($blind)<td class="num">{{ $l->varianceQty() ?? '' }}</td><td class="num">{{ $l->varianceQty() ? number_format($l->varianceValue(), 2) : '' }}</td>@endunless
<td style="{{ $blind ? 'min-width:140px;border-bottom:1px solid #999' : '' }}">{{ $blind ? '' : $l->remarks }}</td></tr>
@endforeach
@unless($blind)<tr><td colspan="6" class="right bold">Total variance value</td><td class="num bold">{{ number_format($take->status === 'posted' ? $take->variance_value : $take->lines->sum(fn($l) => $l->varianceValue()), 2) }}</td><td></td></tr>@endunless
</tbody></table>
<div class="flex justify-between" style="margin-top:3rem"><div>______________________<br>Counted by</div><div>______________________<br>Verified by</div><div>______________________<br>Approved by</div></div>
@endsection
