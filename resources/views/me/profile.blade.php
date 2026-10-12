@extends('layouts.app')
@section('title', 'My Profile')
@section('crumbs') {{ $e->name }} · {{ $e->emp_code }} @endsection

@if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h3>My details</h3></div>
    <div class="card-body">
      <p class="muted">Change requests go to HR for approval — your record updates only after they approve.</p>
      <form method="post" action="{{ route('me.profile.update') }}">@csrf
        @foreach ($fields as $f => $label)
        <div class="x-field"><label>{{ $label }}</label>
          <input name="{{ $f }}" value="{{ old($f, $e->{$f}) }}" maxlength="255"
            {{ in_array($f, ['address'], true) ? '' : '' }}
            placeholder="{{ $e->{$f} ?: '—' }}">
          @error($f)<div class="field-error">{{ $message }}</div>@enderror
        </div>
        @endforeach
        @error('profile')<div class="field-error">{{ $message }}</div>@enderror
        <button class="btn btn-primary" id="saveProfile"><x-icon name="check"/> Request change</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Recent requests</h3></div>
    <div class="card-body">
      @if ($requests->isEmpty())
        <x-empty text="No change requests yet." icon="user"/>
      @else
        <table class="table table-compact">
          <thead><tr><th>Requested</th><th>Fields</th><th>Status</th><th>HR note</th></tr></thead>
          <tbody>
            @foreach ($requests as $r)
            <tr>
              <td class="small">{{ $r->created_at->format('j M Y') }}</td>
              <td class="small">{{ collect($r->changes)->keys()->map(fn ($k) => $fields[$k] ?? $k)->implode(', ') }}</td>
              <td><span class="badge badge-{{ $r->statusTone() }}">{{ $r->statusLabel() }}</span></td>
              <td class="small">{{ $r->review_note ?: '—' }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </div>
</div>
