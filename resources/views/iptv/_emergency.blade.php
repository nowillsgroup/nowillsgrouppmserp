@if($emergency)
<div class="tv-em-banner" id="tvEmergencyLive"><x-icon name="alert"/><div class="grow">
  <b>EMERGENCY ALERT ON EVERY SCREEN</b> · {{ $emergency->number }} since {{ $emergency->starts_at->format('d M H:i') }} by {{ $emergency->sender?->name ?? 'system' }}
  <div>{{ $emergency->title }} – {{ $emergency->body }}</div>
  </div>
  @if($canEm)<form method="post" action="{{ route('iptv.emergency.clear', $emergency) }}" class="tv-em-clear" id="tvAllClearForm">@csrf
    <input name="note" class="input" maxlength="190" placeholder="All-clear message (optional)"><button class="btn btn-sm">Send all clear</button></form>@endif
</div>
@endif
