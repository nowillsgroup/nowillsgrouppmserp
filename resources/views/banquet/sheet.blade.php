<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $hotel->name }} · Function sheet · {{ $date->format('d M Y') }}</title>
  <style>
    :root{color-scheme:light;--ink:#17251d;--ink-soft:#35473b;--muted:#68766d;--line:#dce5de;--surface:#fff;--canvas:#f3f7f4;--green:#166534;--green-soft:#ecf8ef;--green-line:#c8e7d0;--amber:#8a4b08;--amber-soft:#fff6e7;--amber-line:#f1d6a7;--blue:#1d4ed8;--blue-soft:#eff6ff;--slate:#475569;--shadow:0 12px 32px rgba(24,48,33,.07)}
    *{box-sizing:border-box}body{margin:0;background:var(--canvas);color:var(--ink);font:14px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}
    .sheet-shell{max-width:1440px;margin:0 auto;padding:22px 28px 40px}.sheet-toolbar{position:sticky;top:0;z-index:10;display:flex;align-items:center;flex-wrap:wrap;gap:8px;margin:-22px -28px 20px;padding:12px 28px;border-bottom:1px solid rgba(220,229,222,.9);background:rgba(255,255,255,.94);backdrop-filter:blur(12px)}
    .sheet-toolbar-spacer{flex:1}.sheet-toolbar a,.sheet-toolbar button,.sheet-toolbar input{font:inherit}.sheet-toolbar .tool-link,.sheet-toolbar button,.sheet-toolbar .date-field{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:38px;padding:7px 12px;border:1px solid var(--line);border-radius:9px;background:#fff;color:var(--ink-soft);text-decoration:none;font-weight:650}
    .sheet-toolbar .tool-link:hover,.sheet-toolbar button:hover{border-color:#a8cbb1;background:var(--green-soft);color:var(--green)}.sheet-toolbar .tool-link:focus-visible,.sheet-toolbar button:focus-visible,.sheet-toolbar input:focus-visible{outline:3px solid #9ed3aa;outline-offset:2px}
    .sheet-toolbar button{cursor:pointer}.sheet-toolbar .tool-primary{border-color:var(--green);background:var(--green);color:#fff}.sheet-toolbar .tool-primary:hover{background:#14532d;color:#fff}.sheet-toolbar .date-field{padding:4px 8px}.sheet-toolbar .date-field input{min-width:142px;border:0;background:transparent;color:var(--ink);font:inherit;font-weight:600}
    .sheet-cover{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin:0 0 18px;padding:24px 26px;border:1px solid var(--green-line);border-radius:18px;background:linear-gradient(115deg,#f5fcf6,#fff 68%);box-shadow:var(--shadow)}
    .sheet-kicker,.metric-label,.fact-label{color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.09em;text-transform:uppercase}.sheet-cover h1{margin:3px 0 3px;font-size:clamp(1.35rem,2.3vw,1.9rem);letter-spacing:-.035em;line-height:1.15}.sheet-cover .subtitle{margin:0;color:var(--ink-soft);font-size:.88rem}
    .sheet-date-card{flex:none;min-width:210px;padding:12px 15px;border:1px solid var(--green-line);border-radius:12px;background:rgba(255,255,255,.78);text-align:right}.sheet-date-card span{display:block;color:var(--muted);font-size:10px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}.sheet-date-card time{display:block;margin-top:2px;color:var(--green);font-size:1.16rem;font-weight:800;font-variant-numeric:tabular-nums}.sheet-date-card small{color:var(--muted);font-size:.75rem}
    .sheet-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:0 0 15px}.sheet-summary.has-actual{grid-template-columns:repeat(4,minmax(0,1fr))}.sheet-metric{display:flex;align-items:center;gap:12px;min-height:72px;padding:12px 15px;border:1px solid var(--line);border-radius:13px;background:var(--surface);box-shadow:0 2px 8px rgba(24,48,33,.035)}.metric-value{color:var(--ink);font-size:1.3rem;font-weight:800;font-variant-numeric:tabular-nums;line-height:1}.metric-copy{display:grid;gap:3px}.metric-copy small{color:var(--muted);font-size:.7rem}
    .sheet-statuses{display:flex;align-items:center;flex-wrap:wrap;gap:6px;margin:0 0 17px}.status-count{display:inline-flex;align-items:center;gap:7px;padding:5px 9px;border:1px solid var(--line);border-radius:999px;background:#fff;color:var(--ink-soft);font-size:.72rem}.status-count b{color:var(--ink);font-variant-numeric:tabular-nums}.status-dot{width:8px;height:8px;border-radius:50%;background:var(--dot)}.status-definite{--dot:#15803d}.status-tentative{--dot:#d97706}.status-completed{--dot:#2563eb}.status-tentative-note{margin-left:auto;border-color:var(--amber-line);background:var(--amber-soft);color:var(--amber);font-weight:650}
    .sheet-department-overview{display:flex;align-items:center;flex-wrap:wrap;gap:9px 14px;margin:0 0 15px;padding:10px 13px;border:1px solid var(--line);border-radius:12px;background:#fff}.sheet-department-overview-head{display:grid;gap:1px;min-width:130px}.sheet-department-overview-head b{font-size:.75rem}.sheet-department-overview-head small{color:var(--muted);font-size:.64rem}.sheet-department-chips{display:flex;align-items:stretch;flex:1;flex-wrap:wrap;gap:6px}.sheet-department-chip{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:center;gap:1px 7px;min-width:0;max-width:100%;padding:5px 8px;border:1px solid var(--line);border-radius:9px;background:var(--canvas);color:var(--ink-soft);font-size:.68rem}.sheet-department-chip b{grid-row:1/3;color:var(--green);font-size:.82rem;font-variant-numeric:tabular-nums}.sheet-department-chip>span{overflow-wrap:anywhere;font-weight:700}.sheet-department-chip small{grid-column:2;color:var(--muted);font-size:.58rem;white-space:normal}
    .sheet-functions{display:grid;gap:14px}.sheet-function{overflow:hidden;border:1px solid var(--line);border-radius:15px;background:var(--surface);box-shadow:0 5px 18px rgba(24,48,33,.045);break-inside:avoid-page}.sheet-function-head{display:grid;grid-template-columns:135px minmax(0,1fr) auto auto;align-items:center;gap:13px;padding:15px 17px;border-bottom:1px solid var(--line);background:linear-gradient(100deg,#f8fbf8,#fff 65%)}
    .function-time{display:grid;gap:2px}.function-time b{color:var(--green);font-size:1rem;font-variant-numeric:tabular-nums;white-space:nowrap}.function-time small{color:var(--muted);font-size:.68rem}.function-identity{min-width:0}.function-ref{display:inline-flex;align-items:center;padding:2px 6px;border:1px solid var(--line);border-radius:5px;background:#fff;color:var(--slate);font-size:.65rem;font-weight:800;font-variant-numeric:tabular-nums;text-decoration:none}.function-ref:hover,.function-ref:focus-visible{border-color:var(--green-line);background:var(--green-soft);color:var(--green);text-decoration:none}.function-status-line{display:flex;align-items:center;flex-wrap:wrap;gap:5px;margin-top:4px}.function-type{display:inline-flex;align-items:center;padding:4px 8px;border:1px solid #dbe4ea;border-radius:999px;background:#f5f8fa;color:#485866;font-size:.64rem;font-weight:700}.function-identity h2{margin:4px 0 1px;font-size:1rem;line-height:1.25}.function-identity p{margin:0;color:var(--muted);font-size:.77rem}.status-badge{display:inline-flex;align-items:center;justify-content:center;padding:5px 9px;border:1px solid var(--line);border-radius:999px;background:#fff;color:var(--slate);font-size:.67rem;font-weight:800;text-transform:capitalize;white-space:nowrap}.status-badge.definite{border-color:var(--green-line);background:var(--green-soft);color:var(--green)}.status-badge.tentative{border-color:var(--amber-line);background:var(--amber-soft);color:var(--amber)}.status-badge.completed{border-color:#bfdbfe;background:var(--blue-soft);color:var(--blue)}.option-deadline{display:inline-flex;align-items:center;padding:4px 8px;border:1px solid var(--amber-line);border-radius:999px;background:var(--amber-soft);color:var(--amber);font-size:.64rem;font-weight:700}.option-deadline.is-expired{border-color:#fecaca;background:#fef2f2;color:#b91c1c}
    .function-pax{min-width:88px;padding-left:13px;border-left:1px solid var(--line);text-align:right}.function-pax b{display:block;color:var(--ink);font-size:1.25rem;font-variant-numeric:tabular-nums;line-height:1}.function-pax span,.function-pax small{display:block;margin-top:4px;color:var(--muted);font-size:.64rem}
    .sheet-function-body{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0;padding:2px 16px}.sheet-fact{min-width:0;padding:12px 8px;border-bottom:1px solid #edf1ee}.sheet-fact:nth-last-child(-n+2){border-bottom:0}.sheet-fact.span-two{grid-column:span 2}.sheet-fact.span-all{grid-column:1/-1}.fact-label{display:block;margin-bottom:5px}.fact-value{color:var(--ink-soft);font-size:.78rem;overflow-wrap:anywhere}.fact-value strong{color:var(--ink)}.fact-sub{display:block;margin-top:3px;color:var(--muted);font-size:.71rem}.capacity-warning{display:block;margin-top:6px;padding:5px 7px;border:1px solid var(--amber-line);border-radius:7px;background:var(--amber-soft);color:var(--amber);font-size:.67rem;font-weight:750}.fact-list{display:grid;gap:6px;margin:0;padding:0;list-style:none}.fact-list li{display:grid;grid-template-columns:auto minmax(0,1fr);gap:7px;color:var(--ink-soft);font-size:.76rem}.package-item-list{display:grid;gap:2px;margin:5px 0 0;padding-left:17px;color:var(--ink-soft);font-size:.74rem}.package-item-list li{padding-left:1px}.qty{color:var(--ink);font-weight:750;font-variant-numeric:tabular-nums;white-space:nowrap}.dept-group{margin-top:7px}.dept-group:first-child{margin-top:0}.dept-name{display:block;margin-bottom:3px;color:var(--muted);font-size:.65rem;font-weight:800;letter-spacing:.03em;text-transform:uppercase}.sheet-notes{min-height:40px;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:#fafcfb;color:var(--ink-soft);font-size:.76rem;white-space:pre-wrap;overflow-wrap:anywhere}.sheet-no-notes{color:var(--muted);font-style:italic}
    .sheet-empty{display:grid;justify-items:center;gap:8px;margin:20px 0;padding:42px 20px;border:1px dashed #bfd3c4;border-radius:16px;background:#fbfdfb;text-align:center}.sheet-empty-mark{display:grid;width:42px;height:42px;place-items:center;border-radius:50%;background:var(--green-soft);color:var(--green);font-size:1.2rem}.sheet-empty h2{margin:0;font-size:1rem}.sheet-empty p{margin:0;color:var(--muted);font-size:.82rem}.sheet-empty .tool-link{display:inline-flex;align-items:center;justify-content:center;min-height:38px;margin-top:5px;padding:7px 12px;border:1px solid var(--green-line);border-radius:9px;background:#fff;color:var(--green);font-weight:700;text-decoration:none}.sheet-empty .tool-link:hover{background:var(--green-soft)}
    .sheet-footer{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:15px;padding-top:10px;border-top:1px solid var(--line);color:var(--muted);font-size:.67rem}.sheet-footer strong{color:var(--ink-soft)}.sheet-print-running{display:none}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
    @media(max-width:900px){.sheet-shell{padding:18px 18px 32px}.sheet-toolbar{margin:-18px -18px 16px;padding:10px 18px}.sheet-summary.has-actual{grid-template-columns:repeat(2,minmax(0,1fr))}.sheet-function-body{grid-template-columns:repeat(2,minmax(0,1fr))}.sheet-fact:nth-last-child(-n+3){border-bottom:1px solid #edf1ee}.sheet-fact:nth-last-child(-n+2){border-bottom:0}}
    @media(max-width:640px){body{font-size:13px}.sheet-shell{padding:10px 10px 24px}.sheet-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.sheet-department-overview{align-items:flex-start;padding:9px}.sheet-department-overview-head{min-width:100%}.sheet-department-chips{flex:auto}.sheet-toolbar{position:static;margin:-10px -10px 12px;padding:9px 10px;gap:6px}.sheet-toolbar .tool-link,.sheet-toolbar button{min-height:36px;padding:6px 9px;font-size:.74rem}.sheet-toolbar .sheet-toolbar-spacer{display:none}.sheet-toolbar .date-field{order:5;flex:1 1 100%;justify-content:flex-start}.sheet-toolbar .date-field input{width:100%}.sheet-cover{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;margin-bottom:12px;padding:16px 14px;border-radius:14px}.sheet-date-card{min-width:0;padding:8px 9px}.sheet-date-card time{font-size:.93rem}.sheet-date-card small{font-size:.65rem}.sheet-cover .subtitle{max-width:32ch;font-size:.76rem}.sheet-summary{gap:6px;margin-bottom:10px}.sheet-metric{display:grid;justify-items:start;align-content:center;gap:5px;min-height:65px;padding:9px}.metric-value{font-size:1.12rem}.metric-label{font-size:8px}.metric-copy small{font-size:.61rem}.sheet-statuses{margin-bottom:11px}.status-count{padding:4px 7px;font-size:.66rem}.sheet-functions{gap:10px}.sheet-function{border-radius:12px}.sheet-function-head{grid-template-columns:minmax(0,1fr) auto;gap:7px 10px;padding:12px}.function-time b{font-size:.92rem}.function-identity{grid-column:1/-1;grid-row:2}.function-identity h2{font-size:.94rem}.function-identity p{font-size:.73rem}.function-identity .status-badge{margin-right:5px}.function-pax{grid-column:2;grid-row:1;min-width:73px;padding-left:9px}.function-pax b{font-size:1.1rem}.function-pax span,.function-pax small{font-size:.59rem}.sheet-function-body{grid-template-columns:1fr;padding:1px 11px}.sheet-fact,.sheet-fact:nth-last-child(-n+3){padding:10px 3px;border-bottom:1px solid #edf1ee}.sheet-fact:last-child{border-bottom:0}.sheet-fact.span-two,.sheet-fact.span-all{grid-column:auto}.fact-label{margin-bottom:3px}.sheet-footer{align-items:flex-start;flex-direction:column}}
    @media print{@page{size:A4 portrait;margin:15mm 10mm 12mm}.sheet-print-running{display:flex;position:fixed;top:-9mm;right:0;left:0;justify-content:space-between;gap:8mm;padding-bottom:2mm;border-bottom:1px solid #b9c1bb;color:#5d665f;font-size:7.5pt;font-weight:650}body{background:#fff;color:#111;font-size:9.5pt;-webkit-print-color-adjust:exact;print-color-adjust:exact}.sheet-shell{max-width:none;padding:0}.noprint,.sheet-toolbar{display:none!important}.sheet-cover{margin:0 0 8mm;padding:5mm;border-radius:0;box-shadow:none}.sheet-cover h1{font-size:17pt}.sheet-date-card{min-width:48mm;padding:3mm}.sheet-summary{gap:3mm;margin-bottom:4mm}.sheet-metric{min-height:16mm;padding:3mm;box-shadow:none}.sheet-functions{gap:4mm}.sheet-function{border:1px solid #aab3ad;border-radius:2mm;box-shadow:none}.sheet-function-head{grid-template-columns:27mm minmax(0,1fr) auto auto;gap:3mm;padding:3mm;background:#f5f7f5}.function-identity h2{font-size:10pt}.function-identity p,.fact-value,.fact-list li{font-size:8pt}.sheet-function-body{grid-template-columns:repeat(3,minmax(0,1fr));padding:0 2mm}.sheet-fact,.sheet-fact:nth-last-child(-n+2){padding:2.5mm 1.5mm;border-bottom:1px solid #e0e4e0}.sheet-fact:nth-last-child(-n+2):not(.span-all){border-bottom:0}.sheet-fact.span-two{grid-column:span 2}.sheet-fact.span-all{grid-column:1/-1}.sheet-notes{min-height:0;padding:2mm;border-radius:0;background:#fff}.sheet-footer{margin-top:4mm;padding-top:2mm}.sheet-empty{margin:10mm 0;padding:12mm;border-radius:0}.status-badge,.status-count{background:#fff}.sheet-summary,.sheet-statuses,.sheet-department-overview{break-inside:avoid}.sheet-department-overview{margin-bottom:4mm;padding:2.5mm;border-radius:1mm}.sheet-department-chip{padding:1mm 2mm;font-size:7pt}}
  </style>
</head>
<body>
@php
  $functionCount = $fns->count();
  $coverCount = $fns->sum(fn ($f) => $f->guaranteed_pax ?? $f->expected_pax);
  $actualFunctionCount = $fns->filter(fn ($f) => $f->actual_pax !== null)->count();
  $actualCoverCount = $fns->sum(fn ($f) => $f->actual_pax ?? 0);
  $venueCount = $fns->pluck('banquet_hall_id')->unique()->count();
  $statusCounts = $fns->countBy(fn ($f) => $f->event->status);
  $departmentCounts = [];
  foreach ($fns as $function) {
      $functionStatus = $function->event->status;
      foreach ($function->items as $item) {
          $department = $item->service?->department?->name ?? 'Other services';
          $departmentCounts[$department][$functionStatus] = ($departmentCounts[$department][$functionStatus] ?? 0) + 1;
      }
  }
@endphp
<div class="sheet-shell">
  <nav class="sheet-toolbar noprint" aria-label="Function sheet controls">
    <a class="tool-link" href="?date={{ $date->copy()->subDay()->toDateString() }}" aria-label="Previous day"><span aria-hidden="true">‹</span> Previous</a>
    <a class="tool-link" href="?date={{ bdate()->toDateString() }}" @if($date->toDateString() === bdate()->toDateString()) aria-current="date" @endif>Today</a>
    <a class="tool-link" href="?date={{ $date->copy()->addDay()->toDateString() }}" aria-label="Next day">Next <span aria-hidden="true">›</span></a>
    <form class="date-field" method="get" action="{{ route('banquet.sheet') }}">
      <label class="sr-only" for="sheetDate">Choose function sheet date</label>
      <input id="sheetDate" type="date" name="date" value="{{ $date->toDateString() }}">
      <button type="submit">Go</button>
    </form>
    <span class="sheet-toolbar-spacer"></span>
    <button class="tool-primary" type="button" onclick="window.print()">Print sheet</button>
    <a class="tool-link" href="{{ route('banquet.diary', ['start' => $date->toDateString()]) }}">Back to diary</a>
  </nav>
  <div class="sheet-print-running" aria-hidden="true"><span>{{ $hotel->name }} · Banquet operations</span><span>Function sheet · {{ $date->format('D d M Y') }}</span></div>

  <header class="sheet-cover">
    <div><span class="sheet-kicker">Banquet operations · daily brief</span><h1>{{ $hotel->name }}</h1><p class="subtitle">Function sheet for kitchen, service, housekeeping, engineering, front office and security</p></div>
    <div class="sheet-date-card"><span>Functions on</span><time datetime="{{ $date->toDateString() }}">{{ $date->format('D d M Y') }}</time><small>Prepared for all operating departments</small></div>
  </header>

  <section class="sheet-summary {{ $actualFunctionCount > 0 ? 'has-actual' : '' }}" aria-label="Daily function summary">
    <div class="sheet-metric"><div class="metric-value">{{ $functionCount }}</div><div class="metric-copy"><span class="metric-label">Functions</span><small>{{ $functionCount === 1 ? 'Scheduled function' : 'Scheduled functions' }}</small></div></div>
    <div class="sheet-metric"><div class="metric-value">{{ number_format($coverCount) }}</div><div class="metric-copy"><span class="metric-label">Covers</span><small>Guaranteed or expected @if(($statusCounts->get('tentative') ?? 0) > 0) · includes tentative holds @endif</small></div></div>
    <div class="sheet-metric"><div class="metric-value">{{ $venueCount }}</div><div class="metric-copy"><span class="metric-label">Venues</span><small>With scheduled functions</small></div></div>
    @if($actualFunctionCount > 0)<div class="sheet-metric"><div class="metric-value">{{ number_format($actualCoverCount) }}</div><div class="metric-copy"><span class="metric-label">Actual covers</span><small>Recorded for {{ $actualFunctionCount }} of {{ $functionCount }} functions</small></div></div>@endif
  </section>

  @if($functionCount > 0)
    <div class="sheet-statuses" role="group" aria-label="Functions by status">
      @foreach(['definite' => 'Definite', 'tentative' => 'Tentative', 'completed' => 'Completed'] as $status => $label)
        @if(($statusCounts->get($status) ?? 0) > 0)<span class="status-count"><i class="status-dot status-{{ $status }}" aria-hidden="true"></i>{{ $label }} <b>{{ $statusCounts->get($status) }}</b></span>@endif
      @endforeach
      @if(($statusCounts->get('tentative') ?? 0) > 0)<span class="status-count status-tentative-note">Tentative bookings are not confirmed</span>@endif
    </div>
    @if(! empty($departmentCounts))
      <section class="sheet-department-overview" aria-label="Service and equipment line totals by department and status">
        <div class="sheet-department-overview-head"><b>Department hand-off</b><small>Service &amp; equipment lines by booking status</small></div>
        <div class="sheet-department-chips">
          @foreach($departmentCounts as $department => $linesByStatus)
            @php $lineTotal = array_sum($linesByStatus); @endphp
            <span class="sheet-department-chip"><b>{{ $lineTotal }}</b><span>{{ $department }}</span><small>{{ $linesByStatus['definite'] ?? 0 }} definite · {{ $linesByStatus['tentative'] ?? 0 }} tentative · {{ $linesByStatus['completed'] ?? 0 }} completed</small></span>
          @endforeach
        </div>
      </section>
    @endif
    <main class="sheet-functions" aria-label="Functions for {{ $date->format('l d F Y') }}">
      @foreach($fns as $f)
        @php
          $pax = $f->guaranteed_pax ?? $f->expected_pax;
          $capacityCheckPax = $f->billablePax();
          $status = $f->event->status;
          $eventTitle = $f->event->title ?: ($f->event->contact_name ?: 'Untitled event');
          $eventType = \App\Models\BanquetEvent::TYPES[$f->event->event_type] ?? 'Event';
          $functionName = $f->name ?: 'Function';
          $setupCapacity = $f->hall->capacityFor($f->setup_style);
          $packageItems = $f->package?->itemList() ?? [];
          $optionExpired = $status === 'tentative' && $f->event->optionExpired();
          $itemsByDepartment = $f->items->groupBy(fn ($item) => $item->service?->department?->name ?? 'Other services');
        @endphp
        <article class="sheet-function" aria-labelledby="sheetFunction{{ $f->id }}">
          <header class="sheet-function-head">
            <div class="function-time"><span class="metric-label">Function time</span><b><time datetime="{{ $f->date->toDateString() }}T{{ substr($f->start_time, 0, 5) }}">{{ $f->timeRange() }}</time></b><small>{{ $f->date->format('D d M') }}</small></div>
            <div class="function-identity">
              <a class="function-ref" href="{{ route('banquet.show', $f->event) }}" target="_blank" rel="noopener" aria-label="Open banquet record {{ $f->event->event_no }} in a new tab">{{ $f->event->event_no }}</a>
              <a class="function-ref" href="{{ route('banquet.menu', ['event' => $f->event, 'function' => $f->id]) }}" target="_blank" rel="noopener" aria-label="Open the kitchen menu for {{ $f->name }} in a new tab">Menu</a>
              <div class="function-status-line"><span class="status-badge {{ $status }}">{{ str_replace('_', ' ', $status) }}</span><span class="function-type">{{ $eventType }}</span>@if($status === 'tentative')<span class="option-deadline {{ $optionExpired ? 'is-expired' : '' }}">{{ $optionExpired ? 'Option expired' : 'Option expires' }} · {{ $f->event->option_date?->format('d M Y') ?? 'date not set' }}</span>@endif</div>
              <h2 id="sheetFunction{{ $f->id }}">{{ $functionName }}</h2>
              <p>{{ $eventTitle }}</p>
            </div>
            <div class="function-pax"><b>{{ number_format($pax) }}</b><span>{{ $f->guaranteed_pax === null ? 'Expected covers' : 'Guaranteed covers' }}</span>@if($f->actual_pax !== null)<small>Actual: {{ number_format($f->actual_pax) }}</small>@endif</div>
          </header>
          <div class="sheet-function-body">
            <section class="sheet-fact"><span class="fact-label">Venue &amp; set-up</span><div class="fact-value"><strong>{{ $f->hall->name }}</strong><span class="fact-sub">{{ \App\Models\BanquetFunction::SETUPS[$f->setup_style] ?? $f->setup_style }}</span>@if($setupCapacity > 0)<span class="fact-sub">Capacity for this set-up · {{ number_format($setupCapacity) }}</span>@if($capacityCheckPax > $setupCapacity)<span class="capacity-warning" role="note">Guest count including actuals ({{ number_format($capacityCheckPax) }}) exceeds set-up capacity by {{ number_format($capacityCheckPax - $setupCapacity) }}</span>@endif @endif</div></section>
            <section class="sheet-fact"><span class="fact-label">Client contact</span><div class="fact-value"><strong>{{ $f->event->contact_name ?: 'Contact not recorded' }}</strong>@if($f->event->company?->name)<span class="fact-sub">{{ $f->event->company->name }}</span>@endif @if($f->event->contact_phone)<span class="fact-sub">{{ $f->event->contact_phone }}</span>@endif</div></section>
            <section class="sheet-fact"><span class="fact-label">Menu / package</span><div class="fact-value"><strong>{{ $f->package?->name ?? ($f->rate_per_pax > 0 ? 'Custom menu' : 'No package selected') }}</strong>@if($packageItems)<ul class="package-item-list">@foreach($packageItems as $packageItem)<li>{{ $packageItem }}</li>@endforeach</ul>@endif @if($f->barPackage)<span class="fact-sub">Bar · {{ $f->barPackage->name }}</span>@endif @if(count($f->dietaryLines()))<span class="fact-sub">{{ $f->dietaryCovers() }} dietary covers @foreach($f->dietaryLines() as $dietLine) · {{ $dietLine['covers'] }} {{ $dietLine['label'] }} @endforeach</span>@endif</div></section>
            <section class="sheet-fact span-two"><span class="fact-label">Services &amp; equipment</span>
              @if($itemsByDepartment->isEmpty())<div class="fact-value sheet-no-notes">No additional services or equipment listed.</div>@else
                @foreach($itemsByDepartment as $department => $items)
                  <div class="dept-group"><span class="dept-name">{{ $department }}</span><ul class="fact-list">
                    @foreach($items as $item)<li><span class="qty">{{ rtrim(rtrim(number_format($item->qty, 2), '0'), '.') }} ×</span><span>{{ $item->description }}</span></li>@endforeach
                  </ul></div>
                @endforeach
              @endif
            </section>
            <section class="sheet-fact"><span class="fact-label">Operational notes</span><div class="sheet-notes {{ $f->notes ? '' : 'sheet-no-notes' }}">{{ $f->notes ?: 'No additional notes.' }}</div></section>
          </div>
        </article>
      @endforeach
    </main>
  @else
    <section class="sheet-empty" role="status"><span class="sheet-empty-mark" aria-hidden="true">✓</span><h2>No functions scheduled</h2><p>There are no tentative, definite or completed banquet functions for {{ $date->format('l d F Y') }}.</p><a class="tool-link" href="{{ route('banquet.diary', ['start' => $date->toDateString()]) }}">Return to diary</a></section>
  @endif

  <footer class="sheet-footer"><span><strong>{{ $hotel->name }}</strong> · Banquet function sheet</span><span>Venue turn-around: {{ setting('banquet_turnaround_minutes', '60') }} minutes</span><span>Generated {{ now()->format('d M Y, H:i') }}</span></footer>
</div>
</body>
</html>
