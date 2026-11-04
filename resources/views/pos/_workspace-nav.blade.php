@php
  $posReport = request()->route('report');
  $posNav = [
      ['label' => 'My Shift', 'icon' => 'clock', 'href' => route('my-shift', [], false), 'active' => request()->routeIs('my-shift'), 'show' => can_do('pos.order') || can_do('pos.shift_view_all')],
      ['label' => 'Service Shift Reports', 'icon' => 'users', 'href' => route('department-shifts.index', [], false), 'active' => request()->routeIs('department-shifts.*'), 'show' => can_do('pos.order') || can_do('pos.shift_view_all')],
      ['label' => 'Dashboard', 'icon' => 'chart', 'href' => route('pos.dashboard', [], false), 'active' => request()->routeIs('pos.dashboard'), 'show' => can_do('pos.view')],
      ['label' => 'POS Terminal', 'icon' => 'cup', 'href' => route('pos.index', [], false), 'active' => request()->routeIs('pos.index'), 'show' => can_do('pos.view')],
      ['label' => 'Live Orders', 'icon' => 'bell', 'href' => route('pos.live-orders', [], false), 'active' => request()->routeIs('pos.live-orders'), 'show' => can_do('pos.view')],
      ['label' => 'Unsettled Invoices', 'icon' => 'receipt', 'href' => route('pos.unsettled', [], false), 'active' => request()->routeIs('pos.unsettled'), 'show' => can_do('pos.settle')],
      ['label' => 'Cash Balance', 'icon' => 'money', 'href' => route('shifts.index', [], false), 'active' => request()->routeIs('shifts.*'), 'show' => can_do('folio.shift')],
      ['label' => 'Invoices', 'icon' => 'receipt', 'href' => route('pos.orders', ['status' => 'settled'], false), 'active' => request()->routeIs('pos.orders') && request('status') === 'settled', 'show' => can_do('pos.view')],
      ['label' => 'Outlet Orders', 'icon' => 'layers', 'href' => route('pos.orders', [], false), 'active' => request()->routeIs('pos.orders') && ! request()->filled('status'), 'show' => can_do('pos.view')],
      ['label' => 'Collections', 'icon' => 'wallet', 'href' => route('reports.show', 'collection', false), 'active' => $posReport === 'collection', 'show' => can_do('reports.view')],
  ];
@endphp
<nav class="pos-workspace-nav" aria-label="POS workspace">
  @foreach($posNav as $link)
    @if($link['show'])<a class="pos-workspace-link{{ $link['active'] ? ' active' : '' }}" href="{{ $link['href'] }}" @if($link['active']) aria-current="page" @endif><x-icon name="{{ $link['icon'] }}" size="sm"/><span>{{ $link['label'] }}</span></a>@endif
  @endforeach
</nav>
