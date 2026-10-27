<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#14532d">
<title>@yield('title') · {{ $hotel->name }}</title><link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
<style>.pub-head{background:linear-gradient(135deg,#10271c,#166534);color:#fff;padding:2.2rem 1rem 3.5rem;text-align:center}.pub-head h1{font-size:1.9rem}.pub-wrap{max-width:980px;margin:-2.2rem auto 2rem;padding:0 1rem}</style></head>
<body>@include('partials.icons')
<div class="pub-head"><h1>{{ $hotel->name }}</h1><div style="opacity:.8">{{ $hotel->address }}{{ $hotel->city ? ', '.$hotel->city : '' }} · {{ $hotel->phone }}</div></div>
<div class="pub-wrap">
@if(session('success'))<div class="alert alert-success"><x-icon name="check"/><div>{{ session('success') }}</div></div>@endif
@if($errors->any())<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first() }}</div></div>@endif
@yield('content')
<p class="center small muted mt-2"><a href="{{ route('privacy.public', $hotel->code) }}" class="muted" id="privacyLink">Privacy &amp; your data</a> · Powered by Nowills PMS</p></div></body></html>
