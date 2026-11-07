<table class="kot-items">
  <thead><tr><th scope="col" class="kot-item-qty-heading" aria-label="Quantity" title="Quantity">Qty</th><th scope="col" class="kot-item-name-heading">Item</th></tr></thead>
  <tbody>
@foreach($items as $l)
  @php
    $lineOptions = collect($l->modifiers ?? []);
    $sideDishes = $lineOptions->where('type', 'side_dish');
    $addOns = $lineOptions->reject(fn ($m) => ($m['type'] ?? null) === 'side_dish');
  @endphp
  <tr>
    <td class="kot-item-qty"><b>{{ (float) $l->qty }}</b></td>
    <td class="kot-item-body">
      <div class="kot-item-heading">
        @if($l->kitchen_status === 'held')<b class="kot-item-hold">HOLD</b>@endif
        <b class="kot-item-name">{{ $l->item_name }}</b>
        @if($l->is_complimentary)<b class="kot-item-tag">COMP</b>@endif
      </div>
      @if($l->is_open)
        <div class="kot-item-meta"><b class="kot-item-tag">SPECIAL</b>@if($l->food_type) · {{ ['veg' => 'Veg', 'non_veg' => 'Non-veg', 'egg' => 'Egg', 'beverage' => 'Drink'][$l->food_type] ?? '' }}@endif @if($l->kitchen_station) · {{ $l->kitchen_station }}@endif</div>
      @endif
      @if($sideDishes->isNotEmpty())<div class="kot-item-detail"><b>Side dishes:</b> {{ \App\Services\PosMenuVariantService::describe($sideDishes) }}</div>@endif
      @if($addOns->isNotEmpty())<div class="kot-item-detail"><b>Options:</b> {{ $addOns->pluck('name')->implode(', ') }}</div>@endif
      @if($l->notes)<div class="kot-item-note">{{ $l->notes }}</div>@endif
      @if($l->allergy_flag && ! $l->is_cancelled)<div class="kot-item-allergy">!! {{ $l->allergy_flag }}</div>@endif
    </td>
  </tr>
@endforeach
  </tbody>
</table>
