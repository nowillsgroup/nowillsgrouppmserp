@props(['label', 'value', 'icon' => 'grid', 'tone' => 'blue', 'href' => null, 'sub' => null])
<div class="stat tone-{{ $tone }}">
  <div class="ico"><x-icon :name="$icon"/></div>
  <div><div class="v">{{ $value }}</div><div class="l">{{ $label }}</div>@if($sub)<div class="tiny muted">{{ $sub }}</div>@endif</div>
  @if($href)<a class="cover" href="{{ $href }}" aria-label="{{ $label }}"></a>@endif
</div>
