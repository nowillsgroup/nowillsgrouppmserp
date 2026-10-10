@props(['name', 'size' => null])
<svg {{ $attributes->merge(['class' => 'i'.($size === 'sm' ? ' i-sm' : '')]) }} aria-hidden="true"><use href="#i-{{ $name }}"/></svg>
