{{-- v4.0 API client form – $c = ApiClient|null, $owners, $sources --}}
<x-field name="name" label="Client / app name" required :value="$c?->name" maxlength="80" placeholder="Hotel website booking widget"/>
<x-field name="description" label="Purpose / vendor contact" :value="$c?->description" maxlength="255" placeholder="Built by Acme Digital – dev@acme.example"/>
<div class="field span-2">
  <label>Permissions (scopes) <span class="req">*</span></label>
  <div class="flex" style="gap:.35rem;flex-wrap:wrap;margin-bottom:.4rem">
    <span class="tiny muted" style="align-self:center">Presets:</span>
    @foreach(\App\Models\ApiClient::PRESETS as $pk => [$pl, $ps])<button type="button" class="btn btn-sm" data-preset="{{ implode(',', $ps) }}">{{ $pl }}</button>@endforeach
  </div>
  <div class="scope-grid">
    @foreach(\App\Models\ApiClient::SCOPES as $sk => [$sl, $sg])
      <label class="check"><input type="checkbox" name="scopes[]" value="{{ $sk }}" @checked(in_array($sk, old('scopes', $c?->scopes ?? []), true))><span><code>{{ $sk }}</code><small>{{ $sl }}</small></span></label>
    @endforeach
  </div>
  <span class="hint">Grant only what the integration needs. Guest and folio scopes expose personal data – they are logged per request.</span>
</div>
<x-field name="ip_allowlist" label="Allowed IPs / CIDR ranges" type="textarea" rows="2" :value="$c?->ip_allowlist" placeholder="e.g. 203.0.113.10, 198.51.100.0/24" hint="One per line. Empty = any IP (not recommended for write access)."/>
<div>
  <x-field name="rate_limit" label="Rate limit (requests / minute)" type="number" min="10" max="6000" :value="$c?->rate_limit ?? 120" required/>
  <x-field name="expires_on" label="Key expires on" type="date" :value="$c?->expires_on?->toDateString()" hint="Optional – e.g. the end of a vendor contract"/>
</div>
<x-field name="owner_id" label="Acts as user (audit trail)" type="select" :options="$owners" :value="$c?->owner_id" placeholder="— Me (the user issuing the key) —" hint="Reservations and status changes made with the key are recorded against this user."/>
<x-field name="business_source_id" label="Business source for bookings" type="select" :options="$sources" :value="$c?->business_source_id" placeholder="— Default (API) —" hint="Used for reservations:write – shows up in source-wise reports."/>
@if($c)<div class="field"><label>&nbsp;</label><label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($c->is_active)> Active – uncheck to pause the key without revoking it</label></div>@endif
