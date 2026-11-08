<section class="dash-management-trends" aria-labelledby="dashManagementTrendHeading">
  <div class="dash-management-heading">
    <div><span class="dash-section-kicker">PERFORMANCE TREND</span><h2 id="dashManagementTrendHeading">{{ $canSeeFinance ? 'Revenue & occupancy trend' : 'Occupancy trend' }}</h2><p>Last 14 completed audit days · hover or keyboard-focus a bar for its exact value.</p></div>
    @if($canSeeFinance && can_do('nightaudit.run'))<a class="dash-management-count" href="{{ route('nightaudit.index') }}">Night audit</a>@elseif($canSeeFinance && can_do('reports.view'))<a class="dash-management-count" href="{{ route('reports.show', 'revenue') }}">Revenue report</a>@endif
  </div>
  <div class="card dash-chart-card">
    <div class="card-body">
      @if($audits->isEmpty())
        <x-empty text="No completed audit days are available for this trend yet." icon="chart"/>
      @else
        <div class="dash-chart-grid">
          @if($canSeeFinance)
          @php $maxRevenue = max(1, $audits->max(fn($a) => $a->room_revenue + $a->other_revenue)); @endphp
          <section class="dash-chart-panel" aria-labelledby="dashRevenueTrendHeading">
            <div class="dash-chart-panel-heading"><h3 id="dashRevenueTrendHeading">Daily revenue</h3><span>Room + other revenue</span></div>
            <div class="bar-chart dash-history-chart" role="group" aria-label="Daily revenue for the last 14 completed audit days">
              @foreach($audits as $a)
                @php $v = $a->room_revenue + $a->other_revenue; @endphp
                <div class="col"><div class="b dash-revenue-bar" role="img" tabindex="0" aria-label="{{ $a->business_date->format('d M Y') }}: {{ money($v) }} revenue" title="{{ money($v) }} revenue" style="height: {{ max(2, $v / $maxRevenue * 100) }}%" data-v="{{ money($v) }}"></div><small>{{ $a->business_date->format('d/m') }}</small></div>
              @endforeach
            </div>
          </section>
          @endif
          <section class="dash-chart-panel" aria-labelledby="dashOccupancyTrendHeading">
            <div class="dash-chart-panel-heading"><h3 id="dashOccupancyTrendHeading">Daily occupancy</h3><span>Sellable rooms occupied</span></div>
            <div class="bar-chart dash-history-chart" role="group" aria-label="Daily occupancy for the last 14 completed audit days">
              @foreach($audits as $a)
                <div class="col"><div class="b dash-occupancy-bar" role="img" tabindex="0" aria-label="{{ $a->business_date->format('d M Y') }}: {{ $a->occupancy_pct }}% occupancy" title="{{ $a->occupancy_pct }}% occupancy" style="height: {{ max(2, min(100, (float) $a->occupancy_pct)) }}%" data-v="{{ $a->occupancy_pct }}%"></div><small>{{ $a->business_date->format('d/m') }}</small></div>
              @endforeach
            </div>
          </section>
        </div>
      @endif
    </div>
  </div>
</section>

