@php
  $dmLinks = [
    ['label' => 'Dashboard', 'route' => 'digital.index', 'icon' => 'chart', 'active' => request()->routeIs('digital.index')],
    ['label' => 'Content calendar', 'route' => 'digital.calendar', 'icon' => 'calendar', 'active' => request()->routeIs('digital.calendar', 'digital.post')],
    ['label' => 'Paid campaigns', 'route' => 'digital.campaigns', 'icon' => 'trend', 'active' => request()->routeIs('digital.campaigns', 'digital.campaign')],
    ['label' => 'Tracked links', 'route' => 'digital.links', 'icon' => 'link', 'active' => request()->routeIs('digital.links', 'digital.link', 'digital.link.qr')],
    ['label' => 'Channels & listings', 'route' => 'digital.channels', 'icon' => 'globe', 'active' => request()->routeIs('digital.channels', 'digital.channel')],
    ['label' => 'Influencers', 'route' => 'digital.influencers', 'icon' => 'star', 'active' => request()->routeIs('digital.influencers', 'digital.influencer')],
  ];
@endphp
<section class="sm-workspace" aria-label="Digital Marketing workspace">
  <div class="sm-workspace-head">
    <div class="sm-workspace-brand">
      <span class="sm-workspace-mark" aria-hidden="true"><x-icon name="globe"/></span>
      <span class="sm-workspace-copy"><span class="sm-workspace-eyebrow">Commercial workspace</span><strong>Digital Marketing</strong></span>
    </div>
    <div class="sm-workspace-meta"><span>{{ bdate()->format('D, d M Y') }}</span><span>{{ hotel()->currency ?? 'GHS' }} · {{ hotel()->name }}</span></div>
  </div>
  <nav class="sm-workspace-links" aria-label="Digital Marketing pages">
    @foreach($dmLinks as $link)
      @if(can_do('digital.view'))
        <a class="sm-workspace-link" href="{{ route($link['route']) }}" @if($link['active']) aria-current="page" @endif>
          <span class="sm-workspace-link-icon" aria-hidden="true"><x-icon :name="$link['icon']"/></span><span>{{ $link['label'] }}</span>
        </a>
      @endif
    @endforeach
    @if(can_do('digital.manage'))
      <a class="sm-workspace-link" href="{{ route('digital.settings') }}" @if(request()->routeIs('digital.settings')) aria-current="page" @endif><span class="sm-workspace-link-icon" aria-hidden="true"><x-icon name="tool"/></span><span>Settings</span></a>
    @endif
    @if(can_do('sales.view'))
      <a class="sm-workspace-link" href="{{ route('sales.index') }}"><span class="sm-workspace-link-icon" aria-hidden="true"><x-icon name="building"/></span><span>Sales &amp; Marketing</span></a>
    @endif
  </nav>
</section>
