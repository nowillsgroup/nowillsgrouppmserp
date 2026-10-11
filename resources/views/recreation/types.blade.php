@extends('layouts.app')
@section('title', 'Ticket Types')
@section('crumbs') <a href="{{ route('club.index') }}">Wellness &amp; Recreation</a> / Ticket types @endsection
@section('actions')
<a class="btn" href="{{ route('recreation.outlet') }}"><x-icon name="receipt"/> Ticket outlet</a>
<button class="btn btn-primary" data-modal="typeModal" id="newType"><x-icon name="plus"/> New ticket type</button>
@endsection
@section('content')
<div class="card flush"><div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Code</th><th>Name</th><th>Category</th><th>Pax</th><th class="num">Price (excl. tax)</th><th>Tickets</th><th>Order</th><th>Status</th><th></th></tr></thead>
  <tbody>@forelse($rows as $t)
    <tr class="{{ $t->is_active ? '' : 'muted' }}">
      <td class="bold">{{ $t->code }}</td>
      <td>{{ $t->name }}</td>
      <td><span class="badge badge-{{ $t->category === 'access' ? 'green' : ($t->category === 'rental' ? 'blue' : 'purple') }}">{{ \App\Models\ClubTicketType::CATEGORIES[$t->category] }}</span></td>
      <td class="small">{{ \App\Models\ClubTicketType::PAX_TYPES[$t->pax_type] }}</td>
      <td class="num bold">{{ money($t->price, false) }}</td>
      <td class="small">{{ $t->issues_pass ? 'issues entry tickets' : 'no ticket' }}</td>
      <td class="num">{{ $t->sort }}</td>
      <td><span class="badge badge-{{ $t->is_active ? 'green' : 'slate' }}">{{ $t->is_active ? 'On sale' : 'Off sale' }}</span></td>
      <td class="num"><button class="btn btn-sm btn-ghost" data-modal="typeModal" data-title="Edit {{ $t->name }}" data-action="{{ route('recreation.types.update', $t) }}"
        data-fields="{{ collect(['code' => $t->code, 'name' => $t->name, 'category' => $t->category, 'pax_type' => $t->pax_type, 'price' => $t->price, 'tax_rate' => $t->tax_rate, 'issues_pass' => $t->issues_pass ? 1 : 0, 'is_active' => $t->is_active ? 1 : 0, 'sort' => $t->sort])->toJson() }}">Edit</button></td>
    </tr>
  @empty <tr><td colspan="9"><x-empty text="No ticket types yet." icon="receipt"/></td></tr> @endforelse</tbody>
</table></div></div>

<div class="modal" id="typeModal"><div class="modal-box" style="max-width:560px"><form method="post" action="{{ route('recreation.types.store') }}" data-reset id="typeForm">@csrf
  <input type="hidden" name="_method" value="POST" id="typeMethod">
  <div class="modal-head"><h3 data-title>New ticket type</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="code" label="Code" required placeholder="ADULT" class="span-2"/>
    <x-field name="name" label="Name" required placeholder="Adult day pass" class="span-2"/>
    <x-field name="category" label="Category" type="select" :options="\App\Models\ClubTicketType::CATEGORIES" required/>
    <x-field name="pax_type" label="Ticket covers" type="select" :options="\App\Models\ClubTicketType::PAX_TYPES" required/>
    <x-field name="price" label="Price (excl. tax)" type="number" step="0.01" min="0" required/>
    <x-field name="tax_rate" label="Tax rate % (blank = standard)" type="number" step="0.01" min="0" max="100"/>
    <x-field name="sort" label="Sort order" type="number" value="0" min="0"/>
    <div class="field"><label>Options</label>
      <label class="check"><input type="checkbox" name="issues_pass" value="1" checked> <span>Issues an entry ticket (day pass)</span></label>
      <label class="check"><input type="checkbox" name="is_active" value="1" checked> <span>On sale at the outlet</span></label>
    </div>
  </div>
  <p class="tiny muted">Access tickets issue a day-pass credential scanned at the check-in desk. Rentals and services are charged without a ticket.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save ticket type</button></div>
</form></div></div>
@endsection

@push('scripts')
<script>
(() => {
  const form = document.getElementById('typeForm');
  // edit: prefill + switch to PUT when a row's Edit button opens the modal
  document.querySelectorAll('[data-fields]').forEach(btn => btn.addEventListener('click', () => {
    const f = JSON.parse(btn.dataset.fields);
    form.action = btn.dataset.action;
    form.querySelector('#typeMethod').value = 'PUT';
    ['code', 'name', 'category', 'pax_type', 'price', 'tax_rate', 'sort'].forEach(k => {
      const el = form.querySelector('[name="' + k + '"]'); if (el) el.value = f[k] ?? '';
    });
    form.querySelector('[name=issues_pass]').checked = +f.issues_pass === 1;
    form.querySelector('[name=is_active]').checked = +f.is_active === 1;
  }));
  document.getElementById('newType').addEventListener('click', () => {
    form.action = '{{ route('recreation.types.store') }}';
    form.querySelector('#typeMethod').value = 'POST';
  });
})();
</script>
@endpush
