@extends('layouts.app')
@section('title', $title ?? 'My HR')
@section('content')
<div class="card"><div class="card-body"><x-empty text="Your login is not linked to an employee record, so there is nothing to show here yet. Ask HR to set 'Login user' on your employee record (Masters › Employees) – your payslips, leave, roster, time clock and training all hang off that link." icon="id"/></div></div>
@endsection
