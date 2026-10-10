@extends('layouts.app')
@section('title', $breach->exists ? 'Edit '.$breach->ref : 'Record a personal-data breach')
@section('crumbs') <a href="{{ route('privacy.breaches.index') }}">Breaches</a> / {{ $breach->exists ? $breach->ref : 'New' }} @endsection
@section('content')
<form method="post" action="{{ $breach->exists ? route('privacy.breaches.update', $breach) : route('privacy.breaches.store') }}" class="card" id="breachForm" style="max-width:980px">@csrf @if($breach->exists) @method('PUT') @endif
  <div class="card-body form-grid">
    <x-field name="title" label="Short title" :value="$breach->title" required class="span-2" placeholder="e.g. Arrival list e-mailed to the wrong travel agent"/>
    <x-field name="severity" label="Severity" type="select" :options="\App\Models\DataBreach::SEVERITIES" :value="$breach->severity" required :placeholder="false"/>
    <x-field name="detected_at" label="Detected at" type="datetime-local" :value="$breach->detected_at?->format('Y-m-d\TH:i')" required/>
    <x-field name="occurred_at" label="Occurred at (if known)" type="datetime-local" :value="$breach->occurred_at?->format('Y-m-d\TH:i')"/>
    <x-field name="affected_count" label="People affected (estimate)" type="number" :value="$breach->affected_count" min="0" required/>
    <x-field name="description" label="What happened" type="textarea" :value="$breach->description" required class="span-all" rows="4"/>
    <div class="field span-all"><label>Data involved</label><div class="seg">
      @foreach(\App\Models\DataBreach::CATEGORIES as $k => $l)<label class="check"><input type="checkbox" name="categories[]" value="{{ $k }}" @checked(in_array($k, old('categories', $breach->categoryList())))> {{ $l }}</label>@endforeach</div></div>
    <label class="check span-all"><input type="checkbox" name="notifiable" value="1" id="f_notifiable" @checked(old('notifiable', $breach->notifiable))> Notifiable – report to the Data Protection Commission and inform the people affected</label>
    <x-field name="not_notifiable_reason" label="If not notifiable, why?" :value="$breach->not_notifiable_reason" class="span-all" placeholder="e.g. Encrypted laptop, key not compromised – no risk to individuals"/>
  </div>
  <div class="card-foot"><button class="btn btn-primary" id="saveBreach"><x-icon name="check"/> Save</button> <a class="btn" href="{{ route('privacy.breaches.index') }}">Cancel</a></div>
</form>
@endsection
