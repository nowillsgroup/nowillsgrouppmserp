@extends('layouts.app')
@section('title', 'Reports')
@section('crumbs') Reports @endsection
@section('actions')
<a class="btn" href="{{ route('reports.library') }}"><x-icon name="star"/> My reports</a>
@if(can_do('reports.schedule'))<a class="btn" href="{{ route('reports.schedules') }}"><x-icon name="mail"/> Schedules</a>@endif
@if(can_do('reports.alerts'))<a class="btn" href="{{ route('reports.alerts') }}"><x-icon name="alert"/> KPI alerts</a>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Reports you can open" :value="$total" icon="chart" tone="blue" :sub="count($groups).' areas · everything the property files'"/>
  <x-stat label="Saved views" :value="$views->count()" icon="star" tone="purple" :href="route('reports.library')"
          :sub="$views->count() ? $mine.' mine · '.$shared.' shared with me' : 'save any report to keep it here'"/>
  @if($pinned->count())
    <x-stat label="Pinned to my dashboard" :value="$pinned->count()" icon="grid" tone="green" sub="open straight from the dashboard"/>
  @endif
  @if($schedules !== null)
    <x-stat label="Scheduled reports" :value="$schedules" icon="mail" tone="slate" :href="route('reports.schedules')" sub="e-mailed on your behalf"/>
  @endif
</div>

@if($views->isNotEmpty())
<div class="card rpt-views no-print">
  <div class="card-body">
    <span class="small muted"><x-icon name="star"/> Your views:</span>
    <div class="rpt-view-links">
      @foreach($views as $v)
        <a class="rpt-view" href="{{ $v->url() }}" title="{{ $v->title() }} · {{ \App\Services\ReportScheduleService::RANGES[$v->range] ?? $v->range }}">
          <b>{{ $v->name }}</b>
          <span>{{ $v->title() }}</span>
          @if($v->is_pinned)<span class="badge badge-purple">pinned</span>@endif
          @if($v->is_shared)<span class="badge badge-blue">{{ $v->user_id === auth()->id() ? 'shared by you' : 'shared with you' }}</span>@endif
        </a>
      @endforeach
      <a class="btn btn-sm btn-ghost" href="{{ route('reports.library') }}">All saved views</a>
    </div>
  </div>
</div>
@endif

@if($total === 0)
{{-- A role with no report rights reaches this page only by permission, so say it plainly
     rather than showing an empty search box over nothing. --}}
<div class="card" data-testid="reports-none"><div class="card-body">
  <x-empty icon="lock" text="No report is open to your role yet. Ask an administrator for the reports permission this property uses."/>
</div></div>
@else

<div class="card rpt-toolbar no-print">
  <div class="rpt-search">
    <x-icon name="search"/>
    <input type="search" id="reportSearch" placeholder="Search {{ $total }} reports – by name, or by the question they answer…" aria-label="Search reports by name or description" autocomplete="off" spellcheck="false">
    <button type="button" class="btn btn-sm" id="reportReset" hidden>Show all</button>
  </div>
  <div class="rpt-chips" role="group" aria-label="Filter reports by area">
    <button type="button" class="rpt-chip is-on" data-group-filter="" aria-pressed="true">All areas <span>{{ $total }}</span></button>
    @foreach($groups as $key => $g)
      <button type="button" class="rpt-chip" data-group-filter="{{ $key }}" aria-pressed="false">{{ $g['name'] }} <span>{{ count($g['reports']) }}</span></button>
    @endforeach
  </div>
  <p class="rpt-meta" id="reportCount" aria-live="polite">{{ $total }} reports in {{ count($groups) }} areas</p>
  <p class="rpt-hint">Shorthand works too: <code>p&amp;l</code>, <code>ADR</code>, <code>occ</code>, <code>MoMo</code>, <code>GL</code>, <code>OTB</code>, <code>F&amp;B</code>, <code>VAT</code>.</p>
</div>

<div class="print-only print-head"><h2>{{ hotel()->name }}</h2><div>Report library · {{ $total }} reports · {{ count($groups) }} areas</div></div>

