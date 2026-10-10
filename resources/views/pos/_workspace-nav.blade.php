@php
  $posReport = request()->route('report');
  $posNav = [
      ['label' => 'POS', 'icon' => 'cup', 'href' => route('pos.index'), 'active' => request()->routeIs('pos.index'), 'show' => can_do('pos.view')],
      ['label' => 'Live Orders', 'icon' => 'bell', 'href' => route('pos.orders', ['status' => 'open']), 'active' => request()->routeIs('pos.orders') && request('status') === 'open', 'show' => can_do('pos.view')],
      ['label' => 'Unsettled Invoices', 'icon' => 'receipt', 'href' => route('pos.orders', ['status' => 'unsettled']), 'active' => request()->routeIs('pos.orders') && request('status') === 'unsettled', 'show' => can_do('pos.view')],
      ['label' => 'Cash Balance', 'icon' => 'money', 'href' => route('shifts.index'), 'active' => request()->routeIs('shifts.*'), 'show' => can_do('folio.shift')],
      ['label' => 'Invoices', 'icon' => 'receipt', 'href' => route('pos.orders', ['status' => 'settled']), 'active' => request()->routeIs('pos.orders') && request('status') === 'settled', 'show' => can_do('pos.view')],
      ['label' => 'Outlet Orders', 'icon' => 'layers', 'href' => route('pos.orders'), 'active' => request()->routeIs('pos.orders') && ! request()->filled('status'), 'show' => can_do('pos.view')],
      ['label' => 'Collections', 'icon' => 'wallet', 'href' => route('reports.show', 'collection'), 'active' => $posReport === 'collection', 'show' => can_do('reports.view')],
  ];
@endphp
<nav class="pos-workspace-nav" aria-label="POS workspace">
  @foreach($posNav as $link)
    @if($link['show'])<a class="pos-workspace-link{{ $link['active'] ? ' active' : '' }}" href="{{ $link['href'] }}"><x-icon name="{{ $link['icon'] }}" size="sm"/><span>{{ $link['label'] }}</span></a>@endif
  @endforeach
</nav>
