@extends('layouts.app')
@section('title', 'Stock Take')
@section('crumbs') Inventory / Stock Take (Physical Count) @endsection
@section('actions')<a class="btn" href="{{ route('inventory.reorder') }}"><x-icon name="refresh"/> Re-order suggestions</a>@endsection
@section('content')

{{-- v9.21 a count sheet is only worth what the posting made of it, so the screen leads with the variance, the coverage and what is still unposted --}}
<div class="stats wide">
  <x-stat label="Count sheets" :value="$takes->total()" icon="list" tone="slate"
    :sub="$variance['takes'].' posted · '.$posting['drafts'].' draft · '.$posting['cancelled'].' cancelled'" href="#posting"/>
  <x-stat label="Variance posted" :value="money($variance['value'])" icon="swap" :tone="$variance['value'] < 0 ? 'red' : 'green'"
    :sub="$variance['value_sub']" href="#stVariance"/>
  <x-stat label="Lines counted" :value="$variance['pct_label']" icon="check" :tone="$variance['pct_tone']"
    :sub="$variance['counted'].' of '.$variance['lines'].' lines on the posted sheets were actually counted'" href="#stCoverage"/>
  <x-stat label="Never counted" :value="$variance['skipped']" icon="alert" :tone="$variance['skipped'] ? 'amber' : 'green'"
    :sub="$variance['skipped'] ? 'left exactly as the book had them — the count proves nothing about these' : 'every line on the posted sheets was counted'" href="#stCoverage"/>
  <x-stat label="Not in the books yet" :value="money($posting['value'])" icon="clock" :tone="$posting['value'] ? 'amber' : 'green'"
    :sub="$posting['sub']" href="#posting"/>
</div>

