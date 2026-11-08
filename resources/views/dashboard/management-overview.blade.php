<section class="dash-exec-hero" aria-label="Management dashboard heading">
  <div class="dash-exec-hero-main">
    <span class="dash-exec-eyebrow"><span class="dash-exec-live-dot" aria-hidden="true"></span> MANAGEMENT INTELLIGENCE <span aria-hidden="true">/</span> {{ $currentHotel->name }}</span>
    <h1>Management overview</h1>
    <p>A decision-ready view of property performance, guest flow and department activity.</p>
    <nav class="dash-exec-actions" aria-label="Management dashboard actions">
      @if(can_do('reservations.create'))<a class="dash-exec-action-primary" href="{{ route('reservations.create') }}"><x-icon name="plus" size="sm"/> New reservation</a>@endif
      @if(can_do('reservations.view'))<a class="dash-exec-action-secondary" href="{{ route('reservations.index', ['view' => 'dashboard']) }}"><x-icon name="calendar" size="sm"/> Reservation desk</a>@endif
      @if(can_do('frontdesk.view'))<a class="dash-exec-action-secondary" href="{{ route('frontdesk.arrivals') }}"><x-icon name="login" size="sm"/> Arrivals</a>@endif
      @if(can_do('frontdesk.checkin'))<a class="dash-exec-action-secondary" href="{{ route('frontdesk.walkin') }}"><x-icon name="users" size="sm"/> Walk-in</a>@endif
      @if(can_do('reports.view'))<a class="dash-exec-action-secondary" href="{{ route('reports.index') }}"><x-icon name="chart" size="sm"/> Reports &amp; analysis</a>@endif
      @if(can_do('nightaudit.run'))<a class="dash-exec-action-secondary" href="{{ route('nightaudit.index') }}"><x-icon name="moon" size="sm"/> Night audit</a>@endif
    </nav>
  </div>
  <div class="dash-exec-date">
    <span>BUSINESS DATE</span>
    <strong>{{ bdate()->format('D, d M Y') }}</strong>
    <small>{{ $currentHotel->currency ?: 'Property currency' }} · figures respect your access</small>
  </div>
</section>

@if($executiveKpis)
<section class="dash-exec-kpi-grid" aria-label="Executive key performance indicators">
  @foreach($executiveKpis as $kpi)
  <article class="dash-exec-kpi tone-{{ $kpi['tone'] }}">
    <div class="dash-exec-kpi-top"><span>{{ $kpi['label'] }}</span><span class="dash-exec-kpi-icon"><x-icon :name="$kpi['icon']" size="sm"/></span></div>
    <strong>{{ $kpi['value'] }}</strong>
    <small>{{ $kpi['detail'] }}</small>
  </article>
  @endforeach
</section>
@endif
