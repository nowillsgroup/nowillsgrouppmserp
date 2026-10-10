@extends('layouts.print')
@section('title', 'Personal data report '.$req->request_no)
@section('doctitle', 'PERSONAL DATA REPORT')
@section('docmeta')<div class="small">Request {{ $req->request_no }}<br>Generated {{ now()->format('d M Y H:i') }}<br>Guest ref. {{ $data['about']['guest_reference'] }}</div>@endsection
@section('content')
<p class="small">This report lists the personal data {{ $data['about']['hotel'] }} holds about you, prepared in response to your access request. Data-protection contact: {{ $data['about']['data_protection_contact'] }}.</p>
@php
  $label = fn ($k) => ucfirst(str_replace('_', ' ', $k));
  $cell = fn ($v) => is_array($v) ? implode('; ', array_map(fn ($x) => is_array($x) ? json_encode($x) : (string) $x, $v)) : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v);
@endphp
@foreach($data as $section => $content)
  @continue($section === 'about')
  <h3 style="margin:1.1rem 0 .4rem;border-bottom:1px solid #cbd5e1;padding-bottom:.2rem" id="sec-{{ $section }}">{{ $label($section) }}</h3>
  @if(is_array($content) && array_is_list($content))
    @if(! count($content))<p class="small muted">None.</p>
    @else<table class="table" style="font-size:.78rem"><thead><tr>@foreach(array_keys($content[0]) as $k)<th>{{ $label($k) }}</th>@endforeach</tr></thead>
      <tbody>@foreach($content as $row)<tr>@foreach($row as $v)<td>{{ $cell($v) }}</td>@endforeach</tr>@endforeach</tbody></table>@endif
  @elseif(is_array($content))
    <table class="table" style="font-size:.8rem"><tbody>@foreach($content as $k => $v)
      @if(is_array($v) && array_is_list($v) && $v && is_array($v[0]))<tr><th style="width:30%">{{ $label($k) }}</th><td>@foreach($v as $row)<div>{{ collect($row)->map(fn ($x, $kk) => $label($kk).': '.$cell($x))->implode(' · ') }}</div>@endforeach</td></tr>
      @else<tr><th style="width:30%">{{ is_string($k) ? $k : $label($k) }}</th><td>{{ $cell($v) ?: '—' }}</td></tr>@endif
    @endforeach</tbody></table>
  @endif
@endforeach
<p class="small muted mt-2">Identity-document images are not reproduced in this report; you may view them at the front desk. If anything is wrong you can ask us to correct it, or ask us to erase your data. You may also complain to the Data Protection Commission of Ghana (Data Protection Act 2012, Act 843).</p>
@endsection
