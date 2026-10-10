@extends('layouts.print')
@section('title', 'Allergen matrix')
@section('pageclass', 'landscape')
@section('doctitle', 'Allergen Information')
@section('docmeta')<div class="small">{{ $outlet ? $outlet->name.' · ' : '' }}Printed {{ now()->format('d/m/Y H:i') }}</div>@endsection
@section('content')
@php $A = \App\Services\AllergenService::class; @endphp
<style>
@media print { @page { size: A4 landscape; margin: 10mm } }
.am th.v{writing-mode:vertical-rl;transform:rotate(180deg);font-size:10px;padding:4px 2px;white-space:nowrap;height:110px}
.am td,.am th{border:1px solid #cbd5e1;padding:3px 5px;font-size:11px} .am td.c{text-align:center;font-weight:800;width:26px}
.am td.y{background:#fee2e2;color:#991b1b} .am td.m{background:#fef3c7;color:#92400e} .am tr.cat td{background:#f1f5f9;font-weight:700;text-transform:uppercase;font-size:10px}
</style>
<table class="am" style="border-collapse:collapse;width:100%" id="amTable">
  <thead><tr><th style="text-align:left">Dish</th>@foreach($A::ALLERGENS as $k => [$l])<th class="v">{{ $A::short($k) }}</th>@endforeach</tr></thead>
  <tbody>
  @foreach($menu->groupBy(fn ($m) => $m->category?->name ?? 'Other') as $cat => $items)
    <tr class="cat"><td colspan="{{ count($A::ALLERGENS) + 1 }}">{{ $cat }}</td></tr>
    @foreach($items as $m)
      <tr><td>{{ $m->name }}</td>@foreach($A::ALLERGENS as $k => $_)@php $y = in_array($k, $map[$m->id]['contains']); $mm = in_array($k, $map[$m->id]['may']); @endphp<td class="c {{ $y ? 'y' : ($mm ? 'm' : '') }}">{{ $y ? '●' : ($mm ? '◐' : '') }}</td>@endforeach</tr>
    @endforeach
  @endforeach
  </tbody>
</table>
<p class="small" style="margin-top:8px">● contains &nbsp; ◐ may contain (prepared in a kitchen that handles it / cross-contact). Recipes and suppliers can change – if a guest has an allergy, always confirm with the chef on duty. Information declared for the 14 major allergens (Codex / Ghana FDA).</p>
@endsection
