@php
  $bdLinks = [
    ['label' => 'Overview', 'route' => 'bd.index', 'icon' => 'chart', 'active' => request()->routeIs('bd.index')],
    ['label' => 'Competitive set', 'route' => 'bd.compset', 'icon' => 'trend', 'active' => request()->routeIs('bd.compset', 'bd.competitor')],
    ['label' => 'Market mix', 'route' => 'bd.market', 'icon' => 'globe', 'active' => request()->routeIs('bd.market')],
    ['label' => 'Partnerships', 'route' => 'bd.partners', 'icon' => 'users', 'active' => request()->routeIs('bd.partners', 'bd.partner')],
    ['label' => 'Initiatives', 'route' => 'bd.initiatives', 'icon' => 'layers', 'active' => request()->routeIs('bd.initiatives', 'bd.initiative')],
  ];
@endphp
<section class="sm-workspace" aria-label="Business Development workspace">
  <div class="sm-workspace-head">
    <div class="sm-workspace-brand">
      <span class="sm-workspace-mark" aria-hidden="true"><x-icon name="building"/></span>
      <span class="sm-workspace-copy"><span class="sm-workspace-eyebrow">Commercial workspace</span><strong>Business Development</strong></span>
    </div>
    <div class="sm-workspace-meta"><span>{{ bdate()->format('D, d M Y') }}</span><span>{{ hotel()->currency ?? 'GHS' }} · {{ hotel()->name }}</span></div>
  </div>
  <nav class="sm-workspace-links" aria-label="Business Development pages">
    @foreach($bdLinks as $link)
      @if(can_do('bd.view'))
        <a class="sm-workspace-link" href="{{ route($link['route']) }}" @if($link['active']) aria-current="page" @endif>
          <span class="sm-workspace-link-icon" aria-hidden="true"><x-icon :name="$link['icon']"/></span><span>{{ $link['label'] }}</span>
        </a>
      @endif
    @endforeach
    @if(can_do('bd.manage'))
      <a class="sm-workspace-link" href="{{ route('bd.settings') }}" @if(request()->routeIs('bd.settings')) aria-current="page" @endif><span class="sm-workspace-link-icon" aria-hidden="true"><x-icon name="tool"/></span><span>Settings</span></a>
    @endif
    @if(can_do('sales.view'))
      <a class="sm-workspace-link" href="{{ route('sales.index') }}"><span class="sm-workspace-link-icon" aria-hidden="true"><x-icon name="trend"/></span><span>Sales &amp; Marketing</span></a>
    @endif
  </nav>
</section>