@if($canSeeFinance || can_do('reservations.view') || can_do('pos.view'))
<section class="dash-mix-section" aria-labelledby="dashMixHeading">
  <div class="dash-management-heading">
    <div><span class="dash-section-kicker">{{ $canSeeFinance && can_do('reservations.view') ? 'REVENUE & DEMAND MIX' : ($canSeeFinance && can_do('pos.view') ? 'REVENUE & POS MIX' : (can_do('reservations.view') && can_do('pos.view') ? 'DEMAND & POS MIX' : ($canSeeFinance ? 'REVENUE MIX' : (can_do('reservations.view') ? 'DEMAND MIX' : 'F&B MIX')))) }}</span><h2 id="dashMixHeading">Business mix</h2><p>Mix charts are built from current tenant records and follow finance, reservation and POS permissions.</p></div>
  </div>
  <div class="dash-mix-grid">
    @if($canSeeFinance)
    @php
      $revenueSlices = collect([
        ['label' => 'Rooms & extra bed', 'value' => max(0, (float) $roomRevenuePositive), 'amount' => (float) $roomRevenue, 'color' => '#16834a'],
        ['label' => 'Other & POS', 'value' => max(0, (float) $otherRevenuePositive), 'amount' => (float) $otherRevenue, 'color' => '#3b82f6'],
      ]);
      $revenueMixTotal = $revenueSlices->sum('value');
      $revenueMixHasAdjustments = $roomRevenuePositive > $roomRevenue + 0.005 || $otherRevenuePositive > $otherRevenue + 0.005;
      $revenueStops = [];
      $revenueAngle = 0;
      if ($revenueMixTotal > 0) {
        foreach ($revenueSlices as $slice) {
          if ($slice['value'] <= 0) continue;
          $nextAngle = min(360, $revenueAngle + ($slice['value'] / $revenueMixTotal * 360));
          $revenueStops[] = $slice['color'].' '.round($revenueAngle, 2).'deg '.round($nextAngle, 2).'deg';
          $revenueAngle = $nextAngle;
        }
      }
      $revenueGradient = $revenueStops ? 'conic-gradient('.implode(', ', $revenueStops).')' : '';
    @endphp
    <article class="dash-mix-card" aria-labelledby="dashRevenueMixTitle">
      <div class="dash-mix-card-head"><div><h3 id="dashRevenueMixTitle">Revenue breakdown · today</h3><p>Room and extra-bed charges vs other folio charges and settled POS, net of tax.</p></div><span class="dash-mix-period">Business date</span></div>
      @if($revenueMixTotal > 0)
      <div class="dash-mix-content">
        <div class="dash-mix-donut" role="img" aria-label="Today's revenue mix. Net revenue {{ money($roomRevenue + $otherRevenue) }}. Room and extra-bed revenue {{ money($roomRevenue) }}; other revenue and settled POS {{ money($otherRevenue) }}." style="background:{{ $revenueGradient }}"><span><strong>{{ money($roomRevenue + $otherRevenue) }}</strong><small>net revenue</small></span></div>
        <ul class="dash-mix-legend">
          @foreach($revenueSlices as $slice)
          <li><span class="dash-mix-swatch" style="--slice-color:{{ $slice['color'] }}"></span><span class="dash-mix-label">{{ $slice['label'] }}</span><strong>{{ money($slice['amount']) }}</strong><small>{{ $revenueMixTotal ? number_format($slice['value'] / $revenueMixTotal * 100, 1).'%' : '0%' }}</small></li>
          @endforeach
        </ul>
      </div>
      @if($revenueMixHasAdjustments)<p class="dash-mix-note">Pie proportions use positive revenue postings only; the net amounts and total above retain negative adjustments.</p>@endif
      @else
      <div class="dash-mix-empty"><x-icon name="chart" size="sm"/> No positive revenue has been posted today.</div>
      @endif
    </article>
    @endif

    @if(can_do('reservations.view'))
    @php
      $sourcePalette = ['#16834a', '#3b82f6', '#f59e0b', '#8b5cf6', '#e11d48', '#64748b'];
      $sourceNoSource = $sourceMix->firstWhere('name', 'No source');
      $sourceRows = $sourceMix->reject(fn ($row) => $row->name === 'No source')->take(4)->values()
        ->map(fn ($row, $index) => ['label' => $row->name, 'value' => (int) $row->c, 'color' => $sourcePalette[$index]])->values();
      if ($sourceNoSource) $sourceRows->push(['label' => 'No source', 'value' => (int) $sourceNoSource->c, 'color' => $sourcePalette[4]]);
      $sourceMixTotal = (int) $sourceMix->sum('c');
      $sourceTopTotal = (int) $sourceRows->sum('value');
      if ($sourceMixTotal > $sourceTopTotal) $sourceRows->push(['label' => 'Other sources', 'value' => $sourceMixTotal - $sourceTopTotal, 'color' => $sourcePalette[5]]);
      $sourceStops = [];
      $sourceAngle = 0;
      if ($sourceMixTotal > 0) {
        foreach ($sourceRows as $row) {
          $nextAngle = min(360, $sourceAngle + ($row['value'] / $sourceMixTotal * 360));
          $sourceStops[] = $row['color'].' '.round($sourceAngle, 2).'deg '.round($nextAngle, 2).'deg';
          $sourceAngle = $nextAngle;
        }
      }
      $sourceGradient = $sourceStops ? 'conic-gradient('.implode(', ', $sourceStops).')' : '';
    @endphp
    <article class="dash-mix-card" aria-labelledby="dashSourceMixTitle">
      <div class="dash-mix-card-head"><div><h3 id="dashSourceMixTitle">Reservation sources</h3><p>Bookings created in the last 30 days · cancelled excluded · unassigned shown as “No source”.</p></div><span class="dash-mix-period">30 days</span></div>
      @if($sourceMixTotal > 0)
      <div class="dash-mix-content">
        <div class="dash-mix-donut" role="img" aria-label="Reservation source mix for the last 30 days. {{ $sourceMixTotal }} bookings, cancelled bookings excluded." style="background:{{ $sourceGradient }}"><span><strong>{{ number_format($sourceMixTotal) }}</strong><small>bookings</small></span></div>
        <ul class="dash-mix-legend">
          @foreach($sourceRows as $row)
          <li><span class="dash-mix-swatch" style="--slice-color:{{ $row['color'] }}"></span><span class="dash-mix-label">{{ $row['label'] }}</span><strong>{{ number_format($row['value']) }}</strong><small>{{ number_format($row['value'] / $sourceMixTotal * 100, 1) }}%</small></li>
          @endforeach
        </ul>
      </div>
      @else
      <div class="dash-mix-empty"><x-icon name="chart" size="sm"/> No non-cancelled bookings in the last 30 days.</div>
      @endif
    </article>
    @endif

    @if(can_do('pos.view'))
    @php
      $posStatusMeta = [
        'open' => ['label' => 'Open', 'color' => '#f59e0b'],
        'billed' => ['label' => 'Billed', 'color' => '#3b82f6'],
        'settled' => ['label' => 'Settled', 'color' => '#16834a'],
      ];
      $posRows = collect();
      foreach ($posStatusMeta as $status => $meta) {
        $count = (int) ($posOrderStatusToday[$status] ?? 0);
        if ($count > 0) $posRows->push(['label' => $meta['label'], 'value' => $count, 'color' => $meta['color']]);
      }
      $posMixTotal = (int) $posOrderStatusToday->sum();
      $posStatusLabel = $posRows->map(fn ($row) => $row['label'].' '.$row['value'])->implode('; ');
      $posStops = [];
      $posAngle = 0;
      if ($posMixTotal > 0) {
        foreach ($posRows as $row) {
          $nextAngle = min(360, $posAngle + ($row['value'] / $posMixTotal * 360));
          $posStops[] = $row['color'].' '.round($posAngle, 2).'deg '.round($nextAngle, 2).'deg';
          $posAngle = $nextAngle;
        }
      }
      $posGradient = $posStops ? 'conic-gradient('.implode(', ', $posStops).')' : '';
    @endphp
    <article class="dash-mix-card" aria-labelledby="dashPosMixTitle">
      <div class="dash-mix-card-head"><div><h3 id="dashPosMixTitle">POS checks · today</h3><p>Open, billed and settled checks · void and merged checks excluded.</p></div><span class="dash-mix-period">Business date</span></div>
      @if($posMixTotal > 0)
      <div class="dash-mix-content">
        <div class="dash-mix-donut" role="img" aria-label="POS checks today: {{ $posMixTotal }} total. {{ $posStatusLabel }}." style="background:{{ $posGradient }}"><span><strong>{{ number_format($posMixTotal) }}</strong><small>checks today</small></span></div>
        <ul class="dash-mix-legend">
          @foreach($posRows as $row)
          <li><span class="dash-mix-swatch" style="--slice-color:{{ $row['color'] }}"></span><span class="dash-mix-label">{{ $row['label'] }}</span><strong>{{ number_format($row['value']) }}</strong><small>{{ number_format($row['value'] / $posMixTotal * 100, 1) }}%</small></li>
          @endforeach
        </ul>
      </div>
      @else
      <div class="dash-mix-empty"><x-icon name="chart" size="sm"/> No open, billed or settled POS checks today.</div>
      @endif
    </article>
    @endif
  </div>
</section>
@endif
