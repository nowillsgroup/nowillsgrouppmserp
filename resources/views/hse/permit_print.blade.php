@extends('layouts.print')
@section('title', 'Permit to work '.$p->number)
@section('doctitle', 'PERMIT TO WORK')
@section('docmeta')<div class="small"><b>{{ $p->number }}</b></div><div class="small">{{ $p->typeLabel() }}</div>@endsection
@section('content')
<style>.ptw{width:100%;border-collapse:collapse;margin-top:.8rem;font-size:.88rem}.ptw td,.ptw th{border:1px solid #cbd5e1;padding:.4rem .55rem;text-align:left;vertical-align:top}.ptw th{background:#f1f5f9;width:28%}
.ptw-sig{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-top:1.4rem;font-size:.82rem}.ptw-sig div{border-top:1px solid #334155;padding-top:.3rem}.ptw-box{font-size:1.05rem}</style>
<table class="ptw" id="hsePermitPrint">
  <tr><th>Status</th><td><b>{{ strtoupper($p->statusLabel()) }}</b></td></tr>
  <tr><th>Location</th><td>{{ $p->location }}</td></tr>
  <tr><th>Work</th><td>{{ $p->work }}</td></tr>
  <tr><th>Contractor / team</th><td>{{ $p->contractor ?? 'In-house' }} · {{ $p->workers }} worker(s)</td></tr>
  <tr><th>Valid</th><td>{{ $p->starts_at->format('D d M Y H:i') }} to {{ $p->ends_at->format('D d M Y H:i') }} only</td></tr>
  <tr><th>Precautions</th><td>@foreach($p->precautionList() as $k => $l)<div><span class="ptw-box">{{ in_array($k, $p->precautions ?? [], true) ? '☑' : '☐' }}</span> {{ $l }}</div>@endforeach</td></tr>
  @if($p->type === 'hot_work')<tr><th>Fire watch</th><td>{{ $p->fire_watch ?? '________________' }} – during the work and for 60 minutes after.</td></tr>
  <tr><th>Detectors isolated</th><td>{{ $p->detectors_isolated ?? 'None' }}</td></tr>@endif
  @if($p->type === 'confined')<tr><th>Gas test</th><td>O₂ {{ $p->gas_o2 !== null ? number_format($p->gas_o2, 1).' %' : '______ %' }} (safe 19.5–23.5 %)</td></tr>@endif
  @if($p->type === 'electrical')<tr><th>Isolation points</th><td>{{ $p->isolation_points ?? '' }}</td></tr>@endif
</table>
<div class="ptw-sig">
  <div>Requested by<br><b>{{ $p->requester?->name }}</b><br>{{ $p->created_at->format('d M H:i') }}</div>
  <div>Issued by (authorised)<br><b>{{ $p->issuer?->name ?? '' }}</b><br>{{ $p->issued_at?->format('d M H:i') }}</div>
  <div>Person in charge of the work – I have read and understood the precautions<br><br></div>
</div>
<div class="ptw-sig">
  <div>Work complete – area safe<br><br></div><div>Closed out by<br><b>{{ $p->closer?->name ?? '' }}</b><br>{{ $p->closed_at?->format('d M H:i') }}</div><div>Keep this permit displayed at the work point.</div>
</div>
@endsection
