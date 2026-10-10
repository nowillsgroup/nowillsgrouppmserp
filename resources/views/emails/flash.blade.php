@extends('emails.layout')
@section('body')
<p style="font-size:16px;margin:0 0 4px"><b>Daily flash report</b></p>
<p style="margin:0 0 14px;color:#64748b">Business date {{ $date->format('l, d M Y') }} · night audit closed {{ now()->format('d M Y H:i') }} · amounts in {{ $hotel->currency_symbol }}</p>
<table width="100%" cellpadding="7" cellspacing="0" style="border-collapse:collapse;font-size:13px">
  <tr style="background:#1e293b;color:#fff"><th align="left">Metric</th><th align="right">Day</th><th align="right">MTD</th><th align="right">YTD</th></tr>
  @foreach($rows as $r)
  <tr style="border-bottom:1px solid #e2e8f0;{{ in_array($r['key'], ['occ','room','net','collect']) ? 'font-weight:700;background:#f8fafc' : '' }}"><td>{{ $r['metric'] }}</td><td align="right">{{ $r['day'] }}</td><td align="right">{{ $r['mtd'] }}</td><td align="right">{{ $r['ytd'] }}</td></tr>
  @endforeach
</table>
<p style="font-size:12px;color:#64748b;margin-top:14px">Sent automatically by Nowills PMS after the night audit. Change the recipients under Settings › Preferences.</p>
@endsection
