@extends('layouts.app')
@section('title', 'New gate pass')
@section('crumbs') Security / <a href="{{ route('security.gp.index') }}">Gate passes</a> / New @endsection
@section('content')
<form method="post" action="{{ route('security.gp.store') }}" id="gpForm" class="card">@csrf
  <div class="card-head"><h3>Material gate pass</h3><span class="small muted">{{ $auto ? 'Approval is switched off – the pass is valid as soon as it is raised.' : 'A manager approves the pass before the goods can leave.' }}</span></div>
  <div class="card-body form-grid" style="grid-template-columns:repeat(3,1fr)">
    <div class="field"><label>Type <span class="req">*</span></label><div class="flex gap-1" id="gpTypes">
      <label class="check"><input type="radio" name="type" value="returnable" @checked(old('type', 'returnable') === 'returnable')> Returnable</label>
      <label class="check"><input type="radio" name="type" value="non_returnable" @checked(old('type') === 'non_returnable')> Non-returnable</label></div></div>
    <x-field name="purpose" label="Purpose" type="select" :placeholder="false" :options="\App\Models\GatePass::PURPOSES" required/>
    <x-field name="department_id" label="Department" type="select" :options="$departments"/>
    <x-field name="party_name" label="Going to (vendor / party)" required maxlength="150" placeholder="Cool Air Services"/>
    <x-field name="party_phone" label="Party phone" maxlength="30"/>
    <x-field name="expected_return_date" label="Expected back on" type="date" :min="bdate()->toDateString()" :value="old('expected_return_date', bdate()->copy()->addDays(7)->toDateString())"/>
    <x-field name="carrier_name" label="Carried by" maxlength="120" placeholder="Vendor technician's name"/>
    <x-field name="vehicle_no" label="Vehicle no." maxlength="20"/>
    <x-field name="reason" label="Reason / remarks" maxlength="500" placeholder="Compressor not cooling – warranty repair"/>
  </div>
  <div class="card-body" style="padding-top:0">
    <h4 class="mb-1">Items</h4>
    <div class="table-wrap"><table class="table" id="gpItems"><thead><tr><th style="width:26%">Fixed asset (optional)</th><th>Description</th><th style="width:9%">Qty</th><th style="width:9%">Unit</th><th style="width:13%">Est. value</th></tr></thead><tbody>
    @for($n = 0; $n < 6; $n++)
      <tr><td><select name="items[{{ $n }}][asset_id]" class="input gp-asset"><option value="">—</option>@foreach($assets as $a)<option value="{{ $a->id }}" data-name="{{ $a->name }}" @selected(old("items.$n.asset_id", $n === 0 ? $asset : null) == $a->id)>{{ $a->asset_no }} · {{ \Illuminate\Support\Str::limit($a->name, 34) }}{{ $a->status === 'in_repair' ? ' (in repair)' : '' }}</option>@endforeach</select></td>
        <td><input name="items[{{ $n }}][description]" class="input" maxlength="200" value="{{ old("items.$n.description") }}" @if($n === 0) placeholder="Split AC indoor unit 1.5 T" @endif></td>
        <td><input name="items[{{ $n }}][quantity]" type="number" step="0.01" min="0" class="input" value="{{ old("items.$n.quantity") }}"></td>
        <td><input name="items[{{ $n }}][unit]" class="input" maxlength="15" value="{{ old("items.$n.unit", 'nos') }}"></td>
        <td><input name="items[{{ $n }}][est_value]" type="number" step="0.01" min="0" class="input" value="{{ old("items.$n.est_value") }}"></td></tr>
    @endfor
    </tbody></table></div>
    <p class="tiny muted">Picking a fixed asset fills the description; an asset sent for repair shows "under repair" in the asset register until it comes back.</p>
  </div>
  <div class="card-foot" style="padding:1rem;display:flex;gap:.5rem;justify-content:flex-end"><a class="btn" href="{{ route('security.gp.index') }}">Cancel</a><button class="btn btn-primary" type="submit">Raise gate pass</button></div>
</form>
@push('scripts')
<script>
(() => {
  const f = document.getElementById('gpForm');
  const syncType = () => { const r = f.querySelector('[name=type]:checked').value === 'returnable'; const d = f.querySelector('[name=expected_return_date]'); d.closest('.field').style.visibility = r ? 'visible' : 'hidden'; d.required = r; };
  f.querySelectorAll('[name=type]').forEach(x => x.addEventListener('change', syncType)); syncType();
  f.querySelectorAll('.gp-asset').forEach(s => s.addEventListener('change', () => { const tr = s.closest('tr'); const o = s.selectedOptions[0];
    if (o && o.value) { const d = tr.querySelector('[name$="[description]"]'); if (!d.value) d.value = o.dataset.name; tr.querySelector('[name$="[quantity]"]').value = 1; } }));
  f.querySelectorAll('.gp-asset').forEach(s => { if (s.value) s.dispatchEvent(new Event('change')); });
})();
</script>
@endpush
@endsection
