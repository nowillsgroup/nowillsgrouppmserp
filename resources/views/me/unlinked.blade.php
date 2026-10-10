@extends('layouts.app')
@section('title', 'My Payslips & Leave')
@section('content')
<div class="card"><div class="card-body"><x-empty text="Your login is not linked to an employee record, so there are no payslips or leave to show. Ask HR to set 'Login user' on your employee record." icon="id"/></div></div>
@endsection
