@extends('layouts.app')
@section('title', 'Annual ESG Report')
@section('crumbs') Sustainability &amp; ESG / Annual report @endsection
@section('actions')
  <form method="get" class="flex gap-1" style="align-items:center"><select name="year" class="input" onchange="this.form.submit()" aria-label="Year">@foreach($years as $y)<option value="{{ $y }}" @selected($a['year'] == $y)>{{ $y }}</option>@endforeach</select></form>
  <a class="btn btn-primary" href="{{ route('esg.report.print', ['year' => $a['year']]) }}" id="esgReportPrint"><x-icon name="print"/> Print / PDF</a>
@endsection
@section('content')
<div class="card"><div class="card-body">@include('esg._report_body')</div></div>
@endsection
