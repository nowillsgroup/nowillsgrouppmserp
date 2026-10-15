@extends('layouts.app')
@section('title', 'Club Day Passes')
@section('crumbs') <a href="{{ route('club.index') }}">Health Club</a> / Day passes @endsection
@section('actions')
@if(can_do('club.desk'))<button class="btn btn-primary" data-modal="sellModal" id="sellBtn"><x-icon name="plus"/> Sell day pass</button>@endif
@endsection
@section('content')
<form method="get" class="filters"><div class="field"><label for="f_date">Date</label><input type="date" name="date" id="f_date" value="{{ $date }}"></div><div class="field" style="align-self:flex-end"><button class="btn">Show</button> <a class="btn btn-ghost" href="{{ route('club.passes') }}">All</a></div></form>
<div class="stats" id="passStats">
  <x-stat label="Visitors on day passes today" :value="$todayCount" icon="users"/>
  <x-stat label="Day pass takings today" :value="money($todayTotal)" icon="money" tone="green" sub="incl. tax"/>
  <x-stat :label="'Day pass revenue '.bdate()->format('M Y')" :value="money($mtd)" icon="trend" tone="purple" sub="net of tax · 4043"/>
  <x-stat label="Prices (excl. tax)" :value="money($adult, false).' / '.money($child, false)" icon="receipt" sub="adult / child under 12"/>
</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="passTable">
  <thead><tr><th>Pass</th><th>Date</th><th>Visitor</th><th class="num">Pax</th><th>Settled</th><th class="num">Total</th><th></th></tr></thead>
  <tbody>@forelse($rows as $p)
    <tr data-pass="{{ $p->pass_no }}" class="{{ $p->cancelled_at ? 'muted' : '' }}"><td class="bold">{{ $p->pass_no }}</td><td class="small">{{ dmy($p->date) }}</td>
      <td>{{ $p->name }}<div class="tiny muted">{{ \App\Models\ClubDayPass::KINDS[$p->kind] }}{{ $p->host ? ' of '.$p->host->name : '' }}{{ $p->phone ? ' · '.$p->phone : '' }}</div>@if($p->cancelled_at)<div class="tiny text-red">cancelled: {{ $p->cancel_reason }}</div>@endif</td>
      <td class="num">{{ $p->adults }}{{ $p->children ? '+'.$p->children : '' }}</td>
      <td class="small">{{ $p->stay ? 'Room '.$p->stay->room?->room_no : $p->paymentMode?->name }}{{ $p->reference ? ' · '.$p->reference : '' }}</td>
      <td class="num bold">{{ money($p->total, false) }}</td>
      <td class="num">@if(! $p->cancelled_at && $p->date->toDateString() === bdate()->toDateString() && can_do('club.desk'))<button class="btn btn-sm btn-ghost" data-modal="cancelPassModal" data-title="Cancel {{ $p->pass_no }}" data-action="{{ route('club.passes.cancel', $p) }}">Cancel</button>@endif</td></tr>
  @empty <tr><td colspan="7"><x-empty text="No day passes sold." icon="receipt"/></td></tr> @endforelse</tbody></table></div></div>
{{ $rows->links() }}

@if(can_do('club.desk'))
<div class="modal" id="sellModal"><div class="modal-box" style="max-width:560px"><form method="post" action="{{ route('club.passes.store') }}" data-reset id="sellForm">@csrf
  <div class="modal-head"><h3>Sell a day pass</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="name" label="Visitor name" required class="span-2"/>
    <x-field name="phone" label="Phone"/>
    <x-field name="facility" label="Check in now to" type="select" :options="\App\Models\ClubPlan::FACILITIES" placeholder="— don't check in —"/>
    <x-field name="adults" label="Adults" type="number" value="1" min="0" max="10" required/>
    <x-field name="children" label="Children under 12" type="number" value="0" min="0" max="10"/>
    <div class="field span-2"><label>Settlement</label><div class="flex gap-2"><label class="check"><input type="radio" name="settle" value="pay" checked> <span>Pay now</span></label><label class="check"><input type="radio" name="settle" value="room"> <span>Charge to an in-house room</span></label></div></div>
    <div class="field" data-settle="pay"><label for="dp_mode">Mode</label><select name="payment_mode_id" id="dp_mode">@foreach($modes as $pm)<option value="{{ $pm->id }}">{{ $pm->name }}</option>@endforeach</select></div>
    <div class="field" data-settle="pay"><label for="dp_ref">Reference</label><input name="reference" id="dp_ref"></div>
    <div class="field" data-settle="room" hidden><label for="dp_room">Room no.</label><input name="room_no" id="dp_room" placeholder="101"></div>
  </div>
  <p class="bold" id="dpTotal"></p><p class="tiny muted">Prices are {{ \App\Services\TaxService::pricesInclusive() ? 'tax-inclusive' : 'exclusive of VAT, NHIL, GETFund and Tourism Levy ('.rtrim(rtrim(number_format($rate, 2), '0'), '.').'%)' }}. A room charge appears on the guest's folio.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="sellSave">Sell pass</button></div>
</form></div></div>
<div class="modal" id="cancelPassModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Cancel pass</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required placeholder="Sold in error"/><p class="tiny muted">Same day only. A room charge is voided on the folio; refund a paid pass from the till.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger" id="cancelPassSave">Cancel pass</button></div>
</form></div></div>
@endif
@endsection
@push('scripts')
<script>
(() => { const f = document.getElementById('sellForm'); if (!f) return; const A = {{ $adult }}, C = {{ $child }}, R = {{ $rate }} / 100;
  const sync = () => { const s = f.querySelector('[name=settle]:checked').value; f.querySelectorAll('[data-settle]').forEach(el => el.hidden = el.dataset.settle !== s);
    const net = (+f.adults.value || 0) * A + (+f.children.value || 0) * C, tot = Math.round(net * (1 + R) * 100) / 100;
    document.getElementById('dpTotal').textContent = 'Total GH₵ ' + tot.toFixed(2) + ' (net ' + net.toFixed(2) + ')'; };
  f.addEventListener('input', sync); f.addEventListener('change', sync); document.addEventListener('click', e => { if (e.target.closest('[data-modal=sellModal]')) setTimeout(sync, 0); });
  if (location.hash === '#sell') document.getElementById('sellBtn')?.click(); sync(); })();
</script>
@endpush
