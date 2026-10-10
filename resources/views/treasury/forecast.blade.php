@extends('layouts.app')
@section('title', 'Cash Forecast')
@section('crumbs') Treasury / {{ $weeks }}-week cash forecast @endsection
@php
  $CF = \App\Models\CashForecastLine::class;
  $canOp = can_do('treasury.operate');
  $W = collect($fc['weeks']);
@endphp
@section('actions')
  <form method="get" class="inline"><select name="weeks" onchange="this.form.submit()" aria-label="Horizon">@foreach([8, 13, 26] as $n)<option value="{{ $n }}" @selected($weeks === $n)>{{ $n }} weeks</option>@endforeach</select></form>
  @if($canOp)<button class="btn btn-primary" data-modal="lineModal" data-action="{{ route('treasury.forecast.store') }}" data-title="New forecast line" data-fdescription="" data-famount="" data-ffrequency="once" data-f_method="POST" id="newLineBtn"><x-icon name="plus"/> Add expected flow</button>@endif
@endsection
@section('content')
@if($fc['first_short'])
  <div class="alert alert-error" id="shortAlert"><x-icon name="alert"/> Cash is projected to fall below the combined minimum balance of {{ money($fc['min']) }} in the week of <b>{{ $fc['first_short']['start']->format('d M Y') }}</b> ({{ money($fc['first_short']['closing']) }}). Move money, chase receivables or defer payments.</div>
@endif

<div class="stats tr-stats">
  <x-stat label="Opening cash" :value="money($fc['opening'])" icon="wallet" tone="blue" :sub="'as at '.dmy(bdate())"/>
  <x-stat label="Expected in" :value="money($fc['total_in'])" icon="download" tone="green" :sub="count($fc['cats']['in']).' sources'"/>
  <x-stat label="Expected out" :value="money($fc['total_out'])" icon="receipt" tone="amber" :sub="count($fc['cats']['out']).' categories'"/>
  <x-stat label="Low point" :value="$fc['low'] ? money($fc['low']['closing']) : '—'" icon="trend" :tone="$fc['first_short'] ? 'red' : 'green'" :sub="$fc['low'] ? 'week '.$fc['low']['n'].' · '.$fc['low']['start']->format('d M') : ''"/>
  <x-stat label="Closing (week {{ $weeks }})" :value="money($W->last()['closing'] ?? 0)" icon="chart" tone="purple" :sub="'to '.dmy($fc['to'])"/>
</div>

<div class="card flush mt-1"><div class="card-head"><h3><x-icon name="chart"/> Weekly cash flow</h3><span class="tiny muted">weeks start Monday · amounts in GH₵</span></div>
<div class="card-body table-wrap"><table class="table fc-table" id="forecastTable">
  <thead><tr><th class="sticky">Week</th>@foreach($W as $w)<th class="num" title="{{ $w['start']->format('d M') }} – {{ $w['end']->format('d M') }}">W{{ $w['n'] }}<div class="tiny muted">{{ $w['start']->format('d/m') }}</div></th>@endforeach<th class="num">Total</th></tr></thead>
  <tbody>
    <tr class="bold-row"><td class="sticky">Opening</td>@foreach($W as $w)<td class="num">{{ number_format($w['opening'], 0) }}</td>@endforeach<td></td></tr>
    <tr><td class="sticky tiny muted" colspan="{{ $weeks + 2 }}" style="text-transform:uppercase;letter-spacing:.04em">Receipts</td></tr>
    @foreach($fc['cats']['in'] as $cat)<tr data-cat="{{ $cat }}"><td class="sticky">{{ $cat }}</td>@foreach($W as $w)<td class="num">{{ isset($w['in'][$cat]) ? number_format($w['in'][$cat], 0) : '' }}</td>@endforeach<td class="num bold">{{ number_format($W->sum(fn ($w) => $w['in'][$cat] ?? 0), 0) }}</td></tr>@endforeach
    <tr class="bold-row"><td class="sticky">Total in</td>@foreach($W as $w)<td class="num text-green">{{ number_format($w['in_total'], 0) }}</td>@endforeach<td class="num">{{ number_format($fc['total_in'], 0) }}</td></tr>
    <tr><td class="sticky tiny muted" colspan="{{ $weeks + 2 }}" style="text-transform:uppercase;letter-spacing:.04em">Payments</td></tr>
    @foreach($fc['cats']['out'] as $cat)<tr data-cat="{{ $cat }}"><td class="sticky">{{ $cat }}</td>@foreach($W as $w)<td class="num">{{ isset($w['out'][$cat]) ? number_format($w['out'][$cat], 0) : '' }}</td>@endforeach<td class="num bold">{{ number_format($W->sum(fn ($w) => $w['out'][$cat] ?? 0), 0) }}</td></tr>@endforeach
    <tr class="bold-row"><td class="sticky">Total out</td>@foreach($W as $w)<td class="num text-red">{{ number_format($w['out_total'], 0) }}</td>@endforeach<td class="num">{{ number_format($fc['total_out'], 0) }}</td></tr>
    <tr class="bold-row"><td class="sticky">Net</td>@foreach($W as $w)<td class="num {{ $w['net'] < 0 ? 'text-red' : '' }}">{{ number_format($w['net'], 0) }}</td>@endforeach<td class="num">{{ number_format($fc['total_in'] - $fc['total_out'], 0) }}</td></tr>
    <tr class="bold-row"><td class="sticky">Closing</td>@foreach($W as $w)<td class="num {{ $w['short'] ? 'fc-short' : '' }}" data-week="{{ $w['n'] }}">{{ number_format($w['closing'], 0) }}</td>@endforeach<td></td></tr>
  </tbody>
