@extends('layouts.app')
@section('title', 'Supplier Statement – '.$supplier->name)
@section('crumbs') <a href="{{ route('ap.ageing') }}">AP Ageing</a> / {{ $supplier->name }} @endsection
@section('actions')
<form method="get" class="flex gap-1 items-center"><input type="date" name="from" value="{{ $from }}" class="input input-sm" style="width:auto"> – <input type="date" name="to" value="{{ $to }}" class="input input-sm" style="width:auto"><button class="btn btn-sm">Go</button></form>
<a class="btn" target="_blank" href="{{ route('ap.statement', [$supplier, 'from' => $from, 'to' => $to, 'print' => 1]) }}"><x-icon name="print"/> Print</a>
@if(can_do('ap.pay'))<a class="btn btn-primary" href="{{ route('ap.payments.create', ['supplier' => $supplier->id]) }}"><x-icon name="money"/> Pay</a>@endif
@endsection
@section('content')
@include('ap.partials.statement-body')
@endsection
