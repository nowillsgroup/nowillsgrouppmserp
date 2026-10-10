<table style="width:100%;font-size:13px">
@foreach($items as $l)
  <tr>
    <td style="width:40px"><b>{{ (float) $l->qty }}</b></td>
    <td>@if($l->kitchen_status === 'held')<b>HOLD</b> @endif<b>{{ $l->item_name }}</b>@if($l->is_open) <b>SPECIAL</b>@if($l->food_type) <span class="tiny">{{ ['veg' => 'Veg', 'non_veg' => 'Non-veg', 'egg' => 'Egg', 'beverage' => 'Drink'][$l->food_type] ?? '' }}</span>@endif @if($l->kitchen_station) <span class="tiny">{{ $l->kitchen_station }}</span>@endif @endif @if($l->is_complimentary) <b>COMP</b>@endif @if($l->modifiers)<br><span class="tiny">Options: {{ collect($l->modifiers)->pluck('name')->implode(', ') }}</span>@endif @if($l->notes)<br><i>{{ $l->notes }}</i>@endif @if($l->allergy_flag && ! $l->is_cancelled)<br><b>!! {{ $l->allergy_flag }}</b>@endif</td>
  </tr>
@endforeach
</table>
