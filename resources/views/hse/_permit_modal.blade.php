@php $tickets = $tickets ?? \App\Models\MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->orderByDesc('id')->limit(60)->get(['id', 'ticket_no', 'title']); @endphp
<div class="modal" id="hsePermitModal"><div class="modal-box"><form method="post" action="{{ route('hse.permits.store') }}" data-reset id="hsePermitForm">@csrf
  <div class="modal-head"><h3 data-title>Request a permit to work</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Type of work</label><select name="type" required>@foreach(\App\Models\HsePermit::TYPES as $k => $t)<option value="{{ $k }}">{{ $t[0] }} – max {{ $t[1] }} h</option>@endforeach</select></div>
    <div class="field"><label>Location</label><input name="location" maxlength="120" required placeholder="Kitchen extract duct, roof level"></div>
    <div class="field"><label>Contractor / team</label><input name="contractor" maxlength="120" placeholder="in-house engineering"></div>
    <div class="field span-all"><label>Work to be done</label><input name="work" maxlength="255" required></div>
    <div class="field"><label>Starts</label><input type="datetime-local" name="starts_at" value="{{ now()->addMinutes(30)->format('Y-m-d\TH:i') }}" required></div>
    <div class="field"><label>Ends</label><input type="datetime-local" name="ends_at" value="{{ now()->addHours(4)->format('Y-m-d\TH:i') }}" required></div>
    <div class="field"><label>Workers</label><input type="number" name="workers" min="1" max="50" value="2"></div>
    <div class="field"><label>Engineering ticket</label><select name="maintenance_request_id"><option value="">—</option>@foreach($tickets as $t)<option value="{{ $t->id }}">{{ $t->ticket_no }} · {{ \Illuminate\Support\Str::limit($t->title, 40) }}</option>@endforeach</select></div>
  </div><p class="tiny muted mt-1">An authorised issuer (not the requester) confirms the precautions on site before work starts.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Request permit</button></div>
</form></div></div>
