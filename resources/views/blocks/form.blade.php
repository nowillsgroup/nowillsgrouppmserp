@extends('layouts.app')
@section('title', $block ? 'Edit '.$block->block_no : 'New Group Block')
@section('crumbs') Reservations / Group Blocks / {{ $block ? 'Edit' : 'New' }} @endsection
@section('content')
@php
  $formArrival = old('arrival_date', $block?->arrival_date?->toDateString() ?? bdate()->copy()->addDays(14)->toDateString());
  $formDeparture = old('departure_date', $block?->departure_date?->toDateString() ?? bdate()->copy()->addDays(16)->toDateString());
  $formRoomTotal = collect($types)->sum(fn ($type) => (int) old('lines.'.$type->id, $lines[$type->id] ?? 0));
@endphp
<section class="gbf-hero" aria-label="Group block setup">
  <span class="gbf-hero-icon"><x-icon name="users"/></span>
  <div class="gbf-hero-copy">
    <div class="gbf-eyebrow">GROUP SALES <span aria-hidden="true">/</span> INVENTORY PLAN</div>
    <p>{{ $block ? 'Update the stay window, allotment and terms for this group. Picked-up reservations remain linked to the block.' : 'Set the group stay window and the room inventory to hold. Live availability is rechecked when you save.' }}</p>
  </div>
  @if($block)<span class="gbf-state">{{ ucfirst($block->status) }} block</span>@endif
</section>

@if($errors->any())
  <div class="alert alert-error gbf-errors" role="alert"><strong>Review the highlighted information.</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
@endif

