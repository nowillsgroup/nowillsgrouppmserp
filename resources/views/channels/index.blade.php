@extends('layouts.app')
@section('title', 'Channel Manager')
@section('crumbs') Reservations / Channel Manager (iCal) @endsection
@section('actions')<button class="btn btn-primary" data-modal="chNew"><x-icon name="plus"/> Add channel</button>@endsection
@section('content')
<div class="alert alert-info"><x-icon name="globe"/><div>
  <b>Two-way iCal sync</b> with Airbnb, Booking.com, Vrbo, Expedia and any OTA that supports calendar links.
  <b>Export</b>: paste the channel's export URL into the OTA – dates when the room type is sold out or stop-sold are closed there.
  <b>Import</b>: paste the OTA's calendar URL here – its bookings become reservations (updates and cancellations follow automatically every 15 minutes, or press <i>Sync now</i>).
</div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Channel</th><th>Room type</th><th>Export URL (give to OTA)</th><th>Import</th><th>Last sync</th><th class="num">Upcoming</th><th></th></tr></thead><tbody>
  @forelse($channels as $c)
    <tr>
      <td><b>{{ $c->name }}</b> @unless($c->is_active)<span class="badge badge-slate">paused</span>@endunless<div class="tiny muted">{{ $c->source?->name ?? 'No source' }}</div></td>
      <td>{{ $c->roomType?->name }}</td>
      <td style="max-width:280px"><div class="flex gap-1 items-center"><input readonly value="{{ route('ical.feed', $c->export_token) }}" onclick="this.select()" class="tiny" style="min-width:0;flex:1" id="u{{ $c->id }}">
        <button type="button" class="btn btn-sm" title="Copy" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('u{{ $c->id }}').value); this.textContent='Copied'">Copy</button></div></td>
      <td class="small">{!! $c->import_url ? '<span class="badge badge-blue">configured</span>' : '<span class="muted">export only</span>' !!}</td>
      <td class="small">@if($c->last_sync_at){{ $c->last_sync_at->diffForHumans() }}<div class="tiny {{ str_starts_with((string) $c->last_sync_status, 'OK') && !str_contains($c->last_sync_status, 'CONFLICT') ? 'text-green' : 'text-red' }}">{{ $c->last_sync_status }}</div>@else<span class="muted">never</span>@endif</td>
      <td class="num"><a href="{{ route('reservations.index', ['channel' => $c->id]) }}">{{ $c->upcoming_count }}</a></td>
      <td class="right nowrap">
        @if($c->import_url)<form method="post" action="{{ route('channels.sync', $c) }}" class="inline">@csrf<button class="btn btn-sm btn-success"><x-icon name="refresh" size="sm"/> Sync now</button></form>@endif
        <button class="btn btn-sm" data-modal="ch{{ $c->id }}"><x-icon name="edit" size="sm"/></button>
      </td>
    </tr>
  @empty<tr><td colspan="7"><x-empty title="No channels yet" text="Add a channel for each OTA listing (one per room type)." icon="globe"/></td></tr>@endforelse
  </tbody></table></div></div>

@foreach($channels->prepend(null) as $c)
<div class="modal" id="{{ $c ? 'ch'.$c->id : 'chNew' }}"><div class="modal-box">
  <form method="post" action="{{ $c ? route('channels.update', $c) : route('channels.store') }}">@csrf @if($c) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $c ? 'Edit '.$c->name : 'Add OTA channel' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="name" label="Channel name" required :value="$c?->name" placeholder="Airbnb – Deluxe listing"/>
    <x-field name="room_type_id" label="Room type" type="select" :options="$types" required :value="$c?->room_type_id"/>
    <x-field name="business_source_id" label="Business source (reports)" type="select" :options="$sources" :value="$c?->business_source_id"/>
    <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($c ? $c->is_active : true)> Active</label>
    <x-field name="import_url" label="OTA calendar URL (import)" type="url" class="span-all" :value="$c?->import_url" placeholder="https://www.airbnb.com/calendar/ical/123.ics?s=…" hint="Leave empty for export-only."/>
  </div>
  <div class="modal-foot">
    @if($c)<button type="submit" form="del{{ $c->id }}" class="btn btn-danger" style="margin-right:auto">Delete</button>
      <button type="submit" form="regen{{ $c->id }}" class="btn">New export URL</button>@endif
    <button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
  </form>
  @if($c)
  <form method="post" action="{{ route('channels.destroy', $c) }}" id="del{{ $c->id }}" data-confirm="Delete this channel? Existing bookings are kept.">@csrf @method('DELETE')</form>
  <form method="post" action="{{ route('channels.regenerate', $c) }}" id="regen{{ $c->id }}" data-confirm="The current export URL will stop working. Continue?">@csrf</form>
  @endif
</div></div>
@endforeach
@endsection
