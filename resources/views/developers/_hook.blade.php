{{-- v4.0 webhook endpoint form – $e = WebhookEndpoint|null --}}
<x-field name="name" label="Name" required :value="$e?->name" maxlength="80" placeholder="Revenue system – reservation feed"/>
<x-field name="url" label="Endpoint URL (HTTPS)" type="url" required :value="$e?->url" maxlength="500" placeholder="https://hooks.example.com/nowills"/>
<x-field name="description" label="Notes" :value="$e?->description" maxlength="255" class="span-2" placeholder="Owner, contact, what consumes it"/>
<div class="field span-2">
  <label>Events <span class="req">*</span></label>
  <label class="check" style="margin-bottom:.35rem"><input type="checkbox" name="events[]" value="*" @checked(in_array('*', old('events', $e?->events ?? []), true))> <b>All events</b> <span class="tiny muted">(including ones added in future versions)</span></label>
  <div class="scope-grid">
    @foreach(\App\Services\WebhookService::EVENTS as $ek => $ev)
      <label class="check"><input type="checkbox" name="events[]" value="{{ $ek }}" @checked(in_array($ek, old('events', $e?->events ?? []), true))><span><code>{{ $ek }}</code><small>{{ $ev[0] }}</small></span></label>
    @endforeach
  </div>
</div>
