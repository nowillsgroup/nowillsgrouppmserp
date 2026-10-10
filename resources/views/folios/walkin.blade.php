@extends('layouts.app')
@section('title', 'Non-Resident Bill')
@section('crumbs') Billing / New non-resident folio @endsection
@section('content')
<form method="post" action="{{ route('folios.walkin.store') }}" class="card" style="max-width:720px">@csrf
<div class="card-head"><h3>Customer details</h3></div>
<div class="card-body form-grid">
  <x-field name="first_name" label="First name" required/><x-field name="last_name" label="Last name"/><x-field name="phone" label="Phone"/>
  <x-field name="company_id" label="Company" type="select" :options="$companies"/><x-field name="tax_number" label="TIN / Ghana Card PIN"/>
</div>
<div class="card-foot"><button class="btn btn-primary">Open Folio</button> <span class="small muted">Use for spa, laundry, event or restaurant guests not staying in-house.</span></div>
</form>
@endsection
