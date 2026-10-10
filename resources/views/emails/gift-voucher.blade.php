@extends('emails.layout')
@section('body')
@php $v = $voucher; @endphp
<p style="font-size:16px;margin:0 0 12px">Dear {{ $v->recipient_name ?: ($v->purchaser_name ?: 'Guest') }},</p>
@if($reminder)
<p>This is a friendly reminder that your gift voucher <b>{{ $v->code }}</b> still has <b>{{ money($v->balance) }}</b> to spend and expires on <b>{{ $v->expires_on->format('d M Y') }}</b>. We would love to welcome you before then.</p>
@else
<p>@if($v->source === 'sold' && $v->purchaser_name){{ $v->purchaser_name }} has sent you a gift voucher for {{ $hotel->name }}.@else {{ $hotel->name }} is pleased to send you a gift voucher.@endif</p>
@if($v->message)<p style="font-style:italic;border-left:3px solid {{ $v->type?->color ?? '#0f766e' }};padding-left:10px;color:#334155">“{{ $v->message }}”</p>@endif
@endif
<table cellpadding="10" cellspacing="0" style="border-collapse:collapse;margin:14px 0;width:100%;background:{{ $v->type?->color ?? '#0f766e' }};color:#fff;border-radius:8px">
<tr><td><div style="font-size:12px;opacity:.85">{{ $v->type?->name }}</div><div style="font-size:26px;font-weight:bold">{{ money($reminder ? $v->balance : $v->face_value) }}</div></td>
<td align="right"><div style="font-size:12px;opacity:.85">Voucher code</div><div style="font-size:20px;font-weight:bold;letter-spacing:2px">{{ $v->code }}</div><div style="font-size:12px;opacity:.85">Valid until {{ $v->expires_on->format('d M Y') }}</div></td></tr>
</table>
<p>Show this code at the front desk or the restaurant when you pay. You can view the voucher, its balance and a scannable QR code at any time here:<br><a href="{{ $v->publicUrl() }}">{{ $v->publicUrl() }}</a></p>
<p style="font-size:12px;color:#64748b">Can be used on: {{ $v->type?->usesLabel() }}. {{ $v->type?->terms }} {{ \App\Services\GiftVoucherService::cfg()['terms'] }}</p>
<p style="margin-top:18px">Warm regards,<br><b>{{ $hotel->name }}</b>@if($hotel->phone)<br>{{ $hotel->phone }}@endif</p>
@endsection
