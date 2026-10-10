@extends('layouts.app')
@section('title', 'Portal Settings')
@section('crumbs') Organiser Portal / Settings @endsection
@section('content')
@if($errors->has('settings'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('settings') }}</div></div>@endif
<div class="card"><div class="card-body">
  <form method="post" action="{{ route('organiser-admin.settings.save') }}" id="ogaSettings">
    @csrf
    <div class="g-2">
      <div>
        <h3>Switches</h3>
        @foreach(['organiser_enabled' => 'Organiser portal switched on', 'organiser_pay' => 'Organisers can pay online', 'organiser_changes' => 'Change requests accepted', 'organiser_cancel' => 'Cancellation requests accepted', 'organiser_quotes' => 'New-event quote requests accepted', 'organiser_attendees' => 'Guest lists editable in the portal'] as $k => $label)
        <div class="field check"><label><input type="checkbox" name="{{ $k }}" value="1" {{ ($s[$k] ?? '0') === '1' ? 'checked' : '' }}> {{ $label }}</label></div>
        @endforeach
      </div>
      <div>
        <h3>Limits</h3>
        <div class="g-2">
          <div class="field"><label for="organiser_idle">Idle sign-out (minutes)</label><input class="input" type="number" min="5" max="240" id="organiser_idle" name="organiser_idle" value="{{ $s['organiser_idle'] }}"></div>
          <div class="field"><label for="organiser_invite_days">Invitation valid (days)</label><input class="input" type="number" min="1" max="30" id="organiser_invite_days" name="organiser_invite_days" value="{{ $s['organiser_invite_days'] }}"></div>
          <div class="field"><label for="organiser_beo_days">Sign the BEO (days before the event)</label><input class="input" type="number" min="1" max="60" id="organiser_beo_days" name="organiser_beo_days" value="{{ $s['organiser_beo_days'] }}"></div>
        </div>
        <div class="field"><label for="organiser_notify_email">E-mail the events team on new requests / signatures (optional)</label><input class="input" id="organiser_notify_email" type="email" name="organiser_notify_email" value="{{ $s['organiser_notify_email'] }}" maxlength="190"></div>
        <div class="field"><label for="organiser_welcome">Welcome line on the organiser home page</label><input class="input" id="organiser_welcome" name="organiser_welcome" value="{{ $s['organiser_welcome'] }}" maxlength="250"></div>
      </div>
    </div>
    <button class="btn btn-primary" type="submit">Save settings</button>
    <span class="tiny muted">Organisers sign in at {{ $loginUrl }}</span>
  </form>
</div></div>
@endsection
