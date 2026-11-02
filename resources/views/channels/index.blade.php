@extends('layouts.app')
@section('title', 'iCal Channel Feeds')
@section('crumbs') <a href="{{ route('cm.index') }}">Channel Manager</a> / iCal feeds @endsection
@section('actions')<button type="button" class="btn btn-primary btn-icon cm-action" data-modal="chNew" aria-label="Add iCal channel feed" title="Add iCal feed"><x-icon name="plus"/></button>@endsection
@section('content')
@php
  $icalTotal = $channels->count();
  $icalActive = $channels->where('is_active', true)->count();
  $icalImports = $channels->filter(fn ($feed) => filled($feed->import_url))->count();
  $icalUpcoming = $channels->sum('upcoming_count');
  $icalIssues = $channels->filter(fn ($feed) => $feed->last_sync_at && (str_starts_with((string) $feed->last_sync_status, 'ERROR') || str_contains((string) $feed->last_sync_status, 'CONFLICT')))->count();
@endphp
<div class="cm-page cm-ical-page">
@include('channel-manager._nav')
<header class="cm-hero"><div class="cm-hero-copy"><div class="cm-kicker"><x-icon name="calendar"/> Calendar-link distribution</div><h2>iCal channel feeds</h2><p>Publish Nowills availability to OTAs and bring their calendar bookings into your PMS.</p></div><span class="cm-hero-mark" aria-hidden="true"><x-icon name="calendar"/></span></header>

<section class="cm-stat-grid cm-ical-stats" aria-label="iCal feed summary">
  <article class="stat"><div class="l">Configured feeds</div><div class="v">{{ $icalTotal }}</div><div class="tiny muted">Across your channel listings</div></article>
  <article class="stat"><div class="l">Active exports</div><div class="v">{{ $icalActive }} <span class="cm-stat-total">/ {{ $icalTotal }}</span></div><div class="tiny muted">Available to share with OTAs</div></article>
  <article class="stat"><div class="l">Two-way imports</div><div class="v">{{ $icalImports }}</div><div class="tiny muted">OTA calendars configured</div></article>
  <article class="stat"><div class="l">Upcoming bookings</div><div class="v">{{ $icalUpcoming }}</div><div class="tiny muted">Across all configured feeds</div></article>
  <article class="stat {{ $icalIssues ? 'cm-ical-stat-warning' : '' }}"><div class="l">Sync issues</div><div class="v">{{ $icalIssues }}</div><div class="tiny muted">Errors or booking conflicts</div></article>
</section>

<section class="cm-ical-flow" aria-label="How iCal feeds work">
  <div class="cm-ical-flow-step"><span class="cm-ical-flow-icon" aria-hidden="true"><x-icon name="globe"/></span><div><b>Export availability</b><p>Copy a feed URL into the matching OTA listing. Sold-out and stop-sell dates are shared from Nowills PMS.</p></div></div>
  <div class="cm-ical-flow-step"><span class="cm-ical-flow-icon" aria-hidden="true"><x-icon name="refresh"/></span><div><b>Import OTA calendars</b><p>Add the OTA’s iCal URL to receive new bookings and changes. Imports run every 15 minutes; use <b>Sync now</b> for an immediate check.</p></div></div>
</section>

