<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title') · {{ $currentHotel->name ?? '' }}</title>
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ config('pms.version') }}">
<style>body{background:#eef1f6}</style></head>
<body>
<div class="print-bar no-print">
  <a class="btn" href="{{ url()->previous() }}">← Back</a>
  <button class="btn btn-primary" onclick="window.print()">Print</button>
</div>
<div class="no-print">@include('partials.flash')</div>
<div class="print-page @yield('pageclass')">
  @hasSection('nohead') @else
  <div class="print-head">
    <div>@if($currentHotel->logo_path)<img src="{{ asset($currentHotel->logo_path) }}" alt="" style="max-height:54px;margin-bottom:.3rem;display:block">@endif<h2>{{ $currentHotel->name }}</h2>
      <div class="small text-2">{{ $currentHotel->address }}{{ $currentHotel->city ? ', '.$currentHotel->city : '' }}{{ $currentHotel->state ? ', '.$currentHotel->state : '' }} {{ $currentHotel->pincode }}</div>
      <div class="small text-2">Ph: {{ $currentHotel->phone }} · {{ $currentHotel->email }}</div>
      @if($currentHotel->tax_number)<div class="small"><b>TIN:</b> {{ $currentHotel->tax_number }}</div>@endif
    </div>
    <div class="right"><h2 style="color:#1d4ed8">@yield('doctitle')</h2>@yield('docmeta')</div>
  </div>
  @endif
  @yield('content')
</div>
@stack('scripts')
@if(request('autoprint'))<script>window.addEventListener('load', () => window.print());</script>@endif
</body></html>
