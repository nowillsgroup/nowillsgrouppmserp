@extends('layouts.app')
@section('title', 'Robita Demo Settings')
@section('crumbs') Interfaces / Robita Locks / Settings @endsection
@section('actions')
  <a class="btn" href="{{ route('robita.index') }}"><x-icon name="grid"/> Dashboard</a>
  <a class="btn" href="{{ route('robita.devices') }}"><x-icon name="lock"/> Devices</a>
  <a class="btn" href="{{ route('robita.events') }}"><x-icon name="clock"/> Events</a>
@endsection
@section('content')

<div class="alert alert-warning" style="margin-bottom:.8rem">
  <x-icon name="alert"/>
  <div><b>Local demo only.</b> Journey automation updates PMS records and simulated events. No Robita cloud calls, polling, credential delivery, or physical lock actuation are enabled. Vendor protocol documentation and hardware acceptance are required before integration.</div>
</div>
@if($cfg['legacy_cloud_configured'])
  <div class="alert alert-warning" style="margin-bottom:.8rem">
    <x-icon name="alert"/>
    <div>
      A legacy cloud URL/access token is saved but ignored. No requests are sent to it. You can explicitly remove these obsolete values here.
      <form method="post" action="{{ route('robita.settings.forget_legacy_cloud') }}" style="margin-top:.5rem" data-confirm="Remove the ignored legacy Robita cloud URL and access token for this hotel? No vendor request will be sent.">@csrf<button class="btn btn-sm btn-danger"><x-icon name="x" size="sm"/> Forget legacy URL &amp; token</button></form>
    </div>
  </div>
@endif

<form method="post" action="{{ route('robita.settings.store') }}">@csrf
  <div class="card">
    <div class="card-head"><h3><x-icon name="tool" size="sm"/> Demo Workflow</h3></div>
    <div class="card-body">
      <div class="form-grid">
        <div>
          <label>Default Device Model Label</label>
          <input class="input" name="robita_default_model" value="{{ $cfg['robita_default_model'] }}" maxlength="40" placeholder="Robita S1">
          <span class="hint">Display label only. It does not identify or provision hardware.</span>
        </div>
        <div>
          <label>Low Battery Alert Threshold</label>
          <input class="input" type="number" name="robita_battery_alert" value="{{ $cfg['robita_battery_alert'] }}" min="0" max="100">
          <span class="hint">Used only for normalized event data; demo records do not invent a battery level.</span>
        </div>
        <div>
          <label>Guest Journey Automation</label>
          <label class="flex" style="gap:.4rem;align-items:center"><input type="checkbox" name="robita_auto_issue" value="1" @checked($cfg['robita_auto_issue'] === '1')> Create local credential records at check-in and room move</label>
          <label class="flex" style="gap:.4rem;align-items:center"><input type="checkbox" name="robita_auto_revoke" value="1" @checked($cfg['robita_auto_revoke'] === '1')> Cancel local records at checkout, room move and expiry</label>
          <span class="hint">Extensions update local expiry records. None of these actions are sent to a lock.</span>
        </div>
      </div>
    </div>
  </div>

  <div class="flex" style="justify-content:flex-end;gap:.4rem;margin-top:.8rem">
    <a class="btn" href="{{ route('robita.index') }}">Cancel</a>
    <button class="btn btn-primary"><x-icon name="check"/> Save Settings</button>
  </div>
</form>

<div class="card">
  <div class="card-head"><h3><x-icon name="link" size="sm"/> Normalized Event Intake</h3></div>
  <div class="card-body">
    <div class="alert alert-info">
      <x-icon name="alert"/>
      <div>This endpoint is for a future trusted bridge using the PMS normalized schema. It is not a Robita vendor webhook implementation; do not configure a lock or vendor cloud against it without protocol review.</div>
    </div>
    <dl class="kv" style="margin-top:.7rem">
      <dt>Method</dt><dd><span class="badge badge-slate">POST</span></dd>
      <dt>Endpoint</dt><dd class="mono">{{ url('/api/robita/events') }}</dd>
      <dt>Authentication</dt><dd>Authorization: Bearer &lt;token&gt;</dd>
      <dt>Accepted events</dt><dd>Normalized JSON only; see <code>docs/ROBITA-LOCK-INTEGRATION.md</code>.</dd>
    </dl>
    <label style="display:block;margin-top:.7rem">Per-hotel bearer token</label>
    <input class="input mono" type="password" id="robitaWebhookToken" readonly value="{{ $cfg['robita_webhook_token'] }}" autocomplete="off">
    <div class="flex" style="gap:.4rem;margin-top:.5rem">
      <button type="button" class="btn btn-sm" onclick="const i=document.getElementById('robitaWebhookToken');i.type=i.type==='password'?'text':'password';"><x-icon name="eye" size="sm"/> Show / hide</button>
      <button type="button" class="btn btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('robitaWebhookToken').value)"><x-icon name="link" size="sm"/> Copy token</button>
      <form method="post" action="{{ route('robita.tokens') }}" data-confirm="Regenerate the bearer token? Existing bridge clients will immediately lose access.">@csrf<button class="btn btn-sm btn-danger"><x-icon name="refresh" size="sm"/> Regenerate</button></form>
    </div>
  </div>
</div>
@endsection
