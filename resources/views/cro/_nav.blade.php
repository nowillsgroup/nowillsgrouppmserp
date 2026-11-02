@php
  $croDesk = request()->routeIs('cro.index');
  $croEnquiries = request()->routeIs('cro.enquiries', 'cro.enquiry');
  $croGuests = request()->routeIs('cro.guests');
  $croLost = request()->routeIs('cro.lost');
  $croPerformance = request()->routeIs('cro.performance');
  $croSettings = request()->routeIs('cro.settings');
@endphp
@push('head')
<link rel="stylesheet" href="{{ asset('css/cro.css') }}?v={{ filemtime(public_path('css/cro.css')) }}">
@endpush
@push('scripts')
<script src="{{ asset('js/cro-modal-accessibility.js') }}?v={{ filemtime(public_path('js/cro-modal-accessibility.js')) }}"></script>
@endpush
<nav class="cro-nav" aria-label="Central Reservations workspace">
  <a href="{{ route('cro.index') }}" class="{{ $croDesk ? 'active' : '' }}" @if($croDesk) aria-current="page" @endif><x-icon name="grid"/> CRO Desk</a>
  <a href="{{ route('cro.enquiries') }}" class="{{ $croEnquiries ? 'active' : '' }}" @if($croEnquiries) aria-current="page" @endif><x-icon name="phone"/> Enquiries</a>
  <a href="{{ route('cro.guests') }}" class="{{ $croGuests ? 'active' : '' }}" @if($croGuests) aria-current="page" @endif><x-icon name="search"/> Guest lookup</a>
  <a href="{{ route('cro.lost') }}" class="{{ $croLost ? 'active' : '' }}" @if($croLost) aria-current="page" @endif><x-icon name="alert"/> Lost business</a>
  <a href="{{ route('cro.performance') }}" class="{{ $croPerformance ? 'active' : '' }}" @if($croPerformance) aria-current="page" @endif><x-icon name="trend"/> Performance</a>
  @if(can_do('cro.manage'))<span class="cro-nav-spacer" aria-hidden="true"></span><a href="{{ route('cro.settings') }}" class="{{ $croSettings ? 'active' : '' }}" @if($croSettings) aria-current="page" @endif><x-icon name="tool"/> Settings</a>@endif
</nav>
