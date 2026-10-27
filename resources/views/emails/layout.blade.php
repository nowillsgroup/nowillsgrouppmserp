<!doctype html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>{{ $hotel->name }}</title></head>
<body style="margin:0;background:#f1f4f9;font-family:Segoe UI,Helvetica,Arial,sans-serif;color:#1e293b;font-size:14px">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f4f9;padding:24px 0"><tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #e2e8f0">
  <tr><td style="background:#166534;color:#fff;padding:20px 28px"><div style="font-size:20px;font-weight:700">{{ $hotel->name }}</div>
    <div style="font-size:12px;opacity:.85">{{ collect([$hotel->address, $hotel->city, $hotel->country])->filter()->implode(', ') }}</div></td></tr>
  <tr><td style="padding:24px 28px">@yield('body')</td></tr>
  <tr><td style="background:#f8fafc;padding:14px 28px;font-size:12px;color:#64748b;border-top:1px solid #e2e8f0">
    {{ $hotel->name }}@if($hotel->phone) · {{ $hotel->phone }}@endif @if($hotel->email) · {{ $hotel->email }}@endif @if($hotel->website)<br>{{ $hotel->website }}@endif
  </td></tr>
</table></td></tr></table></body></html>
