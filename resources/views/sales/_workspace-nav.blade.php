@php
  $smLinks = [
    ['label' => 'Overview', 'route' => 'sales.index', 'icon' => 'chart', 'active' => request()->routeIs('sales.index')],
    ['label' => 'Accounts', 'route' => 'sales.accounts', 'icon' => 'building', 'active' => request()->routeIs('sales.accounts', 'sales.account')],
    ['label' => 'Opportunities', 'route' => 'sales.opportunities', 'icon' => 'trend', 'active' => request()->routeIs('sales.opportunities', 'sales.opportunity', 'sales.proposal')],
    ['label' => 'Sales diary', 'route' => 'sales.activities', 'icon' => 'calendar', 'active' => request()->routeIs('sales.activities')],
    ['label' => 'Targets', 'route' => 'sales.targets', 'icon' => 'check', 'active' => request()->routeIs('sales.targets')],
    ['label' => 'Marketing calendar', 'route' => 'sales.marketing', 'icon' => 'calendar', 'active' => request()->routeIs('sales.marketing', 'sales.mevent')],
    ['label' => 'Budget', 'route' => 'sales.budget', 'icon' => 'wallet', 'active' => request()->routeIs('sales.budget')],
    ['label' => 'Collateral', 'route' => 'sales.collateral', 'icon' => 'book', 'active' => request()->routeIs('sales.collateral')],
  ];
@endphp
<section class="sm-workspace" aria-label="Sales and Marketing workspace">
  <div class="sm-workspace-head">
    <div class="sm-workspace-brand">
      <span class="sm-workspace-mark" aria-hidden="true"><x-icon name="trend"/></span>
      <span class="sm-workspace-copy"><span class="sm-workspace-eyebrow">Commercial workspace</span><strong>Sales &amp; Marketing</strong></span>
    </div>
    <div class="sm-workspace-meta"><span>{{ bdate()->format('D, d M Y') }}</span><span>{{ hotel()->currency ?? 'GHS' }} · {{ hotel()->name }}</span></div>
  </div>
  <nav class="sm-workspace-links" aria-label="Sales and Marketing pages">
    @foreach($smLinks as $link)
      @if(can_do('sales.view'))
        <a class="sm-workspace-link" href="{{ route($link['route']) }}" @if($link['active']) aria-current="page" @endif>
          <span class="sm-workspace-link-icon" aria-hidden="true"><x-icon :name="$link['icon']"/></span><span>{{ $link['label'] }}</span>
        </a>
      @endif
    @endforeach
    @if(can_do('sales.manage'))
      <a class="sm-workspace-link" href="{{ route('sales.settings') }}" @if(request()->routeIs('sales.settings')) aria-current="page" @endif><span class="sm-workspace-link-icon" aria-hidden="true"><x-icon name="tool"/></span><span>Settings</span></a>
    @endif
    @if(can_do('digital.view'))
      <a class="sm-workspace-link" href="{{ route('digital.index') }}"><span class="sm-workspace-link-icon" aria-hidden="true"><x-icon name="globe"/></span><span>Digital marketing</span></a>
    @endif
  </nav>
</section>
