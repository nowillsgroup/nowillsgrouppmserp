@php
  $depth = $depth ?? 0;
  $parentKey = $parentKey ?? '';
  $isActive = $isActive ?? function (array $item) use (&$isActive): bool {
      if (isset($item['route'])) {
          return request()->url() === route($item['route'], $item['params'] ?? []);
      }

      foreach ($item['children'] ?? [] as $child) {
          if ($isActive($child)) {
              return true;
          }
      }

      return false;
  };
  $canSee = $canSee ?? function (array $item) use (&$canSee, $user, $myEmployee): bool {
      if (($item['employee'] ?? false) && ! $myEmployee) {
          return false;
      }

      if (! empty($item['children'])) {
          foreach ($item['children'] as $child) {
              if ($canSee($child)) {
                  return true;
              }
          }

          return false;
      }

      if (empty($item['perm'])) {
          return true;
      }

      return collect(explode(',', $item['perm']))->contains(fn ($permission) => $user->hasPermission(trim($permission)));
  };
  $lastSection = null;
@endphp

@foreach($items as $item)
  @continue(! $canSee($item))
  @php
    $key = trim($parentKey.'.'.\Illuminate\Support\Str::slug($item['label']), '.');
    $active = $isActive($item);
    $hasChildren = ! empty($item['children']);
    $isCategory = $depth === 0;
    $section = $depth === 0 ? ($item['section'] ?? null) : null;
  @endphp
  @if($section && $section !== $lastSection)
    <div class="nav-section-label">{{ $section }}</div>
    @php $lastSection = $section; @endphp
  @endif
  @if($hasChildren)
    <div class="nav-item nav-group {{ $isCategory ? 'nav-category' : 'nav-module' }} {{ $active ? 'open' : '' }}" data-nav-key="{{ $key }}" data-nav-label="{{ $item['label'] }}">
      <button type="button" aria-expanded="{{ $active ? 'true' : 'false' }}" aria-controls="{{ $key }}-children" title="{{ $item['label'] }}">
        <x-icon :name="$item['icon'] ?? 'grid'"/>
        <span class="nav-label">{{ $item['label'] }}</span>
        <svg class="i chev" aria-hidden="true"><use href="#i-chev"/></svg>
      </button>
      <div class="nav-sub" id="{{ $key }}-children">
        @include('partials.sidebar-nav', ['items' => $item['children'], 'depth' => $depth + 1, 'parentKey' => $key, 'isActive' => $isActive, 'canSee' => $canSee])
      </div>
    </div>
  @elseif(isset($item['route']))
    <div class="nav-item nav-link-item {{ $active ? 'active' : '' }}" data-nav-key="{{ $key }}" data-nav-label="{{ $item['label'] }}">
      <a href="{{ route($item['route'], $item['params'] ?? []) }}" @if($active) aria-current="page" @endif title="{{ $item['label'] }}">
        <x-icon :name="$item['icon'] ?? 'grid'"/>
        <span class="nav-label">{{ $item['label'] }}</span>
        @include('partials.sidebar-badge', ['item' => $item])
      </a>
    </div>
  @endif
@endforeach
