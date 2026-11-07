@extends('layouts.app')
@section('title', 'Stock Take '.$take->take_no)
@section('crumbs') <a href="{{ route('stocktakes.index') }}">Stock Take</a> / {{ $take->take_no }} @endsection
@section('actions')
  {!! status_badge($take->status) !!}
  <a class="btn" href="{{ route('stocktakes.print', [$take, 'blind' => 1]) }}" target="_blank"><x-icon name="print"/> Blind count sheet</a>
  <a class="btn" href="{{ route('stocktakes.print', $take) }}" target="_blank"><x-icon name="print"/> Variance report</a>
  @if($editable)<form method="post" action="{{ route('stocktakes.cancel', $take) }}" data-confirm="Cancel this count sheet?">@csrf<button class="btn btn-danger">Cancel</button></form>@endif
@endsection
@section('content')

{{-- v9.24 a blank line and a line counted at zero look the same in a grid and post completely differently --}}
<div class="stats wide">
  <x-stat label="Lines on the sheet" :value="$progress['lines']" icon="list" tone="slate" :sub="$progress['sub'].' on '.$take->store->name.' · counted by '.($take->creator?->name ?? 'nobody yet')" href="#stProgress"/>
  <x-stat label="Lines counted" :value="$progress['counted'].' / '.$progress['lines']" icon="check" :tone="$progress['pct_tone']"
    :sub="$progress['pct'].' % of the sheet carries a figure somebody wrote down'" href="#stProgress"/>
  <x-stat label="Variance so far" :value="money($found['value'])" icon="swap" :tone="$found['tone']"
    :sub="$progress['moved'].' lines have moved against the book — and only the counted ones count'" href="#stFound"/>
  <x-stat label="Blank counts" :value="$writes['blank']" icon="alert" :tone="$writes['tone']"
    :sub="$writes['blank_head_sub']" href="#stWrites"/>
  <x-stat label="Largest line" :value="money($found['worst']['value'] ?? 0)" icon="alert" tone="red"
    :sub="$found['worst'] ? $found['worst']['item'].' — counted '.$found['worst']['counted'].' '.$found['worst']['unit'].' against '.$found['worst']['system'].' on the book' : 'nothing has moved yet'"/>
</div>

@if($take->status === 'posted')
  <div class="alert alert-success"><x-icon name="check"/><div>Posted by {{ $take->poster?->name }} on {{ $take->posted_at->format('d M Y H:i') }}. Variances were booked as stock adjustments (ref. {{ $take->take_no }}).</div></div>
@endif

{{-- v9.24 how far through the count is, by category, because a count is done aisle by aisle --}}
<div class="card" id="stProgress">
  <div class="card-head"><h3>How far through is the count?</h3>
    <span class="muted small">{{ $progress['counted'] }} of {{ $progress['lines'] }} lines · {{ $progress['pct'] }} % · {{ $progress['blank'] }} blank</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Counted" :value="$progress['counted']" icon="check" :tone="$progress['pct_tone']" :sub="$progress['pct'].' % of the lines on the sheet'"/>
      <x-stat label="Still blank" :value="$progress['blank']" icon="edit" :tone="$progress['blank'] ? 'amber' : 'green'" sub="no figure written down, so the book quantity stands"/>
      <x-stat label="Counted and equal" :value="$progress['same']" icon="check" tone="slate" sub="the shelf agrees with the book, so posting writes nothing for them"/>
      <x-stat label="Counted at zero" :value="$progress['zero']" icon="alert" :tone="$progress['zero'] ? 'red' : 'green'" sub="nothing left on the shelf — posting writes the whole book quantity off"/>
    </div>
    <p class="small" id="stProgressState" style="margin:.6rem 0 0"><x-icon name="info"/>
      @if($progress['blank'])
        {{ $progress['blank'] }} of the {{ $progress['lines'] }} lines still have no figure — {{ $progress['behind'] }} are the ones left.
      @endif
      A count is done aisle by aisle, so the progress is kept by category: a sheet that reads 100 % on Food and 40 % on Linen is a
      count somebody is halfway through, and posting it now would leave the linen on the book exactly as it stands.
    </p>
    <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="progressTable">
      <thead><tr><th>Category</th><th class="num">Lines</th><th class="num">Counted</th><th class="num">Blank</th><th class="num">Done</th></tr></thead><tbody>
      @foreach($progress['rows'] as $r)<tr data-pct="{{ $r['pct'] }}">
        <td class="bold">{{ $r['category'] }}</td><td class="num">{{ $r['lines'] }}</td>
        <td class="num">{{ $r['counted'] }}</td><td class="num">{{ $r['blank'] ?: '—' }}</td>
        <td class="num {{ $r['tone'] }}">{{ $r['pct'] }} %</td></tr>@endforeach
      </tbody></table></div>
  </div>
</div>

