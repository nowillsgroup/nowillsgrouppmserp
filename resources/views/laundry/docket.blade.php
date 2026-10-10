<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>{{ $o->order_no }}</title>
<style>
body{font:13px/1.4 system-ui,sans-serif;margin:0;padding:16px;color:#111;max-width:360px}
h1{font-size:18px;margin:0}h2{font-size:28px;margin:6px 0;letter-spacing:.04em}.m{color:#555;font-size:11px}
table{width:100%;border-collapse:collapse;margin-top:8px}td,th{padding:3px 0;border-bottom:1px dashed #bbb;text-align:left}th{font-size:11px;color:#555}.r{text-align:right}
.big{font-size:16px;font-weight:700}.tag{border:2px solid #111;border-radius:8px;padding:8px 10px;margin:8px 0;display:flex;justify-content:space-between;align-items:center}
@media print{button{display:none}body{padding:0}}
</style></head><body>
<button onclick="window.print()" style="float:right">Print</button>
<h1>{{ $hotel->name }}</h1><div class="m">Laundry docket</div>
<div class="tag"><div><div class="m">ORDER</div><h2>{{ $o->order_no }}</h2></div><div class="r"><div class="m">{{ $o->type === 'walk_in' ? 'CUSTOMER' : 'ROOM' }}</div><h2>{{ $o->type === 'walk_in' ? '—' : $o->room?->room_no }}</h2></div></div>
<div><b>{{ $o->guest_name }}</b>{{ $o->phone ? ' · '.$o->phone : '' }}</div>
<div>Bag / tag: <b>{{ $o->bag_no ?: '—' }}</b> · {{ $o->speed === 'express' ? 'EXPRESS' : 'Normal' }}</div>
<div>Collected {{ $o->collected_at?->format('d M H:i') }} · <b>Due {{ $o->promised_at?->format('d M H:i') }}</b></div>
<table><thead><tr><th>Item</th><th>Service</th><th class="r">Qty</th><th class="r">Amount</th></tr></thead><tbody>
@forelse($o->lines as $l)<tr><td>{{ $l->item_name }}@if($l->remark)<div class="m">{{ $l->remark }}</div>@endif</td><td>{{ \App\Models\LaundryItem::SERVICES[$l->service] ?? $l->service }}</td><td class="r">{{ $l->qty }}</td><td class="r">{{ money($l->amount, false) }}</td></tr>
@empty<tr><td colspan="4">{{ $o->bag_pieces ? $o->bag_pieces.' pcs – to be itemised' : 'To be itemised' }}</td></tr>@endforelse
</tbody></table>
@if($o->pieces)<p class="r"><span class="m">{{ $o->pieces }} pcs{{ (float) $o->express_charge ? ' · express '.money($o->express_charge, false) : '' }}</span><br><span class="big">{{ money($o->total) }}</span> <span class="m">+ tax</span></p>@endif
@if($o->notes)<p class="m">Notes: {{ $o->notes }}</p>@endif
<p class="m">The hotel is not responsible for colour fastness, shrinkage or items left in pockets.</p>
</body></html>
