@extends('layouts.print')
@section('title', 'Test '.$printer->name)
@section('pageclass', 'thermal'.($printer->paper === '58' ? ' w58' : ''))
@section('nohead', true)
@section('content')
<div class="no-print" style="margin-bottom:.6rem">
  <a class="btn btn-sm" href="{{ route('pos.printers') }}">← Printers</a>
  @if($printer->connection === 'network')
    <form method="post" action="{{ route('pos.printers.test.send', $printer) }}" style="display:inline">@csrf<button class="btn btn-sm btn-accent">Send test to {{ $printer->host }}</button></form>
  @endif
</div>
<div class="kot-slip">
  <div class="center"><b style="font-size:15px">PRINTER TEST</b><br>{{ $printer->name }}
    @if($printer->header)<br><b>{{ $printer->header }}</b>@endif
  </div>
  <hr>
  <div>Not a live order.<br>{{ $printer->role === 'captain' ? 'Captain / cashier copy' : ('Station '.($printer->station ?: '—')) }} · {{ $printer->paper }} mm · {{ $printer->copies }} {{ $printer->copies > 1 ? 'copies' : 'copy' }}</div>
  <hr>
  <table style="width:100%;font-size:13px"><tr><td style="width:40px"><b>1</b></td><td><b>Test item</b><br><i>If you can read this, the printer is set up.</i></td></tr></table>
  <hr>
  <div class="center">— {{ auth()->user()->name }} —</div>
</div>
@endsection