{{-- v9.21 the variance --}}
<div class="card" id="stVariance">
  <div class="card-head"><h3>What did the count find?</h3>
    <span class="muted small">{{ $variance['takes'] }} posted take{{ $variance['takes'] === 1 ? '' : 's' }} · {{ money(abs($variance['short'])) }} short · {{ money($variance['over']) }} over · {{ money($variance['value']) }} net</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Net variance" :value="money($variance['value'])" icon="swap" :tone="$variance['value'] < 0 ? 'red' : 'green'" sub="counted against the book, at the cost the line carries"/>
      <x-stat label="Short" :value="money(abs($variance['short']))" icon="minus" tone="red" sub="stock the book had and the shelf did not"/>
      <x-stat label="Over" :value="money($variance['over'])" icon="plus" tone="amber" sub="stock on the shelf the book had already written off"/>
      <x-stat label="Lines adjusted" :value="$variance['adjusted']" icon="edit" tone="slate" sub="every one of them wrote a movement into the stock ledger"/>
    </div>
    @if($variance['rows'])
      <p class="small {{ ($variance['worst_short']['value'] ?? 0) > 0 ? 'text-red' : '' }}" id="stVarianceState" style="margin:.6rem 0 0">
        @if($variance['worst_short'])
          <x-icon name="alert"/>The largest single one is {{ $variance['worst_short']['item'] }} on {{ $variance['worst_short']['take'] }} —
          counted {{ $variance['worst_short']['counted'] }} {{ $variance['worst_short']['unit'] }} against {{ $variance['worst_short']['system'] }} on the book,
          {{ $variance['worst_short']['qty'] }} {{ $variance['worst_short']['unit'] }} short at {{ money($variance['worst_short']['cost']) }} =
          {{ money($variance['worst_short']['value']) }}.
        @endif
        {{ money(abs($variance['short'])) }} of the net is stock that was on the book and not on the shelf; a shortage is either a theft,
        a wastage nobody wrote down, or a receipt that never arrived — and the count is the only document that says which.
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="varianceTable">
        <thead><tr><th>Take</th><th>Store</th><th class="num">Counted</th><th class="num">Adjusted</th><th class="num">Short</th><th class="num">Over</th><th class="num">Net</th></tr></thead><tbody>
        @foreach($variance['rows'] as $r)<tr data-net="{{ $r['value'] }}">
          <td class="bold">{{ $r['take'] }}</td><td class="small">{{ $r['store'] }}</td>
          <td class="num">{{ $r['counted'] }}/{{ $r['lines'] }}</td><td class="num">{{ $r['varied'] }}</td>
          <td class="num">{{ $r['short'] ? money($r['short'], false) : '—' }}</td>
          <td class="num">{{ $r['over'] ? money($r['over'], false) : '—' }}</td>
          <td class="num {{ $r['value'] < 0 ? 'text-red bold' : '' }}">{{ money($r['value'], false) }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="stVarianceState" style="margin:.6rem 0 0"><x-icon name="info"/>No count has been posted yet, so nothing has been written back to the stock ledger.</p>
    @endif
  </div>
</div>

{{-- v9.21 the coverage: a posted sheet with a third of its lines blank is a partial count pretending to be a full one --}}
<div class="card" id="stCoverage">
  <div class="card-head"><h3>How much of the shelf was actually counted?</h3>
    <span class="muted small">{{ $variance['counted'] }} of {{ $variance['lines'] }} lines on the posted sheets · {{ $variance['skipped'] }} left alone</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Lines on the sheets" :value="$variance['lines']" icon="list" tone="slate" sub="every line the posted count sheets carried, counted or not"/>
      <x-stat label="Counted" :value="$variance['counted']" icon="check" :tone="$variance['pct_tone']" :sub="$variance['pct'].' % of them have a figure somebody wrote down'"/>
      <x-stat label="Never counted" :value="$variance['skipped']" icon="alert" :tone="$variance['skipped'] ? 'red' : 'green'" sub="posted with the book quantity untouched, so the count does not cover them"/>
      <x-stat label="Lowest coverage" :value="($variance['worst_take'] ? ($summary[$variance['worst_take']->id]['pct'] ?? 0) : 0).' %'" icon="eye" tone="amber"
        :sub="$variance['worst_take'] ? $variance['worst_take']->take_no.' — '.$summary[$variance['worst_take']->id]['counted'].' of '.$summary[$variance['worst_take']->id]['lines'].' lines' : 'nothing has been posted'"/>
    </div>
    @if($variance['coverage_rows'])
      <p class="small" id="stCoverageState" style="margin:.6rem 0 0"><x-icon name="eye"/>
        @if($variance['worst_take'])
          {{ $variance['worst_take']->take_no }} was posted with {{ $summary[$variance['worst_take']->id]['counted'] }} of its
          {{ $summary[$variance['worst_take']->id]['lines'] }} lines counted, {{ $summary[$variance['worst_take']->id]['pct'] }} % —
          the rest kept whatever the book said, so the posting is a partial proof of the shelf rather than a full one.
        @endif
        Together the posted sheets reach {{ $variance['pct'] }} % of the lines they carried; the {{ $variance['skipped'] }} that were never counted
        are the part of the store nobody has looked at this month.
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="coverageTable">
        <thead><tr><th>Take</th><th>Store</th><th class="num">Lines</th><th class="num">Counted</th><th class="num">Coverage</th><th class="num">Adjusted</th></tr></thead><tbody>
        @foreach($variance['coverage_rows'] as $r)<tr data-coverage="{{ $r['pct'] }}">
          <td class="bold">{{ $r['take'] }}</td><td class="small">{{ $r['store'] }}</td>
          <td class="num">{{ $r['lines'] }}</td><td class="num">{{ $r['counted'] }}</td>
          <td class="num {{ $r['tone'] }}">{{ $r['pct'] }} %</td>
          <td class="num">{{ $r['varied'] }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="stCoverageState" style="margin:.6rem 0 0"><x-icon name="info"/>Nothing has been posted, so there is no coverage to measure.</p>
    @endif
  </div>
</div>

{{-- v9.21 the posting: a variance nobody has posted is a variance the ledger does not know about --}}
<div class="card" id="stPosting">
  <div class="card-head"><h3>What is still out of the ledger?</h3>
    <span class="muted small">{{ $posting['drafts'] }} draft{{ $posting['drafts'] === 1 ? '' : 's' }} · {{ money($posting['value']) }} counted and not posted · oldest {{ $posting['oldest_days'] }} day{{ $posting['oldest_days'] === 1 ? '' : 's' }}</span></div>
  <div class="card-body">
    <div class="stats" style="margin:0">
      <x-stat label="Draft sheets" :value="$posting['drafts']" icon="edit" :tone="$posting['drafts'] ? 'amber' : 'green'" :sub="$posting['counted'].' of '.$posting['lines'].' lines on them already carry a counted figure'"/>
      <x-stat label="Held out of the books" :value="money($posting['value'])" icon="clock" :tone="$posting['value'] ? 'amber' : 'green'" sub="the variance the ledger has not been told about yet"/>
      <x-stat label="Oldest draft" :value="$posting['oldest_days'].' d'" icon="alert" :tone="$posting['oldest_tone']"
        :sub="$posting['oldest'] ? $posting['oldest']->take_no.' counted '.dmy($posting['oldest']->count_date) : 'no draft is waiting'"/>
      <x-stat label="Adjustments posted" :value="$variance['adjusted']" icon="check" tone="slate" sub="stock movements written by the posted counts, one per line that moved"/>
    </div>
    @if($posting['rows'])
      <p class="small {{ $posting['oldest_days'] > 7 ? 'text-red' : '' }}" id="stPostingState" style="margin:.6rem 0 0"><x-icon name="clock"/>
        {{ $posting['oldest']->take_no }} was counted on {{ dmy($posting['oldest']->count_date) }} and has been waiting
        {{ $posting['oldest_days'] }} day{{ $posting['oldest_days'] === 1 ? '' : 's' }} to be posted, with
        {{ $summary[$posting['oldest']->id]['counted'] }} of its {{ $summary[$posting['oldest']->id]['lines'] }} lines filled in.
        Until it is posted the shelf and the book disagree by {{ money(abs($summary[$posting['oldest']->id]['value'])) }},
        and the re-order suggestions are still being made on the book.
      </p>
      <div class="table-wrap" style="margin-top:.6rem"><table class="table table-compact" id="postingTable">
        <thead><tr><th>Take</th><th>Store</th><th>Count date</th><th class="num">Age</th><th class="num">Counted</th><th class="num">Value out</th></tr></thead><tbody>
        @foreach($posting['rows'] as $r)<tr data-age="{{ $r['days'] }}">
          <td class="bold">{{ $r['take'] }}</td><td class="small">{{ $r['store'] }}</td><td class="small">{{ dmy($r['date']) }}</td>
          <td class="num {{ $r['tone'] }}">{{ $r['days'] }} d</td>
          <td class="num">{{ $r['counted'] }}/{{ $r['lines'] }}</td>
          <td class="num">{{ money($r['value'], false) }}</td></tr>@endforeach
        </tbody></table></div>
    @else
      <p class="small" id="stPostingState" style="margin:.6rem 0 0"><x-icon name="check"/>Nothing is waiting: every count that was taken has been posted or cancelled, so the book and the shelf are as close as the last count made them.</p>
    @endif
  </div>
</div>

<div class="grid g-2-1">
  <div class="card flush"><div class="card-head"><h3>Count sheets</h3></div><div class="card-body table-wrap"><table class="table" id="takeTable">
    <thead><tr><th>No.</th><th>Store</th><th>Count date</th><th class="num">Items</th><th class="num">Counted</th><th class="num">Coverage</th><th class="num">Adjusted</th><th class="num">Variance value</th><th>Status</th><th>By</th></tr></thead><tbody>
    @forelse($takes as $t)<tr data-status="{{ $t->status }}" data-coverage="{{ $summary[$t->id]['pct'] ?? '' }}">
      <td><a class="bold" href="{{ route('stocktakes.show', $t) }}">{{ $t->take_no }}</a></td><td>{{ $t->store->name }}</td><td>{{ $t->count_date->format('d M Y') }}</td>
      <td class="num">{{ $summary[$t->id]['lines'] }}</td><td class="num">{{ $summary[$t->id]['counted'] }}</td>
      <td class="num {{ ($summary[$t->id]['pct'] ?? 100) < 95 && $t->status === 'posted' ? 'text-red' : '' }}">{{ $summary[$t->id]['pct'] ?? '—' }}{{ $summary[$t->id]['pct'] !== null ? ' %' : '' }}</td>
      <td class="num">{{ $summary[$t->id]['varied'] ?: '—' }}</td>
      <td class="num {{ $t->variance_value < 0 ? 'text-red' : '' }}">{{ $t->status === 'posted' ? money($t->variance_value, false) : '—' }}</td>
      <td>{!! status_badge($t->status) !!}</td><td class="small">{{ $t->creator?->name }}@if($t->poster)<div class="tiny muted">posted by {{ $t->poster->name }}</div>@endif</td>
    </tr>@empty<tr><td colspan="10"><x-empty text="No stock takes yet. Create a count sheet to begin." icon="box"/></td></tr>@endforelse
    </tbody></table></div></div>
  @if(can_do('inventory.manage'))
  <div class="card"><div class="card-head"><h3><x-icon name="plus"/> New count sheet</h3></div><div class="card-body">
    <form method="post" action="{{ route('stocktakes.store') }}" id="takeForm">@csrf
      <div class="form-grid" style="grid-template-columns:1fr">
        <x-field name="store_id" label="Store" type="select" :options="$stores" required/>
        <x-field name="count_date" label="Count date" type="date" :value="bdate()->toDateString()" required/>
        <x-field name="item_category_id" label="Category (optional)" type="select" :options="$categories" placeholder="All items"/>
        <x-field name="notes" label="Notes" type="textarea"/>
      </div>
      <p class="tiny muted">System quantities are frozen now. Receipts or issues made during the count are not double-counted.</p>
      <button class="btn btn-primary mt-1">Create count sheet</button>
    </form>
  </div></div>
  @endif
</div>
{{ $takes->links() }}
<p class="tiny muted mt-1" id="stChain">A count sheet closes the stock loop the way a goods receipt opens it: the <a href="{{ route('grn.index') }}">receipt</a> puts stock on the book, the <a href="{{ route('req.index') }}">issue</a> takes it off, and the count is the only document that checks either of them against the shelf. It is worth nothing until it is posted — and even then only for the lines somebody actually counted.</p>
@endsection