<form method="post" action="{{ $block ? route('blocks.update', $block) : route('blocks.store') }}" class="gbf-form">@csrf @if($block) @method('PUT') @endif
  <div class="gbf-grid">
    <section class="card gbf-card gbf-group-card">
      <div class="card-head gbf-card-head"><span class="gbf-step">01</span><div><h2>Group details</h2><p>Identify the organiser and how this stay is sourced and billed.</p></div></div>
      <div class="card-body form-grid">
        <x-field name="name" label="Group / event name" :value="$block?->name" required class="span-all" placeholder="Mensah–Owusu wedding"/>
        <x-field name="company_id" label="Company" type="select" :options="$companies" :value="$block?->company_id"/>
        <x-field name="business_source_id" label="Business source" type="select" :options="$sources" :value="$block?->business_source_id"/>
        <x-field name="contact_name" label="Contact person" :value="$block?->contact_name"/>
        <x-field name="contact_phone" label="Contact phone" :value="$block?->contact_phone"/>
        <x-field name="contact_email" label="Contact email" type="email" :value="$block?->contact_email"/>
        <x-field name="billing_instruction" label="Billing" type="select" :options="['guest' => 'Each guest pays', 'company' => 'Bill to company']" :value="$block?->billing_instruction ?? 'guest'" :placeholder="false"/>
        <x-field name="status" label="Block status" type="select" :options="['tentative' => 'Tentative', 'definite' => 'Definite']" :value="$block?->status ?? 'tentative'" :placeholder="false" hint="Tentative and definite blocks both hold inventory."/>
        <x-field name="notes" label="Internal notes" type="textarea" :value="$block?->notes" class="span-all" placeholder="Special arrangements or booking instructions"/>
      </div>
    </section>

    <section class="card gbf-card gbf-dates-card">
      <div class="card-head gbf-card-head"><span class="gbf-step">02</span><div><h2>Stay dates &amp; rate</h2><p>Define when rooms are held and when unpicked rooms release.</p></div></div>
      <div class="card-body form-grid">
        <x-field name="arrival_date" label="Arrival" type="date" :value="$formArrival" required/>
        <x-field name="departure_date" label="Departure" type="date" :value="$formDeparture" required/>
        <x-field name="cutoff_date" label="Cut-off / release date" type="date" :value="old('cutoff_date', $block?->cutoff_date?->toDateString() ?? bdate()->copy()->addDays(7)->toDateString())" required hint="Unpicked rooms release at night audit on this date." class="span-all"/>
        <x-field name="rate" label="Group rate per night" type="number" step="0.01" min="0" :value="$block?->rate" hint="Optional. Leave blank to use each room type's rate plan." class="span-all"/>
      </div>
    </section>

    <section class="card gbf-card gbf-rooms-card">
      <div class="card-head gbf-card-head"><span class="gbf-step">03</span><div><h2>Rooms to hold</h2><p>Set the number of rooms to reserve for each room type, per night.</p></div><span class="gbf-per-night">PER NIGHT</span></div>
      <div class="card-body">
        @error('lines')<div class="alert alert-error gbf-line-error">{{ $message }}</div>@enderror
        @if($types->isNotEmpty())
        <div class="table-wrap gbf-room-table-wrap">
          <table class="table gbf-room-table">
            <caption class="sr-only">Room quantities and base nightly rates for this group block</caption>
            <thead><tr><th scope="col">Room type</th><th scope="col" class="right">Base rate</th><th scope="col" class="right">Rooms</th></tr></thead>
            <tbody>
            @foreach($types as $t)
              <tr>
                <th scope="row"><span class="gbf-room-code">{{ $t->code }}</span><span class="gbf-room-name">{{ $t->name }}</span></th>
                <td class="right gbf-base-rate">{{ money($t->base_rate) }}<span>/ night</span></td>
                <td class="right"><label class="sr-only" for="block-room-{{ $t->id }}">Rooms to hold for {{ $t->name }}</label><input class="gbf-room-count" id="block-room-{{ $t->id }}" type="number" name="lines[{{ $t->id }}]" min="0" max="500" step="1" value="{{ old('lines.'.$t->id, $lines[$t->id] ?? 0) }}" data-block-room-count></td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
        @else
          <div class="gbf-no-types"><x-icon name="alert"/><div><strong>No active room types are available.</strong><p>Activate at least one room type before creating a group allotment.</p></div></div>
        @endif
        <div class="gbf-availability-note"><x-icon name="shield"/><span>Availability is checked for every night and rechecked by the server on save. Overbooking is refused.</span></div>
      </div>
    </section>

    <aside class="gbf-summary gbf-plan-summary" aria-label="Stay dates and allotment summary" aria-labelledby="gbfSummaryHeading">
      <div class="gbf-summary-head"><span class="gbf-summary-mark"><x-icon name="layers"/></span><div><span class="gbf-eyebrow">ALLOTMENT CHECK</span><h2 id="gbfSummaryHeading">Plan summary</h2></div></div>
      <div class="gbf-summary-stats">
        <div><strong id="gbfRoomTotal">{{ number_format($formRoomTotal) }}</strong><span>rooms / night</span></div>
        <div><strong id="gbfNightTotal">—</strong><span>nights</span></div>
        <div><strong id="gbfRoomNightTotal">—</strong><span>room-nights</span></div>
      </div>
      <p id="gbfSummaryText" class="gbf-summary-text">Room availability is confirmed when you save the block.</p>
      <p class="sr-only" id="gbfSummaryAnnouncement" role="status" aria-live="polite" aria-atomic="true"></p>
      <div class="gbf-aside-note"><x-icon name="info"/><span>This summary is a planning aid. The saved block uses the validated dates, selected room quantities and billing terms.</span></div>
    </aside>
  </div>

  <div class="gbf-form-footer"><a href="{{ $block ? route('blocks.show', $block) : route('blocks.index') }}" class="btn btn-ghost">Cancel</a><button class="btn btn-primary" type="submit"><x-icon name="check" size="sm"/> {{ $block ? 'Save block changes' : 'Create group block' }}</button></div>
</form>

