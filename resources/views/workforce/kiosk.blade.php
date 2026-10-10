@extends('layouts.app')
@section('title', 'Time Clock Kiosk')
@section('crumbs') HR &amp; Payroll / Time clock kiosk @endsection
@section('content')
<div class="kiosk-wrap">
  <div class="card"><div class="card-body">
    <div class="clock-big" id="kioskClock">{{ now()->format('H:i:s') }}</div>
    <div class="muted mb-1">{{ now()->format('l, d F Y') }}</div>
    @if($last)<div class="alert {{ $last['action'] === 'in' ? 'alert-success' : 'alert-warn' }}" id="kioskLast"><x-icon name="{{ $last['action'] === 'in' ? 'login' : 'logout' }}"/><div><b>{{ $last['name'] }}</b> clocked {{ $last['action'] }}.</div></div>@endif
    <form method="post" action="{{ route('clock.kiosk.punch') }}" id="kioskForm" autocomplete="off">@csrf
      <div class="field"><label for="k_code">Employee code</label><input type="text" name="code" id="k_code" required autofocus placeholder="EMP001" style="text-transform:uppercase"></div>
      <div class="field"><label for="k_pin">PIN</label><input type="password" name="pin" id="k_pin" required inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" autocomplete="off"></div>
      <button class="btn btn-primary" style="width:100%;font-size:1.15rem;padding:.8rem" id="kioskPunch"><x-icon name="clock"/> Clock in / out</button>
    </form>
    <p class="tiny muted mt-1">The same button clocks you in or out. Five wrong PINs lock your code for 15 minutes. Forgot your PIN? Ask your supervisor or change it in self-service.</p>
  </div></div>
</div>
@endsection
@push('scripts')
<script>
setInterval(() => { const d = new Date(), p = n => String(n).padStart(2, '0'); document.getElementById('kioskClock').textContent = p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds()); }, 1000);
@if(session('success') || session('error'))setTimeout(() => document.querySelectorAll('.alert').forEach(a => a.remove()), 8000);@endif
</script>
@endpush
