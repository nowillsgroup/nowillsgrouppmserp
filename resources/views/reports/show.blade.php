@extends('layouts.app')
@section('title', $title)
@section('crumbs') <a href="{{ route('reports.index') }}">Reports</a> / {{ $title }} @endsection
@section('actions')
<a class="btn" href="{{ request()->fullUrlWithQuery(['export' => 'csv']) }}"><x-icon name="download"/> Excel / CSV</a>
<button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>
<button class="btn" type="button" data-modal="save-view"><x-icon name="star"/> Save view</button>
@if(can_do('reports.schedule'))<a class="btn" href="{{ route('reports.schedules', ['new' => $report] + ($activeView ? ['view' => $activeView->id] : [])) }}"><x-icon name="mail"/> Schedule</a>@endif
@endsection
@section('content')
@php $cols = $data['cols']; $rows = $data['rows']; $money = $data['money']; $sum = $data['sum'];
  $RS = \App\Services\ReportScheduleService::class;
  $rangeOpts = $mode === 'date' ? array_intersect_key($RS::RANGES, array_flip([...$RS::DATE_RANGES, 'custom'])) : ($mode === 'range' ? $RS::RANGES : ['today' => 'Current position']);
  $myView = $activeView && $activeView->user_id === auth()->id(); @endphp
@if($views->count() || $activeView)
<div class="card no-print" data-testid="saved-views"><div class="card-body flex gap-1 wrap" style="align-items:center">
  <span class="small muted"><x-icon name="star"/> Saved views:</span>
  @foreach($views as $v)
    <a class="btn btn-sm {{ $activeView?->id === $v->id ? 'btn-primary' : 'btn-soft' }}" href="{{ $v->url() }}" title="{{ $RS::RANGES[$v->range] ?? $v->range }}{{ $v->user_id !== auth()->id() ? ' · shared by '.$v->user?->name : '' }}">{{ $v->name }}@if($v->is_shared) <span class="tiny">· shared</span>@endif</a>
  @endforeach
  @if($activeView)
    <span class="small muted" style="margin-left:auto" data-testid="active-view">Showing <b>{{ $activeView->name }}</b> · {{ $RS::RANGES[$activeView->range] ?? $activeView->range }}@if($activeView->columns) · {{ count($cols) }} of {{ count($allCols) }} columns @endif @if($activeView->filter) · filter “{{ $activeView->filter }}”@endif</span>
    @if($myView)<button type="button" class="btn btn-sm" data-modal="edit-view">Edit view</button>@endif
    <a class="btn btn-sm btn-soft" href="{{ route('reports.show', $report) }}">Clear</a>
  @endif
</div></div>
@endif
@if($mode === 'range')
<div class="card no-print"><div class="card-body"><form class="filters">
  <div class="field"><label>From</label><input type="date" name="from" value="{{ $from->toDateString() }}"></div>
  <div class="field"><label>To</label><input type="date" name="to" value="{{ $to->toDateString() }}"></div>
  <div class="flex gap-1">
    <a class="btn btn-sm btn-soft" href="?from={{ bdate()->toDateString() }}&to={{ bdate()->toDateString() }}">Today</a>
    <a class="btn btn-sm btn-soft" href="?from={{ bdate()->copy()->subDay()->toDateString() }}&to={{ bdate()->copy()->subDay()->toDateString() }}">Yesterday</a>
    <a class="btn btn-sm btn-soft" href="?from={{ bdate()->copy()->startOfMonth()->toDateString() }}&to={{ bdate()->toDateString() }}">MTD</a>
    <a class="btn btn-sm btn-soft" href="?from={{ bdate()->copy()->subMonthNoOverflow()->startOfMonth()->toDateString() }}&to={{ bdate()->copy()->subMonthNoOverflow()->endOfMonth()->toDateString() }}">Last month</a>
    <a class="btn btn-sm btn-soft" href="?from={{ bdate()->copy()->startOfYear()->toDateString() }}&to={{ bdate()->toDateString() }}">YTD</a>
    <select class="input input-sm" data-range-nav aria-label="More periods" style="max-width:170px"><option value="">More periods…</option>
      @foreach($RS::RANGES as $rk => $rl) @continue($rk === 'custom') @php [$pf, $pt] = $RS::resolve($rk); @endphp<option value="?from={{ $pf->toDateString() }}&to={{ $pt->toDateString() }}" @selected($range === $rk)>{{ $rl }}</option>@endforeach
    </select>
  </div>
  <button class="btn btn-primary">Generate</button>
</form></div></div>
@endif
@if($mode === 'date')
<div class="card no-print"><div class="card-body"><form class="filters">
  <div class="field"><label>As on (business date)</label><input type="date" name="to" value="{{ $to->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
  <div class="flex gap-1">
    <a class="btn btn-sm btn-soft" href="?to={{ bdate()->copy()->subDay()->toDateString() }}">Last audited day</a>
    <a class="btn btn-sm btn-soft" href="?to={{ bdate()->toDateString() }}">Today (so far)</a>
    <a class="btn btn-sm btn-soft" href="?to={{ bdate()->copy()->subMonthNoOverflow()->endOfMonth()->toDateString() }}">Last month end</a>
  </div>
  <button class="btn btn-primary">Generate</button>
</form></div></div>
@endif
<div class="print-only print-head"><h2>{{ hotel()->name }}</h2><div>{{ $title }} @if($mode==='range') · {{ $from->format('d M Y') }} – {{ $to->format('d M Y') }} @elseif($mode==='date') · as on {{ $to->format('d M Y') }} @else · as on {{ bdate()->format('d M Y') }} @endif</div></div>

