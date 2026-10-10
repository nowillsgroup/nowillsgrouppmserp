@extends('layouts.print')
@section('title', $d->code.' '.($v?->title ?? $d->title))
@section('doctitle', 'STANDARD OPERATING PROCEDURE')
@section('docmeta')<div class="small"><b>{{ $d->code }}</b> · {{ $v ? 'version '.$v->version : 'DRAFT' }}</div><div class="small">{{ $d->department?->name ?? 'Hotel-wide' }} · {{ $d->categoryLabel() }}</div>@if($v)<div class="small">Effective {{ $v->effective_date->format('d M Y') }}</div>@endif @endsection
@section('content')
<h2 class="mt-1" style="margin-bottom:.2rem">{{ $v?->title ?? $d->title }}</h2>
@if($d->summary)<p class="small"><i>{{ $d->summary }}</i></p>@endif
<div class="sop-body" id="sopPrintBody">{!! \App\Models\SopDocument::render($v?->body ?? $d->body) !!}</div>
<table class="table table-compact mt-1 small"><tr><td><b>Owner</b> {{ $d->owner?->name }}</td><td><b>Next review</b> {{ $d->review_due?->format('d M Y') ?? '—' }}</td><td><b>Change</b> {{ $v?->change_note ?? 'draft' }}</td></tr></table>
<p class="tiny muted">Printed {{ now()->format('d M Y H:i') }}. Uncontrolled when printed – check the SOP library for the current version.</p>
@endsection
