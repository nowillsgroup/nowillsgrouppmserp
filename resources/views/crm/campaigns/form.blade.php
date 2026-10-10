@extends('layouts.app')
@section('title', $campaign->exists ? 'Edit Campaign' : 'New Campaign')
@section('crumbs') <a href="{{ route('crm.index') }}">Marketing</a> / <a href="{{ route('crm.campaigns.index') }}">Campaigns</a> / {{ $campaign->exists ? $campaign->name : 'New' }} @endsection
@section('content')
<form method="post" action="{{ $campaign->exists ? route('crm.campaigns.update', $campaign) : route('crm.campaigns.store') }}" id="campForm">@csrf @if($campaign->exists) @method('PUT') @endif
<div class="grid g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>Campaign</h3>@if($campaign->exists){!! status_badge($campaign->status) !!}@endif</div><div class="card-body form-grid">
      <x-field name="name" label="Campaign name" :value="$campaign->name" required placeholder="e.g. Harvest weekend escape" class="span-all"/>
      <x-field name="segment_id" label="Audience" type="select" :options="$segments" :value="$campaign->segment_id" placeholder="All opted-in guests" class="span-all"
        hint="Only guests who opted in on the chosen channel receive it; blacklisted guests, missing addresses and the weekly frequency cap are skipped automatically."/>
    </div></div>
    @include('crm._composer', ['model' => $campaign])
  </div>
  <div class="card sticky-side"><div class="card-head"><h3><x-icon name="eye"/> Preview</h3><span class="tiny muted">{{ $sample ? 'as '.$sample->first_name : '' }}</span></div><div class="card-body">
    <div class="bold mb-1" id="pvSubject"></div>
    <div id="pvBody" class="small" style="border:1px solid var(--c-border);border-radius:10px;padding:.8rem;background:#fff;min-height:120px"></div>
    <div id="pvMeta" class="tiny muted mt-1"></div>
  </div></div>
</div>
<div class="flex" style="justify-content:flex-end;gap:.5rem">
  <a class="btn btn-ghost" href="{{ $campaign->exists ? route('crm.campaigns.show', $campaign) : route('crm.campaigns.index') }}">Cancel</a>
  <button class="btn btn-primary" id="saveCampaign"><x-icon name="check"/> Save draft</button>
</div>
</form>
@endsection
