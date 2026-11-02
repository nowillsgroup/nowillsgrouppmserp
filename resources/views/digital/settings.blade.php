@extends('layouts.app')
@section('title', 'Digital Marketing Settings')
@section('crumbs') Digital Marketing / Settings @endsection
@section('content')
@include('digital._workspace-nav')
<div class="card" style="max-width:54rem" id="dmSettingsCard">
  <div class="card-head">
    <div><h3><x-icon name="tool"/> Workflow controls</h3><p class="tiny muted mt-1">These settings apply to {{ hotel()->name }} only.</p></div>
    <span class="badge badge-slate">PMS workflow</span>
  </div>
  <div class="card-body">
    <form method="post" action="{{ route('digital.settings.save') }}" id="dmSettings">@csrf
      <div class="form-grid">
        <div class="field span-all">
          <label class="dm-check" for="requireApproval"><input type="checkbox" id="requireApproval" name="require_approval" value="1" @checked($cfg['require_approval'])> Require a second-person approval for social posts</label>
          <span class="tiny muted">When enabled, submitted posts wait for an approver who is not the author. When disabled, submission moves straight to Approved.</span>
        </div>
        <div class="alert alert-info span-all"><x-icon name="alert"/><div><b>Approval is not publishing.</b> Turning this off only skips the review step. Publishing still requires a separate action; configured Facebook or WhatsApp channels may attempt live delivery, while other platforms are recorded for manual posting.</div></div>
        <div class="field">
          <label for="attributionDays">Tracked-link attribution window (days)</label>
          <input type="number" id="attributionDays" name="attribution_days" min="1" max="90" required value="{{ $cfg['attribution_days'] }}">
          <span class="tiny muted">How long the PMS retains a tracked-link code for booking attribution. Allowed: 1–90 days.</span>
        </div>
        <div class="field">
          <label for="auditDays">Listing audit interval (days)</label>
          <input type="number" id="auditDays" name="audit_days" min="7" max="365" required value="{{ $cfg['audit_days'] }}">
          <span class="tiny muted">Active listing records are flagged after this many days without a staff-recorded audit. This does not fetch data from listing sites.</span>
        </div>
      </div>
      <div class="flex gap-1 wrap mt-1">
        <button type="submit" class="btn btn-primary">Save settings</button>
        <span class="tiny muted" style="align-self:center">Changes take effect immediately for this property.</span>
      </div>
    </form>
  </div>
</div>
@endsection