{{-- v9.24 what the counted lines have found, and where it sits --}}
<div class="card" id="stFound">
  <div class="card-head"><h3>What has the count found so far?</h3>
    <span class="muted small">{{ money(abs($found['short'])) }} short · {{ money($found['over']) }} over · {{ money($found['value']) }} net, on {{ $progress['counted'] }} counted lines</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Net variance" :value="money($found['value'])" icon="swap" :tone="$found['tone']" sub="counted quantity less book quantity, at the cost the line carries"/>
      <x-stat label="Short" :value="money(abs($found['short']))" icon="minus" tone="red" sub="stock the book had and the shelf did not"/>
      <x-stat label="Over" :value="money($found['over'])" icon="plus" tone="amber" sub="stock on the shelf the book had already written down"/>
      <x-stat label="Lines moved" :value="$progress['moved']" icon="edit" tone="slate" :sub="$progress['same'].' counted lines agreed with the book exactly'"/>
    </div>
    @if($found['worst'])
      <p class="small text-red" id="stFoundState" style="margin:.6rem 0 0"><x-icon name="alert"/>
        The largest single line is {{ $found['worst']['item'] }} — {{ $found['worst']['counted'] }} {{ $found['worst']['unit'] }} counted
        against {{ $found['worst']['system'] }} on the book, {{ $found['worst']['qty'] }} {{ $found['worst']['unit'] }} short at
        {{ money($found['worst']['cost']) }} = {{ money($found['worst']['value']) }}.
        On a draft sheet none of this is in the ledger yet: it is what the count has found, and it changes the moment somebody
        writes another figure down.
      </p>
    @else
      <p class="small" id="stFoundState" style="margin:.6rem 0 0"><x-icon name="info"/>Nothing counted yet differs from the book, so this sheet has nothing to post.</p>
    @endif
    <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="foundTable">
      <thead><tr><th>Category</th><th class="num">Counted</th><th class="num">Short</th><th class="num">Over</th><th class="num">Net</th></tr></thead><tbody>
      @foreach($found['rows'] as $r)<tr data-net="{{ $r['net'] }}">
        <td class="bold">{{ $r['category'] }}</td><td class="num">{{ $r['counted'] }}</td>
        <td class="num">{{ $r['short'] ? money($r['short'], false) : '—' }}</td>
        <td class="num">{{ $r['over'] ? money($r['over'], false) : '—' }}</td>
        <td class="num {{ $r['net'] < 0 ? 'text-red' : ($r['net'] > 0 ? 'text-green' : '') }}">{{ money($r['net'], false) }}</td></tr>@endforeach
      </tbody><tfoot><tr><th>All categories</th><th class="num">{{ $progress['counted'] }}</th>
        <th class="num">{{ money($found['short'], false) }}</th><th class="num">{{ money($found['over'], false) }}</th>
        <th class="num">{{ money($found['value'], false) }}</th></tr></tfoot></table></div>
  </div>
</div>

{{-- v9.24 what posting will do, line by line — and what the blank-as-zero box would do in one click --}}
<div class="card" id="stWrites">
  <div class="card-head"><h3>What will posting do?</h3>
    <span class="muted small">{{ $writes['moved'] }} lines will write a movement · {{ money($writes['value']) }} · {{ $writes['blank'] }} lines will not</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Will be written" :value="money($writes['value'])" icon="layers" :tone="$writes['value'] < 0 ? 'red' : 'green'" :sub="$writes['moved'].' lines against the stock ledger, one movement each'"/>
      <x-stat label="Counted at zero" :value="$writes['zero']" icon="alert" :tone="$writes['zero'] ? 'red' : 'green'" :sub="$writes['zero_sub']"/>
      <x-stat label="Left alone" :value="$writes['blank']" icon="edit" :tone="$writes['tone']" :sub="$writes['blank_sub']"/>
      <x-stat label="Cost of the box" :value="money($writes['blank_value'])" icon="alert" :tone="$writes['blank'] ? 'amber' : 'green'" :sub="$writes['box_sub']"/>
    </div>
    @if($writes['blank'])
      <p class="small" id="stWritesState" style="margin:.6rem 0 0"><x-icon name="alert"/>
        {{ $writes['blank'] }} line{{ $writes['blank'] === 1 ? '' : 's' }} carry no figure. Posting as it stands leaves them on the book —
        the count proves nothing about them. @if($writes['biggest_blank'])The biggest is {{ $writes['biggest_blank']['item'] }}:
        {{ $writes['biggest_blank']['qty'] }} {{ $writes['biggest_blank']['unit'] }}, {{ money($writes['biggest_blank']['value']) }}.@endif
        Ticking the box below is not a shortcut for finishing the count: it tells the system every uncounted line is at zero, and
        writes {{ money($writes['blank_value']) }} off the shelf in one posting that nobody actually counted.
      </p>
    @else
      <p class="small" id="stWritesState" style="margin:.6rem 0 0"><x-icon name="check"/>
        Every line carries a figure, so posting covers the whole sheet and there is nothing left to write off unseen.
      </p>
    @endif
    @if($writes['rows'])
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="writeTable">
        <thead><tr><th>Item</th><th class="num">Book</th><th class="num">Counted</th><th class="num">Variance</th><th class="num">Writes</th></tr></thead><tbody>
        @foreach($writes['rows'] as $r)<tr data-value="{{ $r['value'] }}">
          <td class="bold">{{ $r['item'] }}</td><td class="num">{{ $r['system'] }} {{ $r['unit'] }}</td>
          <td class="num">{{ $r['counted'] }}</td>
          <td class="num {{ $r['tone'] }}">{{ $r['qty'] > 0 ? '+' : '' }}{{ $r['qty'] }}</td>
          <td class="num {{ $r['tone'] }}">{{ money($r['value'], false) }}</td></tr>@endforeach
      </tbody><tfoot><tr><th>{{ $writes['moved'] }} lines</th><th class="num"></th><th class="num"></th><th class="num"></th>
        <th class="num">{{ money($writes['value'], false) }}</th></tr></tfoot></table></div>
    @endif
  </div>