@push('head')
<style>
.gbf-hero{display:flex;align-items:center;gap:15px;margin:0 0 16px;padding:16px 18px;border:1px solid rgba(20,83,45,.12);border-radius:13px;color:#fff;background:radial-gradient(circle at 86% 0,rgba(187,247,208,.18),transparent 28%),linear-gradient(118deg,#103a23,#176239 68%,#247849);box-shadow:0 10px 24px rgba(20,83,45,.1)}
.gbf-hero-icon{display:inline-flex;width:44px;height:44px;flex:0 0 44px;align-items:center;justify-content:center;border:1px solid rgba(255,255,255,.22);border-radius:12px;background:rgba(255,255,255,.1);color:#d7f5df}
.gbf-hero-icon .i{width:22px;height:22px}
.gbf-hero-copy{min-width:0;flex:1}
.gbf-eyebrow{display:block;margin-bottom:3px;color:#c8e8d0;font-size:9px;font-weight:850;letter-spacing:.12em}
.gbf-eyebrow span{padding:0 4px;color:rgba(255,255,255,.6)}
.gbf-hero-copy p{margin:0;color:rgba(255,255,255,.84);font-size:12px;line-height:1.45}
.gbf-state{padding:6px 9px;border:1px solid rgba(255,255,255,.23);border-radius:999px;background:rgba(255,255,255,.09);color:#fff;font-size:10px;font-weight:750;white-space:nowrap}
.gbf-errors{margin:0 0 14px}
.gbf-errors ul{margin:5px 0 0;padding-left:18px}
.gbf-form{max-width:1240px}
.gbf-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(300px,.8fr);grid-template-areas:"group dates" "rooms summary";align-items:start;gap:14px}
.gbf-group-card{grid-area:group}
.gbf-dates-card{grid-area:dates}
.gbf-rooms-card{grid-area:rooms}
.gbf-plan-summary{grid-area:summary;display:flex;flex-direction:column;gap:10px}
.gbf-aside-note{margin-top:auto}
.gbf-card{overflow:hidden;border:1px solid var(--c-border);border-radius:12px;box-shadow:0 3px 10px rgba(15,23,42,.035)}
.gbf-card-head{display:flex;align-items:flex-start;gap:10px;padding:14px 16px 10px;border-bottom:1px solid var(--c-border)}
.gbf-card-head>div{min-width:0;flex:1}
.gbf-card-head h2{margin:0;color:var(--c-text);font-size:14px;font-weight:800}
.gbf-card-head p{margin:3px 0 0;color:var(--c-muted);font-size:10px;line-height:1.4}
.gbf-step{display:inline-flex;width:25px;height:25px;flex:0 0 25px;align-items:center;justify-content:center;border:1px solid #d1e7d7;border-radius:8px;background:#f0fdf4;color:#166534;font-size:9px;font-weight:850;font-variant-numeric:tabular-nums}
.gbf-per-night{align-self:center;padding:4px 7px;border:1px solid var(--c-border);border-radius:999px;background:var(--c-bg);color:var(--c-muted);font-size:8px;font-weight:850;letter-spacing:.08em;white-space:nowrap}
.gbf-card .card-body{padding:16px}
.gbf-room-table-wrap{max-height:420px;overflow:auto;border:1px solid var(--c-border);border-radius:9px}
.gbf-room-table{min-width:430px;margin:0;border-collapse:separate;border-spacing:0}
.gbf-room-table th,.gbf-room-table td{padding:9px 11px;border-bottom:1px solid var(--c-border);vertical-align:middle}
.gbf-room-table thead th{position:sticky;top:0;z-index:1;background:var(--c-bg);color:var(--c-muted);font-size:9px;font-weight:800;letter-spacing:.07em;text-transform:uppercase}
.gbf-room-table tbody tr:last-child th,.gbf-room-table tbody tr:last-child td{border-bottom:0}
.gbf-room-table tbody tr:hover th,.gbf-room-table tbody tr:hover td{background:rgba(34,139,78,.035)}
.gbf-room-table tbody th{font-weight:400;text-align:left}
.gbf-room-code{display:inline-flex;min-width:38px;justify-content:center;padding:3px 5px;border-radius:5px;background:#f1f5f9;color:#475569;font-size:9px;font-weight:800}
.gbf-room-name{display:inline-block;margin-left:7px;color:var(--c-text);font-size:11px;font-weight:700}
.gbf-base-rate{font-size:11px;font-weight:750;white-space:nowrap}
.gbf-base-rate span{display:block;color:var(--c-muted);font-size:9px;font-weight:500}
.gbf-room-count{width:76px;min-height:34px;padding:5px 8px;border:1px solid var(--c-border);border-radius:7px;background:var(--c-bg);color:var(--c-text);font:inherit;font-size:12px;font-variant-numeric:tabular-nums;text-align:right}
.gbf-room-count:focus{border-color:#15803d;outline:0;box-shadow:0 0 0 3px rgba(21,128,61,.12)}
.gbf-room-count.is-invalid{border-color:#dc2626}
.gbf-line-error{margin:0 0 10px}
.gbf-availability-note{display:flex;gap:7px;align-items:flex-start;margin-top:11px;padding:9px 10px;border:1px solid #d1e7d7;border-radius:8px;background:#f0fdf4;color:#166534;font-size:10px;line-height:1.45}
.gbf-availability-note .i{width:14px;height:14px;flex:0 0 14px;margin-top:1px}
.gbf-no-types{display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px dashed #fcd34d;border-radius:9px;background:#fffbeb;color:#92400e;font-size:11px}
.gbf-no-types>.i{width:17px;height:17px;flex:0 0 17px}
.gbf-no-types p{margin:3px 0 0}
.gbf-summary{padding:15px;border:1px solid #cde5d4;border-radius:12px;background:linear-gradient(145deg,#f0fdf4,#fff);box-shadow:0 4px 12px rgba(20,83,45,.06)}
.gbf-summary-head{display:flex;gap:9px;align-items:center}
.gbf-summary-mark{display:inline-flex;width:33px;height:33px;align-items:center;justify-content:center;border:1px solid #d1e7d7;border-radius:9px;background:#fff;color:#15803d}
.gbf-summary-mark .i{width:16px;height:16px}
.gbf-summary-head .gbf-eyebrow{margin:0;color:#15803d}
.gbf-summary-head h2{margin:2px 0 0;color:#14532d;font-size:14px;font-weight:800}
.gbf-summary-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:7px;margin-top:14px}
.gbf-summary-stats>div{display:flex;min-width:0;flex-direction:column;gap:3px;padding:9px 7px;border:1px solid #e2e8f0;border-radius:8px;background:#fff}
.gbf-summary-stats strong{overflow:hidden;color:#14532d;font-size:18px;font-variant-numeric:tabular-nums;line-height:1.1;text-overflow:ellipsis;white-space:nowrap}
.gbf-summary-stats span{color:#64748b;font-size:9px;font-weight:700;line-height:1.25}
.gbf-summary-text{margin:10px 0 0;color:#475569;font-size:10px;line-height:1.45}
.gbf-aside-note{display:flex;gap:7px;align-items:flex-start;padding:0 3px;color:var(--c-muted);font-size:10px;line-height:1.45}
.gbf-aside-note .i{width:14px;height:14px;flex:0 0 14px;color:#15803d}
.gbf-form-footer{position:sticky;bottom:0;z-index:5;display:flex;justify-content:flex-end;gap:8px;margin:14px 0 0;padding:10px 12px;border:1px solid var(--c-border);border-radius:11px;background:var(--c-surface);background:color-mix(in srgb,var(--c-surface) 92%,transparent);box-shadow:0 8px 24px rgba(15,23,42,.1);backdrop-filter:blur(10px)}
.gbf-form-footer .btn{min-height:38px}
@media(max-width:980px){.gbf-grid{grid-template-columns:minmax(0,1fr);grid-template-areas:"group" "dates" "rooms" "summary"}}
@media(max-width:620px){.gbf-hero{align-items:flex-start;gap:10px;padding:13px}.gbf-hero-icon{width:38px;height:38px;flex-basis:38px}.gbf-hero-copy p{font-size:11px}.gbf-grid{gap:10px}.gbf-card-head{padding:12px 12px 9px}.gbf-card .card-body{padding:12px}.gbf-room-table{min-width:390px}.gbf-room-table th,.gbf-room-table td{padding:8px}.gbf-form-footer{bottom:8px;padding:8px}.gbf-form-footer .btn{flex:1;justify-content:center;padding:8px 6px;font-size:11px}}
body.dark .gbf-step{border-color:#3b5946;background:#193525;color:#86efac}
body.dark .gbf-per-night{background:var(--c-bg);color:var(--c-muted)}
body.dark .gbf-room-code{background:#243229;color:#c1d2c5}
body.dark .gbf-room-table thead th{background:var(--c-surface)}
body.dark .gbf-room-table tbody tr:hover th,body.dark .gbf-room-table tbody tr:hover td{background:rgba(91,212,135,.07)}
body.dark .gbf-availability-note{border-color:#3b5946;background:#193525;color:#bbf7d0}
body.dark .gbf-no-types{border-color:#854d0e;background:#422006;color:#fcd34d}
body.dark .gbf-summary{border-color:#3b5946;background:linear-gradient(145deg,#193525,var(--c-surface))}
body.dark .gbf-summary-mark{border-color:#3b5946;background:#18291f;color:#86efac}
body.dark .gbf-summary-head .gbf-eyebrow,body.dark .gbf-summary-head h2{color:#bbf7d0}
body.dark .gbf-summary-stats>div{border-color:var(--c-border);background:var(--c-surface)}
body.dark .gbf-summary-stats strong{color:#d7f5df}
body.dark .gbf-summary-stats span,body.dark .gbf-summary-text{color:var(--c-muted)}
body.dark .gbf-form-footer{background:color-mix(in srgb,var(--c-surface) 92%,transparent)}
</style>
@endpush
@push('scripts')
<script>
(() => {
  const roomInputs = [...document.querySelectorAll('[data-block-room-count]')];
  const arrival = document.getElementById('f_arrival_date');
  const departure = document.getElementById('f_departure_date');
  const roomTotal = document.getElementById('gbfRoomTotal');
  const nightTotal = document.getElementById('gbfNightTotal');
  const roomNightTotal = document.getElementById('gbfRoomNightTotal');
  const summaryText = document.getElementById('gbfSummaryText');
  const announcement = document.getElementById('gbfSummaryAnnouncement');
  if (!roomTotal || !nightTotal || !roomNightTotal || !summaryText) return;

  const dayNumber = value => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return null;
    const [year, month, day] = value.split('-').map(Number);
    return Date.UTC(year, month - 1, day) / 86400000;
  };
  const numberFormat = new Intl.NumberFormat();
  function refreshSummary() {
    const totalRooms = roomInputs.reduce((sum, input) => sum + Math.max(0, Number.parseInt(input.value, 10) || 0), 0);
    const start = dayNumber(arrival?.value);
    const end = dayNumber(departure?.value);
    const nights = start !== null && end !== null && end > start ? end - start : 0;
    const roomNights = totalRooms * nights;
    roomTotal.textContent = numberFormat.format(totalRooms);
    nightTotal.textContent = nights ? numberFormat.format(nights) : '—';
    roomNightTotal.textContent = nights ? numberFormat.format(roomNights) : '—';
    summaryText.textContent = totalRooms === 0
      ? 'Add at least one room quantity. Availability is confirmed when you save the block.'
      : (nights > 0
        ? `${numberFormat.format(totalRooms)} room${totalRooms === 1 ? '' : 's'} across ${numberFormat.format(nights)} night${nights === 1 ? '' : 's'}. Availability is confirmed when you save the block.`
        : 'Choose a valid departure date after arrival.');
    if (announcement) announcement.textContent = `${numberFormat.format(totalRooms)} rooms per night; ${nights ? numberFormat.format(nights) + ' nights and ' + numberFormat.format(roomNights) + ' room-nights' : 'stay length not set'}.`;
  }
  roomInputs.forEach(input => input.addEventListener('input', refreshSummary));
  arrival?.addEventListener('change', refreshSummary);
  departure?.addEventListener('change', refreshSummary);
  refreshSummary();
})();
</script>
@endpush
@endsection
