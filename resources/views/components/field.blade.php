@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'required' => false, 'options' => null, 'placeholder' => null, 'hint' => null, 'class' => ''])
@php $val = old($name, $value); $id = 'f_'.str_replace(['[',']'], '_', $name); @endphp
<div class="field {{ $class }}">
  @if($label)<label for="{{ $id }}">{{ $label }} @if($required)<span class="req">*</span>@endif</label>@endif
  @if($type === 'select')
    <select name="{{ $name }}" id="{{ $id }}" @if($required) required @endif {{ $attributes }} class="@error($name) is-invalid @enderror">
      @if($placeholder !== false)<option value="">{{ $placeholder ?? '— Select —' }}</option>@endif
      @foreach(($options ?? []) as $k => $v)
        <option value="{{ $k }}" @selected((string) $val === (string) $k)>{{ $v }}</option>
      @endforeach
    </select>
  @elseif($type === 'textarea')
    <textarea name="{{ $name }}" id="{{ $id }}" placeholder="{{ $placeholder }}" @if($required) required @endif {{ $attributes }} class="@error($name) is-invalid @enderror">{{ $val }}</textarea>
  @else
    <input type="{{ $type }}" name="{{ $name }}" id="{{ $id }}" value="{{ $val }}" placeholder="{{ $placeholder }}" @if($required) required @endif {{ $attributes }} class="@error($name) is-invalid @enderror">
  @endif
  @if($hint)<span class="hint">{{ $hint }}</span>@endif
  @error($name)<span class="err">{{ $message }}</span>@enderror
</div>