<section class="card flush cm-ical-register" aria-label="Configured iCal channel feeds">
  <div class="card-head cm-ical-register-head"><div><h3>Feed register</h3><span class="tiny muted">One feed per channel listing and room type.</span></div><span class="badge badge-slate">{{ $icalTotal }} {{ \Illuminate\Support\Str::plural('feed', $icalTotal) }}</span></div>
  <div class="cm-ical-toolbar">
    <label class="cm-ical-search"><x-icon name="search"/><span class="cm-sr-only">Search feeds</span><input type="search" id="icalSearch" placeholder="Search channel, room type or source" autocomplete="off"></label>
    <label class="cm-ical-filter"><span class="cm-sr-only">Filter feeds</span><select id="icalFilter" aria-label="Filter iCal feeds">
      <option value="all">All feeds</option><option value="active">Active</option><option value="paused">Paused</option><option value="import">Import enabled</option><option value="export">Export only</option><option value="attention">Sync issues</option><option value="never">Never synced</option>
    </select></label>
    <p class="cm-ical-result-count" id="icalResultCount" aria-live="polite" role="status">Showing {{ $icalTotal }} of {{ $icalTotal }} feeds</p>
    <button type="button" class="btn btn-sm cm-ical-clear" id="icalClear" hidden>Clear filters</button>
  </div>
  <div class="card-body table-wrap cm-ical-table-wrap" role="region" aria-label="Scrollable iCal channel feeds" tabindex="0">
    <table class="table cm-connection-table cm-ical-table" id="icalTable" aria-describedby="icalResultCount">
      <caption class="cm-sr-only">Configured iCal export and import links, sync status and upcoming bookings.</caption>
      <thead><tr><th scope="col">Channel listing</th><th scope="col">Room type</th><th scope="col">Export URL <span class="cm-ical-th-note">share with OTA</span></th><th scope="col">Import</th><th scope="col">Last sync</th><th scope="col" class="num">Upcoming</th><th scope="col"><span class="cm-sr-only">Actions</span></th></tr></thead>
      <tbody id="icalRows">
      @forelse($channels as $c)
        @php
          $syncState = ! $c->last_sync_at ? 'never-synced' : ((str_starts_with((string) $c->last_sync_status, 'ERROR') || str_contains((string) $c->last_sync_status, 'CONFLICT')) ? 'needs-attention' : 'healthy');
          $searchText = mb_strtolower(implode(' ', array_filter([$c->name, $c->roomType?->name, $c->source?->name])));
        @endphp
        <tr data-ical-feed data-search="{{ $searchText }}" data-active="{{ $c->is_active ? '1' : '0' }}" data-import="{{ filled($c->import_url) ? '1' : '0' }}" data-sync-state="{{ $syncState }}">
          <td><div class="cm-ical-channel"><b>{{ $c->name }}</b><span class="badge {{ $c->is_active ? 'badge-green' : 'badge-slate' }}">{{ $c->is_active ? 'Active' : 'Paused' }}</span></div><div class="tiny muted">{{ $c->source?->name ?? 'No business source' }}</div></td>
          <td>{{ $c->roomType?->name ?? 'Room type removed' }}</td>
          <td><div class="cm-copy-row"><input readonly value="{{ route('ical.feed', $c->export_token) }}" id="icalUrl{{ $c->id }}" aria-label="Export URL for {{ $c->name }}" title="{{ route('ical.feed', $c->export_token) }}" onclick="this.select()"><button type="button" class="btn btn-sm cm-copy-button" data-copy-target="icalUrl{{ $c->id }}" data-copy-status="icalCopyStatus{{ $c->id }}" aria-label="Copy export URL for {{ $c->name }}" title="Copy export URL">Copy</button></div><span class="cm-inline-note" id="icalCopyStatus{{ $c->id }}" role="status" aria-live="polite">Tokenized public calendar feed</span></td>
          <td>@if(filled($c->import_url))<span class="cm-ical-mode cm-ical-mode-import"><span aria-hidden="true"></span>Two-way</span>@else<span class="cm-ical-mode cm-ical-mode-export"><span aria-hidden="true"></span>Export only</span>@endif</td>
          <td class="small"><div class="cm-ical-sync-state cm-ical-sync-{{ $syncState }}">@if($syncState === 'never-synced')Never synced @elseif($syncState === 'needs-attention')Needs attention @else Up to date @endif</div>@if($c->last_sync_at)<time class="cm-ical-sync-time" datetime="{{ $c->last_sync_at->toIso8601String() }}" title="{{ $c->last_sync_at->format('d M Y H:i') }}">{{ $c->last_sync_at->diffForHumans() }}</time><div class="cm-ical-sync-detail">{{ $c->last_sync_status }}</div>@else<span class="cm-ical-sync-detail">Add an import URL to start syncing</span>@endif</td>
          <td class="num">@if(can_do('reservations.view'))<a aria-label="{{ $c->upcoming_count }} upcoming bookings for {{ $c->name }}" href="{{ route('reservations.index', ['channel' => $c->id]) }}">{{ $c->upcoming_count }}</a>@else<span aria-label="{{ $c->upcoming_count }} upcoming bookings">{{ $c->upcoming_count }}</span>@endif</td>
          <td class="right nowrap"><div class="cm-ical-actions">
            @if(filled($c->import_url))<form method="post" action="{{ route('channels.sync', $c) }}" class="inline">@csrf<button type="submit" class="btn btn-sm btn-success" aria-label="Sync {{ $c->name }} now" title="Fetch the OTA calendar now"><x-icon name="refresh" size="sm"/><span>Sync now</span></button></form>@endif
            <button type="button" class="btn btn-sm btn-icon cm-action" data-modal="ch{{ $c->id }}" aria-label="Edit {{ $c->name }} iCal feed" title="Edit feed"><x-icon name="edit" size="sm"/></button>
          </div></td>
        </tr>
      @empty<tr class="cm-ical-empty-row"><td colspan="7"><x-empty title="No iCal feeds yet" text="Add a calendar feed for each OTA listing and room type. Export availability, then optionally add the OTA’s import URL for two-way syncing." icon="calendar"/><div class="center mt-1"><button type="button" class="btn btn-primary" data-modal="chNew"><x-icon name="plus"/> Add your first feed</button></div></td></tr>@endforelse
        <tr id="icalNoMatches" hidden><td colspan="7"><div class="cm-ical-no-matches"><b>No feeds match these filters.</b><span>Try another search or clear the filters.</span></div></td></tr>
      </tbody>
    </table>
  </div>
