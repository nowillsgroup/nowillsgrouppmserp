@extends('layouts.app')
@section('title', $block ? 'Edit '.$block->block_no : 'New Group Block')
@section('crumbs') Reservations / Group Blocks / {{ $block ? 'Edit' : 'New' }} @endsection
@section('content')
<form method="post" action="{{ $block ? route('blocks.update', $block) : route('blocks.store') }}">@csrf @if($block) @method('PUT') @endif
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3>Group details</h3></div><div class="card-body form-grid">
    <x-field name="name" label="Group / event name" :value="$block?->name" required class="span-all" placeholder="Mensah–Owusu wedding"/>
    <x-field name="company_id" label="Company" type="select" :options="$companies" :value="$block?->company_id"/>
    <x-field name="business_source_id" label="Business source" type="select" :options="$sources" :value="$block?->business_source_id"/>
    <x-field name="contact_name" label="Contact person" :value="$block?->contact_name"/>
    <x-field name="contact_phone" label="Contact phone" :value="$block?->contact_phone"/>
    <x-field name="contact_email" label="Contact email" type="email" :value="$block?->contact_email"/>
    <x-field name="billing_instruction" label="Billing" type="select" :options="['guest' => 'Each guest pays', 'company' => 'Bill to company']" :value="$block?->billing_instruction ?? 'guest'" :placeholder="false"/>
    <x-field name="status" label="Status" type="select" :options="['tentative' => 'Tentative', 'definite' => 'Definite']" :value="$block?->status ?? 'tentative'" :placeholder="false"/>
    <x-field name="notes" label="Notes" type="textarea" :value="$block?->notes" class="span-all"/>
  </div></div>
  <div>
  <div class="card"><div class="card-head"><h3>Dates &amp; rate</h3></div><div class="card-body form-grid">
    <x-field name="arrival_date" label="Arrival" type="date" :value="$block?->arrival_date?->toDateString() ?? bdate()->copy()->addDays(14)->toDateString()" required/>
    <x-field name="departure_date" label="Departure" type="date" :value="$block?->departure_date?->toDateString() ?? bdate()->copy()->addDays(16)->toDateString()" required/>
    <x-field name="cutoff_date" label="Cut-off (release) date" type="date" :value="$block?->cutoff_date?->toDateString() ?? bdate()->copy()->addDays(7)->toDateString()" required hint="Unpicked rooms are released at this night's audit"/>
    <x-field name="rate" label="Group rate / night" type="number" step="0.01" min="0" :value="$block?->rate" hint="Leave empty to use the rate plan"/>
  </div></div>
  <div class="card"><div class="card-head"><h3>Rooms to hold (per night)</h3></div><div class="card-body">
    @error('lines')<div class="alert alert-error">{{ $message }}</div>@enderror
    <table class="table"><thead><tr><th>Room type</th><th class="right">Base rate</th><th style="width:120px">Rooms</th></tr></thead><tbody>
    @foreach($types as $t)
      <tr><td>{{ $t->name }} <span class="tiny muted">{{ $t->code }}</span></td><td class="right">{{ money($t->base_rate) }}</td>
      <td><input type="number" name="lines[{{ $t->id }}]" min="0" max="500" value="{{ old('lines.'.$t->id, $lines[$t->id] ?? 0) }}" style="width:100px"></td></tr>
    @endforeach
    </tbody></table>
    <p class="tiny muted">Checked against live availability for every night. Overbooking is refused.</p>
  </div></div>
  </div>
</div>
<div class="flex gap-1" style="justify-content:flex-end"><a href="{{ $block ? route('blocks.show', $block) : route('blocks.index') }}" class="btn">Cancel</a><button class="btn btn-primary">{{ $block ? 'Save changes' : 'Create block' }}</button></div>
</form>
@endsection