@if(!empty($data['chart']) && $rows->count() > 1)
@php $ck = $data['chart']['key']; $max = max(1, $rows->max($ck)); $chartRows = $rows->take(40); @endphp
<div class="card no-print"><div class="card-head"><h3>{{ $data['chart']['label'] }}</h3></div><div class="card-body">
  <div class="bar-chart">@foreach($chartRows as $r)<div class="col"><div class="b" data-v="{{ $r[$data['chart']['x']] }}: {{ $r[$ck] }}" style="height:{{ max(1, round($r[$ck] / $max * 100)) }}%"></div><small>{{ \Illuminate\Support\Str::limit((string) $r[$data['chart']['x']], 5, '') }}</small></div>@endforeach</div>
</div></div>
@endif

@if(!empty($data['summary']) && count($data['summary']))
<div class="stats">@foreach($data['summary'] as $k => $v)<x-stat :label="$k ?: '—'" :value="is_string($v) ? $v : money($v)" icon="money" tone="slate"/>@endforeach</div>
@endif

<div class="card flush"><div class="card-head"><h3>{{ $rows->count() }} record(s)</h3><input class="input input-sm no-print" placeholder="Quick filter…" data-filter-table="#rpt" style="max-width:220px"></div>
<div class="card-body table-wrap"><table class="table table-compact" id="rpt">
<thead><tr><th>#</th>@foreach($cols as $k => $l)<th class="{{ in_array($k, $money) || in_array($k, $sum) ? 'num' : '' }}">{{ $l }}</th>@endforeach</tr></thead>
<tbody>
@forelse($rows as $r)
<tr class="{{ $r['_class'] ?? '' }}"><td class="muted tiny">{{ $loop->iteration }}</td>@foreach($cols as $k => $l)<td class="{{ in_array($k, $money) || in_array($k, $sum) ? 'num' : '' }}">{{ in_array($k, $money) ? (! isset($r[$k]) ? '' : money($r[$k], false)) : ($r[$k] ?? '') }}</td>@endforeach</tr>
@empty
<tr><td colspan="{{ count($cols) + 1 }}"><x-empty text="No data for the selected period." icon="chart"/></td></tr>
@endforelse
</tbody>
@if($rows->count() && count($sum))
<tfoot><tr><td></td>@foreach($cols as $k => $l)<td class="num">@if(in_array($k, $sum)){{ in_array($k, $money) ? money($rows->sum($k), false) : $rows->sum($k) }}@elseif($loop->first) Total @endif</td>@endforeach</tr></tfoot>
@endif
</table></div></div>
@if(!empty($data['note']))<p class="small muted">{{ $data['note'] }}</p>@endif

@foreach(['save-view' => null] + ($myView ? ['edit-view' => $activeView] : []) as $mid => $ev)
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:720px">
  <form method="POST" action="{{ $ev ? route('reports.views.update', $ev) : route('reports.views.store') }}">@csrf @if($ev) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $ev ? 'Edit view “'.$ev->name.'”' : 'Save this report as a view' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <input type="hidden" name="report_key" value="{{ $report }}">
    <input type="hidden" name="date_from" value="{{ $ev?->date_from?->toDateString() ?? $from->toDateString() }}"><input type="hidden" name="date_to" value="{{ $ev?->date_to?->toDateString() ?? $to->toDateString() }}">
    <div class="form-grid">
      <div class="field"><label>Name</label><input class="input" name="name" required maxlength="120" value="{{ $ev?->name }}" placeholder="e.g. {{ $title }} – {{ $RS::RANGES[$range] ?? 'custom' }}"></div>
      <div class="field"><label>Date range</label><select class="input" name="range">@foreach($rangeOpts as $rk => $rl)<option value="{{ $rk }}" @selected(($ev?->range ?? $range) === $rk)>{{ $rk === 'custom' ? 'Fixed: '.$from->format('d M').' – '.$to->format('d M Y') : $rl }}</option>@endforeach</select>
        <small class="muted">Relative ranges always follow the current business date.</small></div>
    </div>
    <div class="field"><label>Columns <span class="muted tiny">(untick to hide)</span></label>
      <div class="flex gap-1 wrap">@foreach($allCols as $ck => $cl)<label class="check small"><input type="checkbox" name="columns[]" value="{{ $ck }}" @checked(! $ev || ! $ev->columns ? isset($cols[$ck]) : in_array($ck, $ev->columns))> {{ $cl }}</label>@endforeach</div></div>
    <div class="form-grid">
      <div class="field"><label>Only rows containing</label><input class="input" name="filter" maxlength="100" value="{{ $ev?->filter ?? $activeView?->filter }}" placeholder="optional text filter"></div>
      <div class="field"><label>&nbsp;</label>
        <label class="check"><input type="checkbox" name="is_pinned" value="1" @checked($ev?->is_pinned)> Pin to my dashboard</label>
        @if(can_do('reports.schedule'))<label class="check"><input type="checkbox" name="is_shared" value="1" @checked($ev?->is_shared)> Share with colleagues who can see this report</label>@endif
      </div>
    </div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">{{ $ev ? 'Update view' : 'Save view' }}</button></div>
  </form>
</div></div>
@endforeach
@push('scripts')
<script>document.querySelectorAll('[data-range-nav]').forEach(s => s.addEventListener('change', () => { if (s.value) location.href = s.value; }));</script>
@endpush
@endsection
