{{-- inspection checklist rows: $checklist, $charges (bool) --}}
<div class="table-wrap" style="max-height:48vh;overflow:auto"><table class="table lease-items">
  <thead><tr><th>Area</th><th>Item</th><th style="width:110px">Condition</th><th>Note</th>@if($charges)<th style="width:100px">Charge (GH₵)</th>@endif</tr></thead>
  <tbody>@php $i = 0; @endphp
  @foreach($checklist as $area => $items)@foreach($items as $item)
    <tr><td class="tiny">{{ $area }}<input type="hidden" name="items[{{ $i }}][area]" value="{{ $area }}"></td><td class="small">{{ $item }}<input type="hidden" name="items[{{ $i }}][item]" value="{{ $item }}"></td>
      <td><select name="items[{{ $i }}][condition]">@foreach(\App\Models\LeaseInspection::CONDITIONS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></td>
      <td><input name="items[{{ $i }}][note]" maxlength="160"></td>@if($charges)<td><input type="number" step="0.01" min="0" name="items[{{ $i }}][charge]"></td>@endif</tr>
    @php $i++; @endphp
  @endforeach @endforeach
  </tbody></table></div>