<div class="rpt-groups" id="reportGroups">
  @foreach($groups as $key => $g)
    <section class="card rpt-group" data-report-group="{{ $key }}" data-report-name="{{ strtolower($key) }}">
      <div class="card-head">
        <h3><x-icon :name="$g['icon']"/> {{ $g['name'] }}</h3>
        <span class="rpt-tally">{{ count($g['reports']) }}</span>
      </div>
      <ul class="rpt-list">
        @foreach($g['reports'] as $r)
          <li data-report-row>
            <a href="{{ route('reports.show', $r['slug']) }}" title="{{ $r['desc'] }}">
              <span class="rpt-title">{{ $r['title'] }}</span>
              <span class="rpt-mode {{ $r['mode'] === 'Live' ? 'is-live' : ($r['mode'] === 'As on date' ? 'is-date' : '') }}">{{ $r['mode'] }}</span>
              <span class="rpt-desc">{{ $r['desc'] }}</span>
            </a>
          </li>
        @endforeach
      </ul>
    </section>
  @endforeach
</div>

<div class="card" id="reportEmpty" hidden>
  <div class="card-body">
    <x-empty icon="search" text="No report matches that search.">
      <button type="button" class="btn btn-sm" data-empty-reset>Show every report</button>
    </x-empty>
  </div>
</div>
@endif
@endsection
@push('scripts')
<script>
(() => {
  // The desk types shorthand, not report titles: "P&L", "occ", "MoMo". A whole-word
  // match on one of these searches the words the reports are written in instead.
  // Typing one of these on its own searches the words the reports are written in, so
  // "P&L" finds Profit & Loss and "AP" finds the supplier ledger without also dragging
  // in every report that happens to contain the letters a-p.
  const SHORTHAND = {
    'p&l': ['profit', 'loss'],
    'pl': ['profit', 'loss'],
    'pnl': ['profit', 'loss'],
    'adr': ['adr', 'average daily rate'],
    'revpar': ['revpar', 'revenue per available'],
    'occ': ['occupancy'],
    'momo': ['mobile money', 'momo'],
    'gl': ['general ledger'],
    'vat': ['vat', 'tax', 'levy'],
    'otb': ['on-the-books', 'forecast', 'otb'],
    'fnb': ['f&b', 'fnb'],
    'fb': ['f&b', 'fnb'],
    'kpi': ['kpi', 'scorecard'],
    'ar': ['city ledger', 'receivable'],
    'ap': ['supplier', 'payable'],
  };

  const search = document.getElementById('reportSearch');
  const groups = [...document.querySelectorAll('[data-report-group]')];
  const chips = [...document.querySelectorAll('[data-group-filter]')];
  const count = document.getElementById('reportCount');
  const empty = document.getElementById('reportEmpty');
  const reset = document.getElementById('reportReset');
  let area = '';

  const apply = () => {
    const q = (search?.value ?? '').trim().toLowerCase();
    const terms = q ? (SHORTHAND[q] ?? [q]) : [];
    const matches = (text) => !terms.length || terms.some(term => text.includes(term));
    let reports = 0, areas = 0;
    groups.forEach(group => {
      const nameHit = matches(group.dataset.reportName ?? '');
      const inArea = !area || group.dataset.reportGroup === area;
      let visible = 0;
      group.querySelectorAll('[data-report-row]').forEach(row => {
        const hit = inArea && (nameHit || matches(row.textContent.toLowerCase()));
        row.hidden = !hit;
        if (hit) visible += 1;
      });
      group.hidden = visible === 0;
      if (visible) { areas += 1; reports += visible; }
    });
    if (count) {
      count.textContent = `${reports} report${reports === 1 ? '' : 's'} in ${areas} area${areas === 1 ? '' : 's'}`
        + (q ? ` matching “${q}”` : (area ? ` · ${area}` : ''));
    }
    if (empty) empty.hidden = reports > 0;
    if (reset) reset.hidden = !q && !area;
  };

  const clear = () => {
    if (search) search.value = '';
    area = '';
    chips.forEach((chip, index) => {
      const on = index === 0;
      chip.classList.toggle('is-on', on);
      chip.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    apply();
    search?.focus();
  };

  search?.addEventListener('input', apply);
  chips.forEach(chip => chip.addEventListener('click', () => {
    area = chip.dataset.groupFilter ?? '';
    chips.forEach(c => {
      const on = c === chip;
      c.classList.toggle('is-on', on);
      c.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    apply();
  }));
  reset?.addEventListener('click', clear);
  document.querySelector('[data-empty-reset]')?.addEventListener('click', clear);
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && (search?.value || area)) clear();
  });
  apply();
})();
</script>
@endpush
