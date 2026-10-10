@extends('layouts.app')
@section('title', $c->exists ? 'Edit '.$c->name : 'New channel connection')
@section('crumbs') <a href="{{ route('cm.index') }}">Channel Manager</a> / {{ $c->exists ? $c->name : 'New connection' }} @endsection
@section('content')
<form method="post" action="{{ $c->exists ? route('cm.connections.update', $c) : route('cm.connections.store') }}" id="connForm">@csrf @if($c->exists) @method('PUT') @endif
<div class="grid g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>Channel</h3></div><div class="card-body form-grid">
      <x-field name="name" label="Channel name" :value="$c->name" required placeholder="e.g. Booking.com"/>
      <x-field name="code" label="Code" :value="$c->code" required placeholder="e.g. BDC" style="text-transform:uppercase" hint="Prefix for booking refs"/>
      <x-field name="provider" label="Connection type" type="select" :options="\App\Models\ChannelConnection::PROVIDERS" :value="$c->provider" required :placeholder="false" class="span-2"/>
      <x-field name="property_code" label="Hotel ID at the channel" :value="$c->property_code" placeholder="e.g. 4412087"/>
      <x-field name="push_days" label="Push horizon (days)" type="number" min="30" max="400" :value="$c->push_days" required/>
      <div class="span-all" id="hubFields">
        <div class="form-grid">
          <x-field name="endpoint_url" label="ARI endpoint URL (HTTPS)" :value="$c->endpoint_url" placeholder="https://api.channel.example/ari" class="span-2" hint="Availability, rates & restrictions are POSTed here as signed JSON"/>
          <x-field name="api_key" label="API key / bearer token" type="password" value="" :placeholder="$c->api_key ? '•••••••• stored – leave blank to keep' : 'optional'" autocomplete="new-password"/>
        </div>
      </div>
    </div></div>
    <div class="card"><div class="card-head"><h3>Commercial terms</h3></div><div class="card-body form-grid">
      <x-field name="commission_pct" label="Commission %" type="number" step="0.01" min="0" max="50" :value="$c->commission_pct" required hint="Accrued to the OTA account at check-out"/>
      <x-field name="rate_markup_pct" label="Rate markup %" type="number" step="0.01" min="-50" max="100" :value="$c->rate_markup_pct" required hint="Channel price = PMS price × (1 + markup)"/>
      <x-field name="rate_rounding" label="Rounding" type="select" :options="\App\Models\ChannelConnection::ROUNDING" :value="$c->rate_rounding" required :placeholder="false"/>
      <x-field name="payment_model" label="Collect model" type="select" :options="\App\Models\ChannelConnection::PAYMENT_MODELS" :value="$c->payment_model" required :placeholder="false" class="span-2" hint="Channel collect bills the stay to the OTA account (city ledger)"/>
      <x-field name="business_source_id" label="Business source" type="select" :options="$sources" :value="$c->business_source_id" placeholder="Create one with the channel name"/>
      <x-field name="company_id" label="OTA account (commission & billing)" type="select" :options="$companies->mapWithKeys(fn ($co) => [$co->id => $co->name.' ('.str_replace('_', ' ', $co->type).')'])" :value="$c->company_id" placeholder="Create one with the channel name" class="span-2"/>
    </div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Status</h3></div><div class="card-body">
      <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $c->is_active))> <b>Active</b> – push ARI and accept bookings</label>
      <p class="small muted mt-1">Paused connections refuse inbound bookings (HTTP 423, the channel retries) and receive no pushes. Re-activating queues a full refresh.</p>
      <p class="small muted">The inbound webhook URL and the shared HMAC secret are shown on the connection page after saving.</p>
      <button class="btn btn-primary mt-1" id="saveConn"><x-icon name="check"/> {{ $c->exists ? 'Save' : 'Create connection' }}</button>
    </div></div>
  </div>
</div>
</form>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const p = document.getElementById('f_provider'), box = document.getElementById('hubFields');
  const sync = () => box.style.display = p.value === 'hub' ? '' : 'none';
  p.addEventListener('change', sync); sync();
});
</script>
@endsection
