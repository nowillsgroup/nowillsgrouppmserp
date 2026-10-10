@extends('layouts.print')
@section('title', 'ESG report '.$a['year'])
@section('doctitle', 'ESG REPORT '.$a['year'])
@section('docmeta')<div class="small">Environment · Social · Governance</div><div class="small">Prepared {{ bdate()->format('d M Y') }}</div>@endsection
@section('content')
@include('esg._report_body')
@endsection
