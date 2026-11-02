@extends('layouts.app')
@section('title', 'Group Blocks')
@section('crumbs') Reservations / Group Blocks @endsection
@section('actions')
  @if(can_do('reservations.create'))<a href="{{ route('blocks.create') }}" class="btn btn-icon" aria-label="Create group block" title="New group block"><x-icon name="plus"/></a>@endif
@endsection
@section('content')
@php
  $activeStatus = request('status');
  $blockTabParams = request()->only('q');
  $holdingCount = (int) ($statusCounts['tentative'] ?? 0) + (int) ($statusCounts['definite'] ?? 0);
  $blockTabs = [
      ['label' => 'Holding', 'status' => '', 'count' => $holdingCount],
      ['label' => 'Tentative', 'status' => 'tentative', 'count' => (int) ($statusCounts['tentative'] ?? 0)],
      ['label' => 'Definite', 'status' => 'definite', 'count' => (int) ($statusCounts['definite'] ?? 0)],
      ['label' => 'Released', 'status' => 'released', 'count' => (int) ($statusCounts['released'] ?? 0)],
      ['label' => 'Cancelled', 'status' => 'cancelled', 'count' => (int) ($statusCounts['cancelled'] ?? 0)],
  ];
  $blockRangeStart = $blocks->firstItem() ?? 0;
  $blockRangeEnd = $blocks->lastItem() ?? 0;
@endphp
<section class="gb-hero" aria-label="Group room allotments">
  <span class="gb-hero-mark"><x-icon name="users"/></span>
  <div class="gb-hero-copy">
    <div class="gb-eyebrow">GROUP SALES <span aria-hidden="true">/</span> ROOM ALLOTMENTS</div>
    <p>Hold room inventory for weddings, conferences and tours. Picked-up guests become linked reservations; unpicked rooms return to general inventory at cut-off.</p>
  </div>
  <div class="gb-hero-stats" aria-label="Group block totals">
    <div class="gb-hero-stat"><span>Open blocks</span><strong>{{ number_format($holdingCount) }}</strong></div>
    <div class="gb-hero-stat"><span>In this view</span><strong>{{ number_format($blocks->total()) }}</strong></div>
  </div>
</section>

<nav class="gb-tabs" aria-label="Filter group blocks by status">
  @foreach($blockTabs as $tab)
    @php
      $tabActive = $tab['status'] === '' ? ! request()->filled('status') : $activeStatus === $tab['status'];
      $tabQuery = $blockTabParams;
      if ($tab['status'] !== '') $tabQuery['status'] = $tab['status'];
    @endphp
    <a class="gb-tab {{ $tabActive ? 'active' : '' }}" href="{{ route('blocks.index', $tabQuery) }}" @if($tabActive) aria-current="page" @endif>
      <span>{{ $tab['label'] }}</span><span class="gb-tab-count">{{ number_format($tab['count']) }}</span>
    </a>
  @endforeach
</nav>