</section>

@foreach($channels->prepend(null) as $c)
@php $modalId = $c ? 'ch'.$c->id : 'chNew'; $modalTitleId = $modalId.'Title'; $modalDescId = $modalId.'Desc'; @endphp
<div class="modal cm-modal {{ $errors->any() && old('modal_id') === $modalId ? 'show' : '' }}" id="{{ $modalId }}"><div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="{{ $modalTitleId }}" aria-describedby="{{ $modalDescId }}" tabindex="-1">
  <form method="post" action="{{ $c ? route('channels.update', $c) : route('channels.store') }}">@csrf @if($c) @method('PUT') @endif
  <input type="hidden" name="modal_id" value="{{ $modalId }}">
  <div class="modal-head"><h3 id="{{ $modalTitleId }}">{{ $c ? 'Edit '.$c->name : 'Add iCal channel feed' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close iCal channel dialog" title="Close"><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <p class="cm-ical-modal-intro span-all" id="{{ $modalDescId }}">{{ $c ? 'Keep this OTA listing and room type paired with the correct calendar URLs. Regenerating the export URL immediately invalidates the old link.' : 'Create a calendar feed for one OTA listing and room type. The export URL shares Nowills availability; add the OTA’s iCal URL if you also want bookings imported.' }}</p>
    <x-field name="name" :id="$modalId.'Name'" label="Channel or listing name" required :value="$c?->name" placeholder="Airbnb – Deluxe listing"/>
    <x-field name="room_type_id" :id="$modalId.'RoomType'" label="Room type" type="select" :options="$types" required :value="$c?->room_type_id"/>
    <x-field name="business_source_id" :id="$modalId.'Source'" label="Business source (reports)" type="select" :options="$sources" :value="$c?->business_source_id"/>
    <label class="check cm-ical-active"><input type="hidden" name="is_active" value="0"><input type="checkbox" id="{{ $modalId }}Active" name="is_active" value="1" @checked($c ? $c->is_active : true)> Active — publish this export feed</label>
    <x-field name="import_url" :id="$modalId.'ImportUrl'" label="OTA calendar URL (import)" type="url" class="span-all" :value="$c?->import_url" placeholder="https://www.airbnb.com/calendar/ical/123.ics?s=…" hint="Optional. Leave blank for export-only. Configured imports run every 15 minutes or when you select Sync now."/>
  </div>
  <div class="modal-foot">
    @if($c)<button type="submit" form="del{{ $c->id }}" class="btn btn-danger" style="margin-right:auto">Delete feed</button>
      <button type="submit" form="regen{{ $c->id }}" class="btn" title="Regenerate the export token">New export URL</button>@endif
    <button type="button" class="btn" data-close>Cancel</button><button type="submit" class="btn btn-primary">{{ $c ? 'Save feed' : 'Add feed' }}</button></div>
  </form>
  @if($c)
  <form method="post" action="{{ route('channels.destroy', $c) }}" id="del{{ $c->id }}" data-confirm="Delete this iCal feed? Existing bookings are kept.">@csrf @method('DELETE')</form>
  <form method="post" action="{{ route('channels.regenerate', $c) }}" id="regen{{ $c->id }}" data-confirm="The current export URL will stop working. Update it in the OTA extranet. Continue?">@csrf</form>
  @endif
</div></div>
@endforeach
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const search = document.getElementById('icalSearch');
  const filter = document.getElementById('icalFilter');
  const count = document.getElementById('icalResultCount');
  const noMatches = document.getElementById('icalNoMatches');
  const clear = document.getElementById('icalClear');
  const rows = Array.from(document.querySelectorAll('[data-ical-feed]'));
  const runFilter = () => {
    const query = (search?.value || '').trim().toLocaleLowerCase();
    const mode = filter?.value || 'all';
    let visible = 0;
    rows.forEach(row => {
      const matchesText = (row.dataset.search || '').includes(query);
      const matchesMode = mode === 'all'
        || (mode === 'active' && row.dataset.active === '1')
        || (mode === 'paused' && row.dataset.active === '0')
        || (mode === 'import' && row.dataset.import === '1')
        || (mode === 'export' && row.dataset.import === '0')
        || (mode === 'attention' && row.dataset.syncState === 'needs-attention')
        || (mode === 'never' && row.dataset.syncState === 'never-synced');
      row.hidden = !(matchesText && matchesMode);
      if (!row.hidden) visible++;
    });
    if (noMatches) noMatches.hidden = visible > 0 || rows.length === 0;
    if (count) count.textContent = `Showing ${visible} of ${rows.length} ${rows.length === 1 ? 'feed' : 'feeds'}`;
    if (clear) clear.hidden = !query && mode === 'all';
  };
  search?.addEventListener('input', runFilter);
  filter?.addEventListener('change', runFilter);
  clear?.addEventListener('click', () => { if (search) search.value = ''; if (filter) filter.value = 'all'; runFilter(); search?.focus(); });

  document.querySelectorAll('[data-copy-target]').forEach(button => {
    let resetTimer;
    button.addEventListener('click', async () => {
      const input = document.getElementById(button.dataset.copyTarget);
      const status = document.getElementById(button.dataset.copyStatus);
      if (!input) return;
      const label = button.dataset.originalLabel ||= button.getAttribute('aria-label') || 'Copy export URL';
      const feedback = message => { if (status) status.textContent = message; };
      const copied = () => {
        button.textContent = 'Copied';
        button.setAttribute('aria-label', `Export URL copied for ${label.replace(/^Copy export URL for /, '')}`);
        feedback('Export URL copied to clipboard.');
        clearTimeout(resetTimer);
        resetTimer = window.setTimeout(() => { button.textContent = 'Copy'; button.setAttribute('aria-label', label); feedback('Tokenized public calendar feed'); }, 1800);
      };
      try {
        if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(input.value);
        else { input.focus(); input.select(); if (!document.execCommand('copy')) throw new Error('Copy is unavailable'); }
        copied();
      } catch (_) {
        input.focus(); input.select();
        button.textContent = 'Select and copy';
        button.setAttribute('aria-label', `Export URL selected for manual copy: ${label.replace(/^Copy export URL for /, '')}`);
        feedback('Clipboard access was unavailable. The URL is selected; copy it manually.');
      }
    });
  });
  @if($errors->any() && old('modal_id'))
    window.openModal?.(@json(old('modal_id')));
  @endif
});
</script>
@endsection
