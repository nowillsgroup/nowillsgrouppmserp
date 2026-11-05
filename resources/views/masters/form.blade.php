@extends('layouts.app')
@section('title', ($row->exists ? 'Edit ' : 'New ').\Illuminate\Support\Str::singular($def['title']))
@section('crumbs') Masters / <a href="{{ route('masters.index', $def['slug']) }}">{{ $def['title'] }}</a> / {{ $row->exists ? 'Edit' : 'New' }} @endsection
@section('content')
@php
  $hasFile = collect($def['fields'])->contains(fn ($f) => ($f[1] ?? '') === 'image');
  $hasPhotos = ! empty($def['photos']);   // a record that keeps its own photo gallery (rooms)
  $hasUpload = $hasFile || $hasPhotos;
@endphp
<form method="post" action="{{ $row->exists ? route('masters.update', [$def['slug'], $row->id]) : route('masters.store', $def['slug']) }}" class="card" style="max-width:900px" @if($hasUpload) enctype="multipart/form-data" @endif>@csrf @if($row->exists) @method('PUT') @endif
<div class="card-body form-grid">
@foreach($def['fields'] as $name => $f)
  @php [$label, $type, $rules] = $f; $req = str_contains($rules, 'required'); $val = $row->{$name}; if ($val instanceof \Carbon\CarbonInterface) $val = $val->toDateString(); @endphp
  @if($type === 'bool')
    <div class="field"><label>&nbsp;</label><label class="check"><input type="hidden" name="{{ $name }}" value="0"><input type="checkbox" name="{{ $name }}" value="1" @checked(old($name, $val))> {{ $label }}</label></div>
  @elseif($type === 'select')
    <x-field :name="$name" :label="$label" type="select" :options="$options[$name] ?? []" :value="$val" :required="$req"/>
  @elseif($type === 'image')
    @php $col = $f[3] ?? 'image_path'; $url = method_exists($row, 'imageUrl') ? $row->imageUrl() : null; @endphp
    <div class="field span-all"><label for="f_{{ $name }}">{{ $label }}</label>
      @if($url)<img src="{{ $url }}" alt="" class="menu-thumb" style="width:140px;height:100px;margin-bottom:.45rem">@endif
      <input type="file" name="{{ $name }}" id="f_{{ $name }}" accept="image/jpeg,image/png,image/webp" class="@error($name) is-invalid @enderror">
      @if($url)<label class="check" style="margin-top:.4rem"><input type="checkbox" name="remove_{{ $name }}" value="1"> Remove photo</label>@endif
      <span class="hint">Shown on the POS till. JPG, PNG or WebP, up to 2 MB.</span>
      @error($name)<span class="err">{{ $message }}</span>@enderror
    </div>
  @elseif($type === 'textarea')
    <x-field :name="$name" :label="$label" type="textarea" :value="$val" :required="$req" class="span-all" rows="3"/>
  @elseif($type === 'number')
    <x-field :name="$name" :label="$label" type="number" step="any" :value="$val" :required="$req"/>
  @else
    <x-field :name="$name" :label="$label" :type="$type" :value="$val" :required="$req"/>
  @endif
@endforeach
@if($hasPhotos)
  <div class="field span-all">
    <label for="f_photos">{{ $row->exists ? 'Add room photos' : 'Room photos' }}</label>
    <input type="file" name="photos[]" id="f_photos" accept="image/jpeg,image/png,image/webp" multiple @class(['is-invalid' => $errors->has('photos') || $errors->has('photos.*')])>
    <div class="field" style="margin-top:.5rem"><label for="f_photo_caption">Caption for these photos (optional)</label>
      <input name="photo_caption" id="f_photo_caption" maxlength="160" value="{{ old('photo_caption') }}" placeholder="Describe the room, e.g. sea-facing balcony">
    </div>
    <span class="hint">Upload the real room — no stock hotel pictures. Up to 8 photos per save, JPG, PNG or WebP, 4 MB each. The first photo on a room becomes its primary one; the desk, the arrivals list and the room board show it.</span>
    @error('photos')<span class="err">{{ $message }}</span>@enderror
    @error('photos.*')<span class="err">{{ $message }}</span>@enderror
  </div>
@endif
</div>
<div class="card-foot"><a class="btn" href="{{ route('masters.index', $def['slug']) }}">Cancel</a><button class="btn btn-primary"><x-icon name="check"/> Save</button></div>
</form>
@if($hasPhotos && $row->exists)
  @include('masters.room-photos', ['def' => $def, 'room' => $row, 'photos' => $photos ?? collect()])
@endif
@endsection