<section class="gb-filter-card" aria-label="Search group blocks">
  <form class="gb-filter-form" method="get" role="search">
    @if(request()->filled('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
    <label class="gb-search-label" for="gbSearch">Find a block</label>
    <div class="gb-search-control"><x-icon name="search" size="sm"/><input id="gbSearch" type="search" name="q" value="{{ request('q') }}" placeholder="Block number or group / event name" autocomplete="off"></div>
    <button type="submit" class="btn btn-primary"><x-icon name="search" size="sm"/> Search</button>
    @if(request()->filled('q'))<a class="btn btn-ghost" href="{{ route('blocks.index', request()->only('status')) }}">Clear search</a>@endif
  </form>
  <div class="gb-filter-meta">
    <span><b>{{ number_format($blocks->total()) }}</b> {{ \Illuminate\Support\Str::plural('block', $blocks->total()) }}</span>
    @if($blocks->total())<span>Showing {{ number_format($blockRangeStart) }}–{{ number_format($blockRangeEnd) }}</span>@endif
  </div>
</section>

<section class="card flush gb-table-card" aria-label="Group block register">
  <div class="card-head gb-table-head"><div><h2>Block register</h2><p>Dates, room allotments, pickup and agreed rate for each group.</p></div><span class="gb-table-total">{{ number_format($blocks->total()) }} total</span></div>
  <div class="card-body table-wrap gb-table-wrap">
    <table class="table gb-table">
      <caption class="sr-only">Group room block register</caption>
      <thead><tr>
        <th scope="col">Block / group</th><th scope="col">Company / contact</th><th scope="col">Arrival</th><th scope="col">Departure</th><th scope="col">Cut-off</th><th scope="col">Room allotment</th><th scope="col">Pickup</th><th scope="col">Rate / night</th><th scope="col">Status</th>
      </tr></thead>
      <tbody>
      @forelse($blocks as $b)
        @php
          $cutoffSoon = $b->isHolding() && $b->cutoff_date->lte(bdate()->copy()->addDays(3));
          $daysToCutoff = (int) bdate()->copy()->startOfDay()->diffInDays($b->cutoff_date->copy()->startOfDay(), false);
        @endphp
        <tr>
          <td data-label="Block / group"><a href="{{ route('blocks.show', $b) }}" class="gb-block-link"><span class="gb-block-no">{{ $b->block_no }}</span><span class="gb-block-name">{{ $b->name }}</span></a></td>
          <td data-label="Company / contact"><div class="gb-company">{{ $b->company?->name ?? 'Direct group' }}</div><div class="gb-contact">{{ $b->contact_name ?: 'No contact listed' }}@if($b->contact_phone) <span aria-hidden="true">·</span> {{ $b->contact_phone }}@endif</div></td>
          <td class="nowrap" data-label="Arrival"><span class="gb-date">{{ $b->arrival_date->format('d M Y') }}</span></td>
          <td class="nowrap" data-label="Departure"><span class="gb-date">{{ $b->departure_date->format('d M Y') }}</span></td>
          <td class="nowrap {{ $cutoffSoon ? 'gb-cutoff-soon' : '' }}" data-label="Cut-off"><span class="gb-date">{{ $b->cutoff_date->format('d M Y') }}</span>@if($cutoffSoon)<span class="gb-cutoff-note">{{ $daysToCutoff < 0 ? 'Past cut-off' : ($daysToCutoff === 0 ? 'Due today' : 'Due in '.$daysToCutoff.' days') }}</span>@endif</td>
          <td data-label="Room allotment"><div class="gb-room-chips">
            @forelse($b->lines as $line)<span class="gb-room-chip"><b>{{ $line->rooms }}×</b> {{ $line->roomType?->code ?? $line->roomType?->name ?? 'Room type' }}</span>@empty<span class="muted">No rooms held</span>@endforelse
          </div></td>
          <td data-label="Pickup"><div class="gb-pickup" role="progressbar" aria-label="Pickup for {{ $b->block_no }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $b->pickup_pct) }}"><span style="width:{{ min(100, $b->pickup_pct) }}%"></span></div><span class="gb-pickup-label">{{ $b->pickup_pct }}% picked up</span></td>
          <td data-label="Rate / night"><span class="gb-rate">{{ $b->rate !== null ? money($b->rate) : 'Rate plan' }}</span>@if($b->rate === null)<span class="gb-rate-note">Property plan</span>@endif</td>
          <td data-label="Status"><span class="gb-status">{!! status_badge($b->status) !!}</span></td>
        </tr>
      @empty
        <tr><td colspan="9" class="empty gb-empty-cell">
          <div class="gb-empty-state"><span class="gb-empty-icon"><x-icon name="users"/></span><div><strong>{{ request()->filled('q') ? 'No group blocks match this search' : (request()->filled('status') ? 'No '.strtolower(request('status')).' blocks yet' : 'No open group blocks') }}</strong><p>{{ request()->filled('q') ? 'Try a different block number or group / event name.' : 'Room allotments will appear here once they are created for a group stay.' }}</p></div>
          @if(request()->filled('q') || request()->filled('status'))<a class="btn btn-sm btn-ghost" href="{{ route('blocks.index') }}">Clear filters</a>@elseif(can_do('reservations.create'))<a class="btn btn-sm btn-primary" href="{{ route('blocks.create') }}"><x-icon name="plus" size="sm"/> Create first block</a>@endif
          </div>
        </td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</section>
@if($blocks->hasPages())<div class="gb-pagination">{{ $blocks->links() }}</div>@endif

@push('head')
<style>
.gb-hero{display:flex;align-items:center;gap:16px;margin:0 0 16px;padding:18px 20px;border:1px solid rgba(20,83,45,.12);border-radius:14px;color:#fff;background:radial-gradient(circle at 84% 0,rgba(187,247,208,.2),transparent 28%),linear-gradient(118deg,#103a23,#176239 68%,#247849);box-shadow:0 12px 28px rgba(20,83,45,.11)}
.gb-hero-mark{display:inline-flex;width:46px;height:46px;flex:0 0 46px;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,.24);border-radius:13px;background:rgba(255,255,255,.11);color:#d7f5df}
.gb-hero-mark .i{width:23px;height:23px}
.gb-hero-copy{min-width:0;flex:1}
.gb-eyebrow{margin-bottom:4px;color:#c8e8d0;font-size:10px;font-weight:800;letter-spacing:.12em}
.gb-eyebrow span{padding:0 4px;color:rgba(255,255,255,.62)}
.gb-hero-copy p{max-width:690px;margin:0;color:rgba(255,255,255,.84);font-size:13px;line-height:1.5}
.gb-hero-stats{display:flex;gap:8px;flex:0 0 auto}
.gb-hero-stat{display:flex;min-width:104px;flex-direction:column;gap:2px;padding:8px 11px;border:1px solid rgba(255,255,255,.2);border-radius:10px;background:rgba(255,255,255,.08)}
.gb-hero-stat span{color:rgba(255,255,255,.72);font-size:10px;font-weight:650}
.gb-hero-stat strong{color:#fff;font-size:18px;font-variant-numeric:tabular-nums;line-height:1.1}
.gb-tabs{display:flex;gap:6px;overflow-x:auto;margin-bottom:12px;padding:3px 2px 7px;scrollbar-width:thin}
.gb-tab{display:inline-flex;min-height:38px;flex:0 0 auto;align-items:center;gap:8px;padding:0 11px;border:1px solid var(--c-border);border-radius:9px;background:var(--c-surface);color:var(--c-text-2);font-size:12px;font-weight:700;text-decoration:none;transition:background-color .14s ease,border-color .14s ease,color .14s ease}
.gb-tab:hover{border-color:#86c79b;background:#f0fdf4;color:#14532d}
.gb-tab.active{border-color:#15803d;background:#ecfdf3;color:#14532d;box-shadow:0 2px 7px rgba(20,83,45,.08)}
.gb-tab-count{display:inline-flex;min-width:22px;height:22px;align-items:center;justify-content:center;padding:0 6px;border-radius:999px;background:#f1f5f9;color:#64748b;font-size:10px;font-variant-numeric:tabular-nums}
.gb-tab.active .gb-tab-count{background:#d1fae5;color:#166534}
.gb-filter-card{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;margin-bottom:12px;padding:13px 15px;border:1px solid var(--c-border);border-radius:12px;background:var(--c-surface);box-shadow:0 2px 8px rgba(15,23,42,.035)}
.gb-filter-form{display:flex;min-width:0;flex:1;align-items:flex-end;gap:8px;flex-wrap:wrap}
.gb-search-label{width:100%;color:var(--c-muted);font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
.gb-search-control{display:flex;min-width:220px;max-width:520px;min-height:38px;flex:1;align-items:center;gap:8px;padding:0 10px;border:1px solid var(--c-border);border-radius:8px;background:var(--c-bg)}
.gb-search-control .i{width:15px;height:15px;flex:0 0 15px;color:var(--c-muted)}
.gb-search-control input{width:100%;min-width:0;border:0;outline:0;background:transparent;color:var(--c-text);font:inherit;font-size:12px}
.gb-search-control:focus-within{border-color:#15803d;box-shadow:0 0 0 3px rgba(21,128,61,.12)}
.gb-filter-form>.btn{min-height:38px}
.gb-filter-meta{display:flex;gap:12px;align-items:center;padding-bottom:9px;color:var(--c-muted);font-size:11px;white-space:nowrap}
.gb-filter-meta b{color:var(--c-text);font-variant-numeric:tabular-nums}
.gb-table-card{overflow:hidden;border:1px solid var(--c-border);border-radius:13px;box-shadow:0 3px 12px rgba(15,23,42,.04)}
.gb-table-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 17px;border-bottom:1px solid var(--c-border)}
.gb-table-head h2{margin:0;font-size:14px;font-weight:800;letter-spacing:-.01em}
.gb-table-head p{margin:3px 0 0;color:var(--c-muted);font-size:11px}
.gb-table-total{padding:5px 9px;border:1px solid var(--c-border);border-radius:999px;background:var(--c-bg);color:var(--c-muted);font-size:10px;font-weight:750;font-variant-numeric:tabular-nums;white-space:nowrap}
.gb-table-wrap{padding:0}
.gb-table{min-width:1040px;margin:0;border-collapse:separate;border-spacing:0;font-size:12px}
.gb-table thead th{position:sticky;top:0;z-index:1;padding:10px 11px;border-bottom:1px solid var(--c-border);background:var(--c-bg);color:var(--c-muted);font-size:9px;font-weight:800;letter-spacing:.065em;text-transform:uppercase;white-space:nowrap}
.gb-table tbody td{padding:12px 11px;border-bottom:1px solid var(--c-border);vertical-align:middle}
.gb-table tbody tr:last-child td{border-bottom:0}
.gb-table tbody tr:hover td{background:rgba(34,139,78,.035)}
.gb-block-link{display:flex;min-width:145px;flex-direction:column;gap:3px;text-decoration:none}
.gb-block-no{color:#166534;font-size:12px;font-weight:850;letter-spacing:.015em}
.gb-block-name{color:var(--c-text);font-size:11px;font-weight:650;line-height:1.35}
.gb-block-link:hover .gb-block-no{text-decoration:underline;text-underline-offset:3px}
.gb-company{font-size:11px;font-weight:750}
.gb-contact{max-width:200px;margin-top:3px;color:var(--c-muted);font-size:10px;line-height:1.4;overflow-wrap:anywhere}
.gb-date{font-size:11px;font-variant-numeric:tabular-nums;white-space:nowrap}
.gb-cutoff-soon .gb-date{color:#b91c1c;font-weight:800}
.gb-cutoff-note{display:block;margin-top:3px;color:#b91c1c;font-size:9px;font-weight:750}
.gb-room-chips{display:flex;max-width:240px;flex-wrap:wrap;gap:4px}
.gb-room-chip{display:inline-flex;align-items:center;gap:3px;padding:4px 6px;border:1px solid #dbeafe;border-radius:6px;background:#eff6ff;color:#1e3a8a;font-size:9px;white-space:nowrap}
.gb-room-chip b{font-variant-numeric:tabular-nums}
.gb-pickup{width:96px;height:7px;overflow:hidden;border-radius:999px;background:#e2e8f0}
.gb-pickup>span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#15803d,#4ade80)}
.gb-pickup-label{display:block;margin-top:4px;color:var(--c-muted);font-size:9px;font-variant-numeric:tabular-nums;white-space:nowrap}
.gb-rate{display:block;font-size:11px;font-weight:750;white-space:nowrap}
.gb-rate-note{display:block;margin-top:3px;color:var(--c-muted);font-size:9px}
.gb-status{white-space:nowrap}
.gb-empty-cell{padding:0!important}
.gb-empty-state{display:flex;min-height:140px;align-items:center;justify-content:center;gap:14px;padding:22px;background:linear-gradient(135deg,var(--c-surface),var(--c-bg));text-align:left}
.gb-empty-state>div{min-width:0}
.gb-empty-state strong{display:block;color:var(--c-text);font-size:14px}
.gb-empty-state p{margin:4px 0 0;color:var(--c-muted);font-size:11px}
.gb-empty-icon{display:inline-flex;width:42px;height:42px;flex:0 0 42px;align-items:center;justify-content:center;border:1px solid #d1e7d7;border-radius:12px;background:#f0fdf4;color:#166534}
.gb-empty-icon .i{width:20px;height:20px}
.gb-pagination{display:flex;justify-content:flex-end;margin-top:12px}
.gb-pagination nav{max-width:100%;overflow-x:auto}
@media(max-width:1050px){.gb-hero{align-items:flex-start;flex-wrap:wrap}.gb-hero-copy{flex-basis:calc(100% - 66px)}.gb-hero-stats{margin-left:62px}}
@media(max-width:680px){
  .gb-hero{gap:11px;padding:14px}
  .gb-hero-mark{width:40px;height:40px;flex-basis:40px}
  .gb-hero-copy{flex-basis:calc(100% - 52px)}
  .gb-hero-copy p{font-size:12px}
  .gb-hero-stats{width:100%;margin-left:0}
  .gb-hero-stat{min-width:0;flex:1}
  .gb-filter-card{align-items:stretch;flex-direction:column;gap:9px;padding:11px}
  .gb-filter-form{display:grid;grid-template-columns:minmax(0,1fr) auto}
  .gb-search-label,.gb-search-control{grid-column:1/-1}
  .gb-search-control{min-width:0;max-width:none}
  .gb-filter-form>.btn{justify-content:center}
  .gb-filter-meta{justify-content:space-between;padding-bottom:0}
  .gb-table-head{align-items:flex-start;padding:12px}
  .gb-table-wrap{overflow-x:auto}
  .gb-empty-state{align-items:flex-start;flex-wrap:wrap;justify-content:flex-start;padding:16px}
  .gb-empty-state .btn{margin-left:56px}
}
@media(prefers-reduced-motion:reduce){.gb-tab{transition:none}}
body.dark .gb-tab:hover{border-color:#3b5946;background:#193525;color:#d7f5df}
body.dark .gb-tab.active{border-color:#3b5946;background:#193525;color:#d7f5df}
body.dark .gb-tab-count{background:#243229;color:#c1d2c5}
body.dark .gb-tab.active .gb-tab-count{background:#28523a;color:#bbf7d0}
body.dark .gb-room-chip{border-color:#294364;background:#172b4d;color:#bfdbfe}
body.dark .gb-cutoff-soon .gb-date,body.dark .gb-cutoff-note{color:#fca5a5}
body.dark .gb-pickup{background:#243229}
body.dark .gb-empty-icon{border-color:#3b5946;background:#193525;color:#86efac}
</style>
@endpush
@endsection
