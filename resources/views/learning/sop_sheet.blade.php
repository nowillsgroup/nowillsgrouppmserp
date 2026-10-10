@extends('layouts.print')
@section('title', 'Sign-off '.$d->code)
@section('doctitle', 'SOP SIGN-OFF SHEET')
@section('docmeta')<div class="small"><b>{{ $d->code }}</b> · version {{ $d->version }}</div><div class="small">{{ $d->title }}</div>@endsection
@section('content')
<p class="small mt-1">By signing below I confirm that I have read, understood and will follow <b>{{ $d->code }} {{ $d->title }}</b> (version {{ $d->version }}, effective {{ $d->effective_date?->format('d M Y') }}).</p>
<table class="table table-compact mt-1" id="sheetTable">
  <thead><tr><th>#</th><th>Employee</th><th>Job title</th><th>Department</th><th>Already signed</th><th style="width:7rem">Date</th><th style="width:11rem">Signature</th></tr></thead>
  <tbody>@foreach($ack['rows']->sortBy('employee.name')->values() as $i => $r)
    <tr style="height:2.1rem"><td>{{ $i + 1 }}</td><td>{{ $r['employee']->name }}</td><td class="small">{{ $r['employee']->designation }}</td><td class="small">{{ $r['employee']->department?->name }}</td>
      <td class="small">{{ $r['ack'] ? 'v'.$r['ack']->version.' '.$r['ack']->acknowledged_at->format('d M y') : '' }}</td><td></td><td></td></tr>
  @endforeach</tbody>
</table>
<div class="small" style="margin-top:2rem">Supervisor: ______________________ &nbsp; Date: ____________</div>
@endsection
