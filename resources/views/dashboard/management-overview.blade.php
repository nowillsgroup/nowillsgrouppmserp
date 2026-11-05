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

@if($managementModules)
<section class="dash-management-modules" aria-labelledby="dashManagementModulesHeading">
  <div class="dash-management-heading">
    <div><span class="dash-section-kicker">PROPERTY-WIDE PERFORMANCE</span><h2 id="dashManagementModulesHeading">Department pulse</h2><p>Live KPIs from the modules you’re authorized to view.</p></div>
    <span class="dash-management-count">{{ count($managementModules) }} business areas</span>
  </div>
  <div class="dash-management-grid">
    @foreach($managementModules as $module)
    <article class="dash-management-card tone-{{ $module['tone'] }}">
      <div class="dash-management-card-head"><span class="dash-management-icon"><x-icon :name="$module['icon']" size="sm"/></span><h3>{{ $module['title'] }}</h3></div>
      <div class="dash-management-metric"><strong>{{ $module['value'] }}</strong><span>{{ $module['label'] }}</span></div>
      <p>{{ $module['detail'] }}</p>
      @if($module['href'])<a class="dash-management-link" href="{{ $module['href'] }}" aria-label="Open {{ $module['title'] }}">Open module <x-icon name="chev" size="sm"/></a>@endif
    </article>
    @endforeach
  </div>
</section>
@endif
