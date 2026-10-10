@extends('layouts.print')
@section('title', 'Notice of accident '.$a->number)
@section('doctitle', $a->kind === 'dangerous' ? 'NOTICE OF DANGEROUS OCCURRENCE' : 'NOTICE OF ACCIDENT')
@section('docmeta')<div class="small"><b>{{ $a->number }}</b></div><div class="small">Factories, Offices and Shops Act, 1970 (Act 328), s. {{ $a->kind === 'dangerous' ? '11' : '10' }}</div>@endsection
@section('content')
<style>.ntc{width:100%;border-collapse:collapse;margin-top:.8rem;font-size:.9rem}.ntc td,.ntc th{border:1px solid #cbd5e1;padding:.45rem .6rem;text-align:left;vertical-align:top}.ntc th{background:#f1f5f9;width:32%}</style>
<p class="small">To: <b>The Chief Inspector / District Inspector – {{ $cfg['inspectorate'] }}</b></p>
<table class="ntc" id="hseNotice">
  <tr><th>Occupier</th><td>{{ hotel()->name }}{{ hotel()->address ? ', '.hotel()->address : '' }}</td></tr>
  <tr><th>Date and time</th><td>{{ $a->occurred_at->format('l d F Y, H:i') }}</td></tr>
  <tr><th>Place on the premises</th><td>{{ $a->location }}</td></tr>
  @if($a->kind === 'dangerous')<tr><th>Dangerous occurrence</th><td>{{ \App\Models\HseAccident::OCCURRENCES[$a->occurrence] ?? '' }}</td></tr>@endif
  @if($a->person_type)<tr><th>Person injured</th><td>{{ $a->personLabel() }} – {{ \App\Models\HseAccident::PERSONS[$a->person_type] }}{{ $a->employee ? ' · '.($a->employee->designation ?? '').($a->employee->department ? ', '.$a->employee->department->name : '') : '' }}</td></tr>
  <tr><th>Nature of injury</th><td>{{ $a->injury_type ? \App\Models\HseAccident::INJURIES[$a->injury_type] : '—' }}{{ $a->body_part ? ' – '.\App\Models\HseAccident::BODY[$a->body_part] : '' }}</td></tr>
  <tr><th>Outcome</th><td>{{ $a->severityLabel() }}{{ $a->lost_days ? ' – off work '.$a->lost_days.' day(s) so far' : '' }}{{ $a->hospital ? ' · treated at '.$a->hospital : '' }}</td></tr>@endif
  <tr><th>How it happened</th><td style="white-space:pre-line">{{ $a->description }}</td></tr>
  <tr><th>Immediate action</th><td>{{ $a->first_aid ?? '—' }}</td></tr>
  <tr><th>Witnesses</th><td>{{ $a->witnesses ?? '—' }}</td></tr>
</table>
<p class="small mt-1">Submitted on behalf of the occupier. Signature: ______________________ &nbsp; Name / position: ______________________ &nbsp; Date: {{ now()->format('d M Y') }}</p>
@endsection
