@extends('layouts.app')
@section('title', 'Robita Lock Settings')
@section('crumbs') Interfaces / Robita Locks / Settings @endsection
@section('actions')
  <a class="btn" href="{{ route('robita.index') }}"><x-icon name="grid"/> Dashboard</a>
  <a class="btn" href="{{ route('robita.devices') }}"><x-icon name="lock"/> Devices</a>
  <a class="btn" href="{{ route('robita.events') }}"><x-icon name="clock"/> Events</a>
@endsection
@section('content')

<form method="post" action="{{ route('robita.settings.store') }}">@csrf
  <div class="card">
    <div class="card-head"><h3><x-icon name="link" size="sm"/> Cloud Connection</h3></div>
    <div class="card-body">
      <div class="form-grid">
        <div style="grid-column:1/-1">
          <label>Robita Cloud URL</label>
          <input class="input" name="robita_cloud_url" value="{{ $cfg['robita_cloud_url'] }}" placeholder="https://api.robita.example.com">
          <span class="hint">Leave empty for demo mode — commands acknowledged locally, no cloud calls.</span>
        </div>
        <div>
          <label>Access Token</label>
          <input class="input" name="robita_access_token" value="{{ $cfg['robita_access_token'] }}" placeholder="40-character token">
          <span class="hint">Generated when cloud URL is first set. Store securely.</span>
        </div>
        <div>
          <label>Default Device Model</label>
          <input class="input" name="robita_default_model" value="{{ $cfg['robita_default_model'] }}" placeholder="Robita S1">
        </div>
      </div>
      <div class="form-grid" style="margin-top:.6rem">
        <div>
          <label>Event Poll Interval (minutes)</label>
          <input class="input" type="number" name="robita_poll_minutes" value="{{ $cfg['robita_poll_minutes'] }}" min="0" max="60">
          <span class="hint">0 = webhook only, no polling.</span>
        </div>
        <div>
          <label>Low Battery Alert Threshold</label>
          <input class="input" type="number" name="robita_battery_alert" value="{{ $cfg['robita_battery_alert'] }}" min="0" max="100">
          <span class="hint">Alert fires when battery drops below this percentage.</span>
        </div>
        <div>
          <label>Auto-Register Devices</label>
          <label class="flex" style="gap:.4rem;align-items:center"><input type="checkbox" name="robita_auto_register" value="1" @checked($cfg['robita_auto_register'] === '1')> Auto-provision devices on first credential</label>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3><x-icon name="link" size="sm"/> Webhook &amp; Polling</h3></div>
    <div class="card-body">
      <div class="alert alert-info">
        <x-icon name="link"/>
        <div>Configure the Robita cloud to push events to the PMS at:</div>
        <input class="input mono" readonly value="{{ url('/api/robita/'.$cfg['robita_webhook_token']) }}" style="margin-top:.4rem" onclick="this.select()">
      </div>
      <div class="form-grid" style="margin-top:.6rem">
        <div>
          <label>Webhook Token</label>
          <input class="input" name="robita_webhook_token" value="{{ $cfg['robita_webhook_token'] }}" placeholder="40-character token">
          <span class="hint">Appears in the webhook URL above. Regenerate to block old pushes.</span>
        </div>
        <div style="display:flex;align-items:flex-end">
          <button type="button" class="btn btn-primary" data-confirm="Regenerate access and webhook tokens? Old tokens stop working immediately." onclick="event.preventDefault();if(confirm('Regenerate access and webhook tokens? Old tokens stop working immediately.')){document.getElementById('regenTokens').submit();}"><x-icon name="refresh"/> Regenerate Tokens</button>
        </div>
      </div>
    </div>
  </div>

  <div class="flex" style="justify-content:flex-end;gap:.4rem;margin-top:.8rem">
    <a class="btn" href="{{ route('robita.index') }}">Cancel</a>
    <button class="btn btn-primary"><x-icon name="check"/> Save Settings</button>
  </div>
</form>

<form id="regenTokens" method="post" action="{{ route('robita.tokens') }}" style="display:none">@csrf</form>
@endsection
