@php $ga = \App\Services\GstService::alerts(); @endphp
<div class="tabs" id="gstNav">
  <a href="{{ route('gst.index') }}" class="{{ request()->routeIs('gst.index') ? 'active' : '' }}">Overview</a>
  <a href="{{ route('gst.einvoices') }}" class="{{ request()->routeIs('gst.einvoices') ? 'active' : '' }}">E-VAT @if($ga['failed'] + $ga['pending'])<span class="badge badge-red">{{ $ga['failed'] + $ga['pending'] }}</span>@endif</a>
  <a href="{{ route('gst.credit_notes') }}" class="{{ request()->routeIs('gst.credit_notes') ? 'active' : '' }}">Credit notes &amp; cancellations</a>
  <a href="{{ route('gst.return') }}" class="{{ request()->routeIs('gst.return') ? 'active' : '' }}">VAT return @if($ga['return_period'])<span class="badge {{ $ga['return_overdue'] ? 'badge-red' : 'badge-amber' }}">{{ \Illuminate\Support\Carbon::parse($ga['return_period'].'-01')->format('M') }}</span>@endif</a>
  @if(can_do('gst.manage'))<a href="{{ route('gst.settings') }}" class="{{ request()->routeIs('gst.settings') ? 'active' : '' }}">Settings</a>@endif
</div>
