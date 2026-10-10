@if($clash = session('clash'))
<div class="alert alert-warn" id="clashAlert"><x-icon name="alert"/><div>
  <b>Double-booked:</b> {{ $clash['message'] }}
  @if(!empty($clash['trip']) && !empty($clash['vehicle_id']) && can_do('transport.book'))
    <form method="post" action="{{ route('tr.assign', $clash['trip']) }}" class="inline" style="margin-left:.4rem">@csrf
      <input type="hidden" name="vehicle_id" value="{{ $clash['vehicle_id'] }}"><input type="hidden" name="driver_id" value="{{ $clash['driver_id'] }}">
      <input type="hidden" name="force" value="1"><input type="hidden" name="notify" value="1">
      <button class="btn btn-sm btn-danger" id="forceAssign">Assign anyway</button></form>
    <span class="small muted">or pick another car / driver.</span>
  @else
    <span class="small muted">Tick “assign even if busy” to override, or choose another time, car or driver.</span>
  @endif
</div></div>
@endif