</div>

<form method="post" action="{{ route('stocktakes.update', $take) }}" id="stForm">@csrf @method('PUT')
<div class="card flush"><div class="card-head"><h3>Count sheet</h3><input class="input input-sm" placeholder="Filter items…" data-filter-table="#stTable" style="max-width:240px"></div>
<div class="card-body table-wrap"><table class="table table-compact" id="stTable">
  <thead><tr><th>Item</th><th>Category</th><th>Unit</th><th class="num">Book qty</th><th style="width:120px">Counted</th><th class="num">Variance</th><th class="num">Unit cost</th><th class="num">Value</th><th>Remarks</th></tr></thead><tbody>
  @foreach($take->lines->sortBy(fn($l) => ($l->item->category->name ?? 'zzz').$l->item->name) as $l)
  <tr data-sys="{{ $l->system_qty }}" data-cost="{{ $l->unit_cost }}" data-counted="{{ $l->counted_qty === null ? 'blank' : ($l->counted_qty ? 'counted' : 'zero') }}">
    <td class="bold">{{ $l->item->name }} <span class="tiny muted">{{ $l->item->code }}</span></td><td class="small">{{ $l->item->category->name ?? '—' }}</td><td class="small">{{ $l->item->unit }}</td>
    <td class="num">{{ rtrim(rtrim(number_format($l->system_qty, 2), '0'), '.') }}</td>
    <td>@if($editable)<input type="number" step="0.01" min="0" name="counts[{{ $l->id }}]" value="{{ $l->counted_qty === null ? '' : (float) $l->counted_qty }}" class="st-count" style="width:100px">@else{{ $l->counted_qty === null ? '—' : (float) $l->counted_qty }}@endif</td>
    <td class="num st-var {{ ($l->varianceQty() ?? 0) < 0 ? 'text-red' : (($l->varianceQty() ?? 0) > 0 ? 'text-green' : '') }}">{{ $l->varianceQty() === null ? '' : ($l->varianceQty() > 0 ? '+' : '').$l->varianceQty() }}</td>
    <td class="num">{{ money($l->unit_cost, false) }}</td><td class="num st-val">{{ $l->varianceQty() ? money($l->varianceValue(), false) : '' }}</td>
    <td>@if($editable)<input name="remarks[{{ $l->id }}]" value="{{ $l->remarks }}" maxlength="190" style="width:140px" placeholder="e.g. breakage">@else<span class="small">{{ $l->remarks }}</span>@endif</td>
  </tr>
  @endforeach
  </tbody></table></div></div>
@if($editable)
<div class="card"><div class="card-body flex gap-1 wrap items-center">
  <button class="btn" name="action" value="save"><x-icon name="check"/> Save counts</button>
  <label class="check"><input type="checkbox" name="blank_as_zero" value="1"> Treat blank counts as zero (full count)</label>
  <span style="flex:1"></span>
  <button class="btn btn-primary" name="action" value="post" onclick="return confirm('Post the stock take? Variances will be booked as stock adjustments and the sheet will be locked.')"><x-icon name="layers"/> Post variances</button>
</div></div>
@endif
</form>

<p class="tiny muted mt-1" id="stSheetChain">This sheet is the count, and the <a href="{{ route('stocktakes.index') }}">stock take register</a> is the month it belongs to. Posting writes one movement per counted line into the <a href="{{ route('inventory.ledger') }}">stock ledger</a> and freezes the sheet; a line left blank is not a line counted at zero, and only the second one takes stock off the shelf.</p>
@endsection
@push('scripts')
<script>
document.querySelectorAll('.st-count').forEach(inp => inp.addEventListener('input', () => {
  const tr = inp.closest('tr'), sys = +tr.dataset.sys, cost = +tr.dataset.cost, v = inp.value === '' ? null : Math.round((+inp.value - sys) * 100) / 100;
  const vc = tr.querySelector('.st-var'), vv = tr.querySelector('.st-val');
  vc.textContent = v === null ? '' : (v > 0 ? '+' : '') + v; vc.className = 'num st-var ' + (v < 0 ? 'text-red' : (v > 0 ? 'text-green' : ''));
  vv.textContent = v ? (v * cost).toFixed(2) : '';
}));
</script>
@endpush
