@extends('public.layout')
@section('title', 'Request confirmed')
@section('content')
<div class="card" style="max-width:640px;margin:0 auto" id="confirmedCard"><div class="card-body center">
  <div style="font-size:2.4rem;color:#16a34a">✓</div>
  <h3>Thank you – your request is confirmed</h3>
  <p>Reference <b>{{ $req->request_no }}</b> ({{ strtolower(\App\Models\PrivacyRequest::SHORT[$req->type]) }}).</p>
  <p class="small">We will reply by <b>{{ $req->due_on->format('d M Y') }}</b>. @if($req->needsIdentity())Before sharing or deleting data we may contact you to confirm your identity.@endif</p>
</div></div>
@endsection
