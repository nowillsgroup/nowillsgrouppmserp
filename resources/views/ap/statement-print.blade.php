@extends('layouts.print')
@section('title', 'Statement '.$supplier->name)
@section('doctitle', 'SUPPLIER STATEMENT')
@section('docmeta')<div class="small"><b>{{ $supplier->name }}</b></div><div class="small">{{ dmy($from) }} – {{ dmy($to) }}</div>@endsection
@section('content')
@include('ap.partials.statement-body', ['print' => true])
@endsection
