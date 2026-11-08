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
