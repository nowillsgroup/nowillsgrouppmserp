@extends('portal.layout')
@section('title', 'Visitors')
@section('heading', 'Visitors')
@section('sub', 'Expecting someone? Register them and share their pass – they skip the questions at the desk.')
@section('content')
<style>
.st.expected{background:#e0e7ff;color:#3730a3}.st.awaiting{background:#fef3c7;color:#92400e}.st.in{background:#dcfce7;color:#166534}.st.out{background:#f1f5f9;color:#475569}
.gp-pass{background:#f8fafc;border:1px dashed #94a3b8;border-radius:12px;padding:.55rem .7rem;margin-top:.45rem;font-size:.82rem;word-break:break-all}
</style>

@if($list->isNotEmpty())
<div class="gp-card" id="myVisitors"><h3>My visitors</h3>
@foreach($list as $v)
  <div class="gp-row" data-visitor="{{ $v->pass_no }}"><div style="flex:1">
    <b>{{ $v->name }}</b>@if($v->persons > 1) <span class="tiny muted">+{{ $v->persons - 1 }}</span>@endif
    <div class="tiny muted">{{ $v->status === 'expected' ? 'Expected '.$v->expected_at?->format('D d M, H:i') : ($v->status === 'in' ? 'Arrived '.$v->checked_in_at?->format('H:i').' · pass until '.$v->valid_until?->format('H:i') : ($v->status === 'awaiting' ? 'At the front desk now – we will call you' : 'Left '.$v->checked_out_at?->format('D H:i'))) }}</div>
    @if($v->status === 'expected' && $v->pre_token)
      <div class="gp-pass">Visitor pass <b>{{ $v->pass_no }}</b> – share this link:<br><a href="{{ route('visitor.pass', $v->pre_token) }}" class="pass-link" @if(session('new_pass') === $v->id) id="newPassLink" @endif>{{ route('visitor.pass', $v->pre_token) }}</a></div>
      <form method="post" action="{{ route('portal.visitors.cancel', [$token, $v->id]) }}" class="mt-1" onsubmit="return confirm('Remove this visitor?')">@csrf<button class="btn btn-sm">Remove</button></form>
    @endif
  </div><span class="st {{ $v->status }}">{{ $v->statusLabel() }}</span></div>
@endforeach
</div>
@endif

<div class="gp-card" id="registerVisitor"><h3>Register a visitor</h3>
<form method="post" action="{{ route('portal.visitors.store', $token) }}" id="visitorForm">@csrf
  <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
  <div class="field"><label for="pv_name">Visitor's name</label><input id="pv_name" name="name" class="input" required maxlength="120" value="{{ old('name') }}"></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
    <div class="field"><label for="pv_date">Date</label><input type="date" id="pv_date" name="date" class="input" required min="{{ max(now()->toDateString(), $stay->arrival_date->toDateString()) }}" max="{{ $stay->departure_date->toDateString() }}" value="{{ old('date', max(now()->toDateString(), $stay->arrival_date->toDateString())) }}"></div>
    <div class="field"><label for="pv_time">Around</label><input type="time" id="pv_time" name="time" class="input" required value="{{ old('time', now()->addHour()->format('H:00')) }}"></div>
    <div class="field"><label for="pv_phone">Their mobile</label><input id="pv_phone" name="phone" class="input" maxlength="30" value="{{ old('phone') }}"></div>
    <div class="field"><label for="pv_persons">People</label><select id="pv_persons" name="persons" class="input">@for($n = 1; $n <= 10; $n++)<option @selected(old('persons', 1) == $n)>{{ $n }}</option>@endfor</select></div>
  </div>
  <div class="field"><label for="pv_notes">Note for the desk (optional)</label><input id="pv_notes" name="notes" class="input" maxlength="300" placeholder="Please send them up / I'll meet them in the lobby" value="{{ old('notes') }}"></div>
  <button class="btn btn-primary btn-block" id="sendVisitor">Register visitor</button>
  <p class="tiny muted mt-1 m-0">For everyone's safety your visitor shows a photo ID at the desk. Visiting hours end at {{ $cfg['visiting_until'] }}.</p>
</form>
</div>

<div class="gp-card" id="visitorPrivacy"><h3>Privacy</h3>
<form method="post" action="{{ route('portal.visitors.policy', $token) }}">@csrf
  @foreach($policies as $k => $l)<label class="gp-chip" style="display:flex;margin-bottom:.35rem"><input type="radio" name="policy" value="{{ $k }}" @checked($stay->visitor_policy === $k)> {{ $l }}</label>@endforeach
  <button class="btn btn-block mt-1" id="savePolicy">Save preference</button>
  <p class="tiny muted mt-1 m-0">With "No visitors" the desk will not confirm to callers or visitors that you are staying with us.</p>
</form>
</div>
@endsection
