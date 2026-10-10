@extends('layouts.print')
@section('title', 'Recipe card – '.$m->name)
@section('doctitle') Standard Recipe Card @endsection
@section('docmeta')<div class="small"><b>{{ $m->name }}</b> · {{ $m->code }}</div><div class="small">{{ $m->category?->name }} · {{ $card ? 'v'.$card->version : 'draft' }}</div>@endsection
@section('content')
<table class="table table-compact" style="margin-bottom:1rem">
  <tr><td><b>Portion</b><br>{{ $card?->portion_size ?? '—' }}</td><td><b>Batch</b><br>{{ $card?->batch_portions ?? 1 }} portion(s)</td>
    <td><b>Preparation</b><br>{{ $card && $card->prep_minutes !== null ? $card->prep_minutes.' min' : '—' }}</td><td><b>Station</b><br>{{ $card?->station ?? $m->category?->kitchen_station ?? '—' }}</td>
    <td><b>Menu price</b><br>{{ money($price) }}</td><td><b>Plate cost</b><br>{{ money($cost) }} ({{ $pct ?? '—' }}%)</td></tr>
</table>
<h3>Ingredients</h3>
<table class="table table-compact" id="fbcPrintLines"><thead><tr><th>Ingredient</th><th class="num">Net / portion</th><th class="num">Yield</th><th class="num">Gross / portion</th>
  @if($card && $card->batch_portions > 1)<th class="num">Gross / batch</th>@endif<th class="num">Cost</th></tr></thead><tbody>
  @foreach($lines as $l)<tr><td>{{ $l->item->name }}</td><td class="num">{{ rtrim(rtrim(number_format($l->qty, 3), '0'), '.') }} {{ $l->item->unit }}</td>
    <td class="num">{{ rtrim(rtrim(number_format($l->item->yield_pct, 1), '0'), '.') }}%</td><td class="num">{{ rtrim(rtrim(number_format($l->gross, 3), '0'), '.') }} {{ $l->item->unit }}</td>
    @if($card && $card->batch_portions > 1)<td class="num">{{ rtrim(rtrim(number_format($l->gross * $card->batch_portions, 3), '0'), '.') }} {{ $l->item->unit }}</td>@endif
    <td class="num">{{ money($l->cost) }}</td></tr>@endforeach
</tbody></table>
<h3 style="margin-top:1rem">Method</h3>
<div class="fbc-method">{!! $card?->method ? nl2br(e($card->method)) : '<i>No method written.</i>' !!}</div>
@if($card?->garnish)<p><b>Garnish / presentation:</b> {{ $card->garnish }}</p>@endif
@if($m->allergens)<p><b>Allergens:</b> {{ implode(', ', (array) $m->allergens) }}</p>@endif
<p class="small" style="margin-top:1.5rem">Approved: {{ $card?->approved_at ? $card->approver?->name.' on '.$card->approved_at->format('d M Y') : '________________________' }} &nbsp;&nbsp; Printed {{ now()->format('d M Y H:i') }}</p>
@endsection
