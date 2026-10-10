@extends('layouts.app')
@section('title', $d->exists ? 'Edit '.$d->code : 'New SOP')
@section('crumbs') Learning & SOPs / SOP library / {{ $d->exists ? $d->code : 'New' }} @endsection
@php $S = \App\Models\SopDocument::class; $ackD = old('ack_departments', $d->ack_departments ?? []); @endphp
@section('content')
<form method="post" action="{{ $d->exists ? route('learning.sop.update', $d) : route('learning.sop.store') }}" id="sopForm">@csrf @if($d->exists) @method('PUT') @endif
@if($d->exists && $d->version)<div class="alert alert-info"><x-icon name="edit"/> Saving creates a draft of v{{ $d->version + 1 }}. Staff keep seeing v{{ $d->version }} until you publish.</div>@endif
<div class="g-2-1">
<div class="card"><div class="card-body">
  <div class="form-grid">
    <div class="field"><label for="fCode">Reference code</label><input name="code" id="fCode" maxlength="30" required value="{{ old('code', $d->code) }}" placeholder="SOP-FO-001"></div>
    <div class="field"><label for="fCat">Category</label><select name="category" id="fCat">@foreach($S::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(old('category', $d->category) === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field span-all"><label for="fTitle">Title</label><input name="title" id="fTitle" maxlength="150" required value="{{ old('title', $d->title) }}"></div>
    <div class="field span-all"><label for="fSummary">Purpose / scope (one line)</label><input name="summary" id="fSummary" maxlength="255" value="{{ old('summary', $d->summary) }}"></div>
  </div>
  <div class="field mt-1"><label for="fBody">Procedure <span class="tiny muted">– Markdown: <code>## Heading</code>, <code>1.</code> numbered steps, <code>-</code> bullets, <code>**bold**</code></span></label>
    <textarea name="body" id="fBody" rows="22" maxlength="60000" style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.82rem">{{ old('body', $d->body) }}</textarea></div>
</div></div>
<div class="card"><div class="card-body">
  <div class="field"><label for="fDept">Department</label><select name="department_id" id="fDept"><option value="">Hotel-wide</option>@foreach($depts as $x)<option value="{{ $x->id }}" @selected(old('department_id', $d->department_id) == $x->id)>{{ $x->name }}</option>@endforeach</select></div>
  <div class="field"><label for="fOwner">Owner</label><select name="owner_id" id="fOwner">@foreach($users as $u)<option value="{{ $u->id }}" @selected(old('owner_id', $d->owner_id) == $u->id)>{{ $u->name }}</option>@endforeach</select></div>
  <div class="field"><label for="fReview">Review every (months)</label><input type="number" name="review_months" id="fReview" min="1" max="60" required value="{{ old('review_months', $d->review_months ?? 12) }}"></div>
  <label class="small"><input type="checkbox" name="requires_ack" value="1" id="fAck" @checked(old('requires_ack', $d->requires_ack))> Staff must read and sign it</label>
  <div class="field mt-1"><label>Who signs <span class="tiny muted">(none ticked = the SOP's department; hotel-wide = everyone)</span></label>
    @foreach($depts as $x)<label class="small" style="display:block"><input type="checkbox" name="ack_departments[]" value="{{ $x->id }}" @checked(in_array($x->id, array_map('intval', (array) $ackD)))> {{ $x->name }}</label>@endforeach</div>
  <div class="mt-1 flex gap-1"><button class="btn btn-primary" id="fSave"><x-icon name="check"/> Save draft</button><a class="btn" href="{{ $d->exists ? route('learning.sop', $d) : route('learning.sops') }}">Cancel</a></div>
</div></div>
</div>
</form>
@endsection
