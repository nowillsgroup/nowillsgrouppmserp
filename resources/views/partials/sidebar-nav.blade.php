@php
  $depth = $depth ?? 0;
  $parentKey = $parentKey ?? '';
  $isActive = $isActive ?? function (array $item) use (&$isActive): bool {
      if (isset($item['route'])) {
          $target = route($item['route'], $item['params'] ?? []);
          if (request()->url() !== strtok($target, '?')) {
              return false;
          }
          parse_str((string) parse_url($target, PHP_URL_QUERY), $targetQuery);
          foreach ($targetQuery as $key => $value) {
              if (request()->query($key) != $value) {
                  return false;
              }
          }

          return true;
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

      // v10.7 the sidebar hid nothing the licence had switched off: the permission test alone let the
      // screens of an unlicensed module sit there and refuse on the click, while the refusal page
      // says plainly that switching a module off "hides its screens". An item now stays only when
      // the user holds a permission the licence in force here also allows — the module can be licensed
      // on the group and switched off at this property, and both cases hide it.
      return collect(explode(',', $item['perm']))->contains(
          fn ($permission) => $user->hasPermission(trim($permission))
              && \App\Services\LicenceService::licensed(trim($permission))
      );
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
