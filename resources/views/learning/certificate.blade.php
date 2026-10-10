@extends('layouts.print')
@section('title', 'Certificate '.$r->certificate_no)
@section('nohead', '1')
@section('content')
<div class="cert" id="certificate">
  <div class="cert-hotel">{{ $currentHotel->name }}</div>
  <div class="cert-title">Certificate of Training</div>
  <div class="cert-sub">This is to certify that</div>
  <div class="cert-name" id="certName">{{ $r->employee->name }}</div>
  <div class="cert-sub">{{ $r->employee->designation }}{{ $r->employee->department ? ' · '.$r->employee->department->name : '' }}</div>
  <div class="cert-sub" style="margin-top:1rem">has successfully completed</div>
  <div class="cert-course" id="certCourse">{{ $r->course->name }}</div>
  <div class="cert-sub">{{ rtrim(rtrim((string) $r->course->duration_hours, '0'), '.') }} hour(s) · {{ $r->course->categoryLabel() }}{{ $r->score !== null ? ' · score '.rtrim(rtrim((string) $r->score, '0'), '.').'%' : '' }}</div>
  <div class="cert-grid">
    <div><span>Completed</span><b>{{ $r->completed_on->format('d F Y') }}</b></div>
    <div><span>Valid until</span><b id="certExpiry">{{ $r->expires_on ? $r->expires_on->format('d F Y') : 'No expiry' }}</b></div>
    <div><span>Certificate no.</span><b>{{ $r->certificate_no ?: '—' }}</b></div>
    <div><span>Trainer</span><b>{{ $r->session?->trainerLabel() ?? ($r->provider ?: '—') }}</b></div>
  </div>
  <div class="cert-sign"><div>______________________<br><span>Trainer</span></div><div>______________________<br><span>General Manager / HR</span></div></div>
</div>
@endsection
