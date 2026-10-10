@extends('layouts.print')
@section('title', 'Statement '.$supplier->name)
@section('doctitle', 'SUPPLIER STATEMENT')
@section('docmeta')<div class="small"><b>{{ $supplier->name }}</b></div><div class="small">{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} – {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</div>@endsection
@section('content')
@include('ap.partials.statement-body', ['print' => true])
@endsection
