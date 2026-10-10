@extends('layouts.app')
@section('title', 'Food Handler Certificates')
@section('crumbs') <a href="{{ route('fs.index') }}">Food Safety</a> / Food handler certificates @endsection
@section('actions')
@if(can_do('foodsafety.certs'))<button class="btn btn-primary" data-modal="certModal" data-f-type="medical" data-f-issued_on="{{ now()->toDateString() }}" data-f-expires_on="{{ now()->addYear()->subDay()->toDateString() }}" id="addCert"><x-icon name="plus"/> Record certificate</button>@endif
@endsection
@section('content')
@php $FS = \App\Services\FoodSafetyService::class; $badge = ['valid' => 'badge-green', 'expiring' => 'badge-amber', 'expired' => 'badge-red', 'missing' => 'badge-red']; @endphp
<div class="card flush"><div class="card-head"><h3>Food handlers – {{ collect($handlerDepts)->map(fn ($d) => $departments[$d] ?? null)->filter()->implode(', ') ?: 'no departments set' }}</h3></div>
<div class="card-body table-wrap"><table class="table" id="certTable">
  <thead><tr><th>Employee</th><th>Department</th><th>Medical fitness</th><th>Hygiene training</th><th></th></tr></thead>
  <tbody>@forelse($handlers as $e)
    <tr data-emp="{{ $e->emp_code }}">
      <td><b>{{ $e->name }}</b> <span class="tiny muted">{{ $e->emp_code }}</span><div class="tiny muted">{{ $e->designation }}</div></td><td class="small">{{ $e->department?->name }}</td>
      @foreach(['medical', 'hygiene'] as $type)
        @php $c = $map[$e->id][$type] ?? null; $s = $FS::certState($c); @endphp
        <td data-cert="{{ $type }}" data-state="{{ $s }}"><span class="badge {{ $badge[$s] }}">{{ $s }}</span>@if($c)<div class="tiny muted">{{ $c->cert_no }} · to {{ dmy($c->expires_on) }}{{ $c->issuer ? ' · '.$c->issuer : '' }}</div>@endif</td>
      @endforeach
      <td class="num">@if(can_do('foodsafety.certs'))<button class="btn btn-sm" data-modal="certModal" data-f-employee_id="{{ $e->id }}" data-f-type="{{ $FS::certState($map[$e->id]['medical'] ?? null) === 'valid' ? 'hygiene' : 'medical' }}" data-f-issued_on="{{ now()->toDateString() }}" data-f-expires_on="{{ now()->addYear()->subDay()->toDateString() }}">Renew</button>@endif</td></tr>
  @empty <tr><td colspan="5"><x-empty text="No food handlers in the selected departments." icon="users"/></td></tr> @endforelse</tbody></table></div></div>
<p class="tiny muted">Food handlers need a current medical fitness (health) certificate before handling food, renewed yearly, under the Public Health Act 2012 (Act 851) and FDA / district assembly food hygiene rules. The duty roster warns when a food handler without a valid certificate is rostered. Choose the food-handling departments under Temperature logs → Critical limits.</p>

<div class="card flush mt-1"><div class="card-head"><h3>Recent certificates</h3></div><div class="card-body table-wrap"><table class="table" id="certHistory">
  <thead><tr><th>Employee</th><th>Type</th><th>Number</th><th>Issuer</th><th>Issued</th><th>Expires</th></tr></thead>
  <tbody>@forelse($history as $c)<tr><td>{{ $c->employee?->name }}</td><td class="small">{{ \App\Models\FsCertificate::TYPES[$c->type] }}</td><td class="small">{{ $c->cert_no }}</td><td class="small">{{ $c->issuer }}</td><td>{{ dmy($c->issued_on) }}</td><td>{{ dmy($c->expires_on) }}</td></tr>
  @empty <tr><td colspan="6"><x-empty text="No certificates recorded." icon="id"/></td></tr> @endforelse</tbody></table></div></div>

@if(can_do('foodsafety.certs'))
<div class="modal" id="certModal"><div class="modal-box"><form method="post" action="{{ route('fs.certs.store') }}" data-reset id="certForm">@csrf
  <div class="modal-head"><h3>Record certificate</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="employee_id" label="Employee" type="select" :options="$employees" required class="span-2"/>
    <x-field name="type" label="Certificate" type="select" :options="\App\Models\FsCertificate::TYPES" :placeholder="false" required class="span-2"/>
    <x-field name="cert_no" label="Certificate no."/>
    <x-field name="issuer" label="Issued by" placeholder="e.g. Accra Metropolitan Assembly Health Dept"/>
    <x-field name="issued_on" label="Issued on" type="date" required/>
    <x-field name="expires_on" label="Valid until" type="date" required/>
    <x-field name="notes" label="Notes" class="span-2"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="certSave">Save</button></div>
</form></div></div>
@endif
@endsection
