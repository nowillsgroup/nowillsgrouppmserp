@extends('layouts.app')
@section('title', 'CRO Settings')
@section('crumbs') Central Reservations / Settings @endsection
@section('content')
<div class="card" style="max-width:40rem"><div class="card-body"><form method="post" action="{{ route('cro.settings.save') }}" class="form-grid one" id="croSettings">@csrf
  <div class="field"><label>Default follow-up (hours after logging an open enquiry)</label><input type="number" name="follow_up_hours" min="1" max="168" required value="{{ $cfg['follow_up_hours'] }}"></div>
  <div class="field"><label>Open enquiries lapse after (days) – closed as lost, regret “no response”</label><input type="number" name="expire_days" min="1" max="90" required value="{{ $cfg['expire_days'] }}"><span class="tiny muted">Also lapsed once the arrival date passes. Runs daily (pms:cro-expire).</span></div>
  <div class="field"><label>Conversion target (%)</label><input type="number" name="conversion_target" min="1" max="100" step="0.1" required value="{{ $cfg['conversion_target'] }}"><span class="tiny muted">Booked ÷ closed enquiries, per agent.</span></div>
  <div><button class="btn btn-primary">Save settings</button></div>
</form></div></div>
@endsection
