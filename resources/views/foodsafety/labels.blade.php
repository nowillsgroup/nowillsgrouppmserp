@extends('layouts.app')
@section('title', 'Prep Labels')
@section('crumbs') <a href="{{ route('fs.index') }}">Food Safety</a> / Prep labels @endsection
@section('actions')
@if(session('print_label'))<a class="btn btn-accent" target="_blank" href="{{ route('fs.labels.print', session('print_label')) }}" id="printLast"><x-icon name="print"/> Print labels</a>@endif
@endsection
@section('content')
@php $A = \App\Services\AllergenService::class; @endphp
<div class="fs-split">
  <form method="post" action="{{ route('fs.labels.store') }}" class="card" id="labelForm">@csrf
    <div class="card-head"><h3>New prep labels</h3></div>
    <div class="card-body"><div class="form-grid">
      <x-field name="menu_item_id" label="Dish (allergens filled from the matrix)" type="select" :options="$dishes" placeholder="— or type an item below —"/>
      <x-field name="item" label="Item / prep" placeholder="e.g. Shito sauce, chopped onions"/>
      <div class="field"><label for="f_storage">Storage <span class="req">*</span></label><select name="storage" id="f_storage" required>@foreach(\App\Models\FsLabel::STORAGE as $k => $l)<option value="{{ $k }}" data-hours="{{ $shelf[$k] }}" @selected(old('storage', 'chilled') === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="field"><label for="f_shelf_hours">Shelf life (hours)</label><input type="number" name="shelf_hours" id="f_shelf_hours" min="1" max="4320" value="{{ old('shelf_hours', $shelf['chilled']) }}"><span class="hint" id="useByHint"></span></div>
      <x-field name="prepared_at" label="Prepared at" type="datetime-local" :value="now()->format('Y-m-d\TH:i')"/>
      <x-field name="qty" label="Number of labels" type="number" min="1" max="48" value="1" required/>
      <x-field name="batch" label="Batch (optional)"/>
    </div>
    <div class="field mt-1"><label>Extra allergens (added to the dish's)</label><div class="allergen-picks">@foreach($A::ALLERGENS as $k => [$l, $code])<label><input type="checkbox" name="allergens[]" value="{{ $k }}"> <span class="allergen-codes">{{ $code }}</span> {{ $A::short($k) }}</label>@endforeach</div></div>
    </div>
    <div class="card-foot"><button class="btn btn-primary" id="labelSave"><x-icon name="print"/> Create &amp; print</button></div>
  </form>
  <div class="card flush"><div class="card-head"><h3>Recent labels</h3></div><div class="card-body table-wrap"><table class="table" id="labelTable">
    <thead><tr><th>Item</th><th>Use by</th><th class="num">Qty</th><th></th></tr></thead>
    <tbody>@forelse($recent as $l)<tr data-label="{{ $l->id }}" class="{{ $l->use_by->isPast() ? 'muted' : '' }}"><td><b class="small">{{ $l->item }}</b><div class="tiny muted">{{ \App\Models\FsLabel::STORAGE[$l->storage] }} · {{ $l->user?->name }}{{ $l->allergens ? ' · '.$A::names($l->allergens) : '' }}</div></td>
      <td class="small {{ ! $l->use_by->isPast() && $l->use_by->lt(now()->addHours(12)) ? 'text-red bold' : '' }}">{{ $l->use_by->format('D d/m H:i') }}{{ $l->use_by->isPast() ? ' (expired)' : '' }}</td><td class="num">{{ $l->qty }}</td>
      <td><a class="btn btn-sm btn-ghost btn-icon" target="_blank" href="{{ route('fs.labels.print', $l) }}" title="Reprint"><x-icon name="print" size="sm"/></a></td></tr>
    @empty <tr><td colspan="4"><x-empty text="No labels printed yet." icon="print"/></td></tr> @endforelse</tbody></table></div></div>
</div>
@if(session('print_label'))<iframe src="{{ route('fs.labels.print', session('print_label')) }}?autoprint=1" style="position:absolute;width:0;height:0;border:0" title="Labels"></iframe>@endif
@endsection
@push('scripts')
<script>
(() => { const st = document.getElementById('f_storage'), h = document.getElementById('f_shelf_hours'), p = document.getElementById('f_prepared_at'), hint = document.getElementById('useByHint');
  const show = () => { const d = new Date((p.value ? new Date(p.value) : new Date()).getTime() + (+h.value || 0) * 3600e3); hint.textContent = h.value ? 'Use by ' + d.toLocaleString([], {weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'}) : ''; };
  st.addEventListener('change', () => { h.value = st.selectedOptions[0].dataset.hours; show(); }); [h, p].forEach(e => e.addEventListener('input', show)); show(); })();
</script>
@endpush