</table></div></div>

<div class="grid g-2-1 mt-1" style="align-items:start">
<details class="card flush" id="itemsCard"><summary class="card-head"><h3><x-icon name="layers"/> What's in it ({{ $fc['items']->count() }} items)</h3></summary>
  <div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Category</th><th>Item</th><th class="num">In</th><th class="num">Out</th></tr></thead>
    <tbody>@foreach($fc['items'] as $i)<tr><td class="small">{{ \Illuminate\Support\Carbon::parse($i['date'])->format('d M') }}</td><td class="small">{{ $i['cat'] }}</td><td class="small">@if($i['url'])<a href="{{ $i['url'] }}">{{ $i['label'] }}</a>@else{{ $i['label'] }}@endif</td>
      <td class="num">{{ $i['dir'] === 'in' ? money($i['amount'], false) : '' }}</td><td class="num">{{ $i['dir'] === 'out' ? money($i['amount'], false) : '' }}</td></tr>@endforeach</tbody>
  </table></div>
</details>

<div class="card" id="linesCard"><div class="card-head"><h3><x-icon name="edit"/> Expected flows entered by hand</h3></div><div class="card-body">
  @forelse($lines as $l)
    <div class="flex justify-between items-center mb-1" data-line="{{ $l->id }}">
      <div><b>{{ $l->description }}</b> <span class="badge badge-{{ $l->direction === 'in' ? 'green' : 'amber' }}">{{ $l->direction === 'in' ? 'in' : 'out' }}</span>
        <div class="tiny muted">{{ $l->category }} · {{ $CF::FREQUENCIES[$l->frequency] }} from {{ $l->first_date->format('d M Y') }}@if($l->until_date) to {{ $l->until_date->format('d M Y') }}@endif</div></div>
      <div class="right"><b>{{ money($l->amount, false) }}</b>@if($canOp)<div>
        <button class="btn btn-sm btn-ghost" data-modal="lineModal" data-action="{{ route('treasury.forecast.update', $l) }}" data-title="Edit forecast line" data-fdescription="{{ $l->description }}" data-fdirection="{{ $l->direction }}" data-fcategory="{{ $l->category }}" data-famount="{{ $l->amount }}" data-ffirst_date="{{ $l->first_date->toDateString() }}" data-ffrequency="{{ $l->frequency }}" data-funtil_date="{{ $l->until_date?->toDateString() }}" data-f_method="PUT">Edit</button>
        <form method="post" action="{{ route('treasury.forecast.delete', $l) }}" class="inline" data-confirm="Remove this forecast line?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost">Remove</button></form></div>@endif</div>
    </div>
  @empty<p class="small muted" style="margin:0">Add loan instalments, capital expenditure, owner drawings or anything the ledger does not know about yet.</p>@endforelse
  <p class="tiny muted" style="margin:.6rem 0 0">Automatic sources: card / gateway settlements (after settlement days, net of fees), city-ledger bills by due date ({{ \App\Services\TreasuryService::cfg('treasury_collect_pct') }}% of overdue in week 1), post-dated cheques, in-house guest balances at departure, guest-paid arrivals ({{ \App\Services\TreasuryService::cfg('treasury_arrival_pct') }}%), tenant charges, supplier bills by due date, unpresented cheques, payroll on day {{ \App\Services\TreasuryService::cfg('treasury_payday') }}, SSNIT / Tier 2 on the 14th, PAYE on the 15th, VAT and levies at month end.</p>
</div></div>
</div>

@if($canOp)
<div class="modal" id="lineModal"><div class="modal-box"><form method="post" action="{{ route('treasury.forecast.store') }}" id="lineForm">@csrf <input type="hidden" name="_method" value="POST">
  <div class="modal-head"><h3 data-title>Forecast line</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label for="flDesc">Description</label><input name="description" id="flDesc" required maxlength="150"></div>
    <div class="field"><label for="flDir">Direction</label><select name="direction" id="flDir"><option value="out">Money out</option><option value="in">Money in</option></select></div>
    <div class="field"><label for="flCat">Category</label><select name="category" id="flCat">@foreach($CF::CATEGORIES as $c)<option>{{ $c }}</option>@endforeach</select></div>
    <div class="field"><label for="flAmt">Amount</label><input type="number" step="0.01" min="0.01" name="amount" id="flAmt" required></div>
    <div class="field"><label for="flFreq">Repeats</label><select name="frequency" id="flFreq">@foreach($CF::FREQUENCIES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    <div class="field"><label for="flFirst">First date</label><input type="date" name="first_date" id="flFirst" required value="{{ bdate()->toDateString() }}"></div>
    <div class="field"><label for="flUntil">Until (optional)</label><input type="date" name="until_date" id="flUntil"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="lineSave">Save</button></div>
</form></div></div>
@endif
@endsection
