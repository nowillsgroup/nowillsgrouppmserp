@extends('layouts.app')
@section('title', 'L&D Settings')
@section('crumbs') Learning & SOPs / Settings @endsection
@section('content')
<div class="card" style="max-width:44rem"><div class="card-body"><form method="post" action="{{ route('learning.settings.save') }}" class="form-grid" id="ldSettings">@csrf
  <div class="field"><label for="warnDays">Flag certificates expiring within (days)</label><input type="number" name="expiry_warn_days" id="warnDays" min="0" max="365" required value="{{ $cfg['expiry_warn_days'] }}"></div>
  <div class="field"><label for="reviewDays">Flag SOP reviews due within (days)</label><input type="number" name="review_warn_days" id="reviewDays" min="0" max="365" required value="{{ $cfg['review_warn_days'] }}"></div>
  <div class="field span-all"><label for="venue">Default training venue</label><input name="venue" id="venue" maxlength="120" value="{{ $cfg['venue'] }}"></div>
  <label class="span-all small"><input type="checkbox" name="self_ack" value="1" @checked($cfg['self_ack'] === '1')> Staff can read and sign SOPs online on their self-service page (otherwise paper sign-off sheets only)</label>
  <label class="span-all small"><input type="checkbox" name="notify" value="1" @checked($cfg['notify'] === '1')> Text staff when they are enrolled on, removed from or a session is cancelled</label>
  <div class="span-all"><button class="btn btn-primary" id="saveSettings">Save settings</button></div>
</form></div></div>
@endsection
