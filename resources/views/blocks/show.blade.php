@extends('layouts.app')
@section('title', $block->block_no.' · '.$block->name)
@section('crumbs') Reservations / Group Blocks / {{ $block->block_no }} @endsection
@section('actions')
  @if($block->isHolding())
    @if(can_do('reservations.edit'))
      <a href="{{ route('blocks.edit', $block) }}" class="btn btn-icon" aria-label="Edit group block {{ $block->block_no }}" title="Edit block"><x-icon name="edit"/></a>
      @if($block->status === 'tentative')<form method="post" action="{{ route('blocks.status', $block) }}" class="inline" data-confirm="Mark this group block definite? It will continue holding inventory.">@csrf<input type="hidden" name="action" value="definite"><button type="submit" class="btn btn-icon" aria-label="Mark block definite" title="Mark definite"><x-icon name="check"/></button></form>@endif
      <form method="post" action="{{ route('blocks.status', $block) }}" class="inline" data-confirm="Release all unpicked rooms back to general inventory now?">@csrf<input type="hidden" name="action" value="release"><button type="submit" class="btn btn-icon" aria-label="Release unpicked rooms now" title="Release rooms"><x-icon name="refresh"/></button></form>
      <form method="post" action="{{ route('blocks.status', $block) }}" class="inline" data-confirm="Cancel this block? Reservations already picked up are kept.">@csrf<input type="hidden" name="action" value="cancel"><button type="submit" class="btn btn-icon btn-danger" aria-label="Cancel group block" title="Cancel block"><x-icon name="x"/></button></form>
    @endif
  @endif
@endsection
@push('head')
<style>
.gbd-overview{display:flex;align-items:center;gap:1rem;padding:1.1rem 1.3rem;margin:0 0 1rem;border-radius:16px;background:linear-gradient(118deg,#103a23,#176239 68%,#247849);color:#f5fff7;box-shadow:0 12px 28px rgba(16,58,35,.15)}
.gbd-overview-icon{display:grid;place-items:center;width:44px;height:44px;flex:0 0 44px;border:1px solid rgba(255,255,255,.22);border-radius:13px;background:rgba(255,255,255,.1)}.gbd-overview-icon svg{width:22px;height:22px}
.gbd-overview-copy{min-width:0;flex:1}.gbd-eyebrow{font-size:.68rem;font-weight:800;letter-spacing:.13em;color:#c7efd0}.gbd-overview-copy p{margin:.2rem 0 0;color:#e0f2e5;font-size:.88rem}
.gbd-status{display:inline-flex;align-items:center;justify-content:center;padding:.42rem .7rem;border-radius:999px;font-size:.68rem;font-weight:800;letter-spacing:.06em;white-space:nowrap;background:#ffffff20;color:white;border:1px solid #ffffff35}
.gbd-cutoff-alert{display:flex;align-items:flex-start;gap:.65rem;margin:0 0 1rem;padding:.8rem 1rem;border:1px solid #f3d08b;border-radius:12px;background:#fff8e8;color:#754b0b;font-size:.86rem}.gbd-cutoff-alert svg{width:19px;height:19px;flex:none;margin-top:1px}.gbd-cutoff-alert strong{margin-right:.25rem}
.gbd-stats{margin-bottom:1.15rem}.gbd-main-grid{align-items:start;margin-bottom:1rem}.gbd-card{min-width:0;overflow:hidden}.gbd-card-head{display:flex;align-items:center;justify-content:space-between;gap:.8rem}.gbd-card-head h3{margin:0}.gbd-card-head p{margin:.22rem 0 0}.gbd-legend{display:inline-flex;align-items:center;gap:.4rem;color:var(--c-muted);font-size:.72rem;white-space:nowrap}.gbd-legend-dot{width:9px;height:9px;border-radius:50%;background:#eab64b}
.gbd-table-wrap{overflow:auto}.gbd-pickup-table{min-width:560px}.gbd-pickup-table th,.gbd-pickup-table td{padding:.55rem .48rem}.gbd-pickup-table thead th{font-size:.7rem;line-height:1.2}.gbd-pickup-table tbody th{position:sticky;left:0;z-index:1;background:var(--c-card);text-align:left}.gbd-pickup-table .badge{min-width:48px;display:inline-flex;justify-content:center}
.gbd-details-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.55rem;margin-top:1rem;padding-top:.9rem;border-top:1px solid var(--c-border)}.gbd-detail-item{display:flex;flex-direction:column;gap:.18rem;min-width:0}.gbd-detail-item>span{color:var(--c-muted);font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.055em}.gbd-detail-item strong{font-size:.84rem;overflow-wrap:anywhere}.gbd-detail-contact{grid-column:1/-1}.gbd-detail-item small{display:block;margin-top:.14rem;color:var(--c-muted);font-size:.76rem;font-weight:400}.gbd-notes,.gbd-release-note{margin:.8rem 0 0;padding:.7rem .8rem;border-radius:9px;background:var(--c-bg);font-size:.82rem;white-space:pre-line;overflow-wrap:anywhere}.gbd-notes strong{font-size:.7rem;text-transform:uppercase;letter-spacing:.05em}.gbd-release-note{color:var(--c-muted)}
.gbd-pickup-form{gap:1rem}.gbd-submit-row{display:flex;align-items:center;gap:.7rem;flex-wrap:wrap}.gbd-closed-state{display:flex;gap:.75rem;align-items:flex-start;padding:.9rem 1rem;border:1px solid var(--c-border);border-radius:11px;background:var(--c-bg);color:var(--c-muted);font-size:.86rem}.gbd-closed-state p{margin:0}.gbd-count{display:grid;place-items:center;min-width:34px;height:34px;padding:0 .45rem;border-radius:11px;background:#e9f5ec;color:#176239;font-weight:800}.gbd-reservations-card{margin-top:0}.gbd-reservations-head{padding:1rem 1.2rem 0}.gbd-reservations-table{min-width:720px}
body.dark .gbd-overview{background:linear-gradient(118deg,#0d2718,#143d25 68%,#1d5433);box-shadow:none}body.dark .gbd-cutoff-alert{background:#332914;border-color:#695321;color:#f2d895}body.dark .gbd-count{background:#183a27;color:#a9e1b7}body.dark .gbd-pickup-table tbody th{background:var(--c-card)}body.dark .gbd-closed-state{background:var(--c-bg)}
@media(max-width:820px){.gbd-main-grid{grid-template-columns:1fr}.gbd-pickup-table{min-width:520px}}
@media(max-width:560px){.gbd-overview{align-items:flex-start;padding:.9rem;gap:.7rem}.gbd-overview-icon{width:38px;height:38px;flex-basis:38px}.gbd-status{padding:.35rem .5rem;font-size:.62rem}.gbd-overview-copy p{font-size:.78rem}.gbd-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.gbd-card-head{align-items:flex-start}.gbd-details-grid{gap:.7rem .5rem}.gbd-reservations-head{padding:.9rem .9rem 0}}
@media(prefers-reduced-motion:reduce){.gbd-overview,.gbd-card{scroll-behavior:auto}}
</style>
@endpush
@section('content')
@php $cutoffDays = (int) bdate()->copy()->startOfDay()->diffInDays($block->cutoff_date->copy()->startOfDay(), false); @endphp
<div class="gbd-overview"><span class="gbd-overview-icon"><x-icon name="layers"/></span><div class="gbd-overview-copy"><span class="gbd-eyebrow">GROUP BLOCK SUMMARY</span><p>Allotment details, night-by-night pickup, and reservations linked to this block.</p></div><span class="gbd-status gbd-status-{{ $block->status }}">{{ strtoupper(str_replace('_', ' ', $block->status)) }}</span></div>
@if($block->isHolding() && $block->cutoff_date->lte(bdate()->copy()->addDays(3)))
  <div class="gbd-cutoff-alert" role="status"><x-icon name="alert"/><span><strong>{{ $cutoffDays < 0 ? 'Cut-off date has passed.' : ($cutoffDays === 0 ? 'Room release is due today.' : 'Room release is due in '.$cutoffDays.' days.') }}</strong> Unpicked rooms are released at night audit on the cut-off date.</span></div>
@endif
<div class="stats gbd-stats">
  <x-stat label="Status" :value="ucfirst($block->status)" icon="layers" tone="{{ $block->status === 'definite' ? 'green' : ($block->isHolding() ? 'amber' : 'slate') }}"/>
  <x-stat label="Stay" :value="$block->arrival_date->format('d M').' → '.$block->departure_date->format('d M')" icon="calendar" tone="blue"/>
  <x-stat label="Cut-off" :value="$block->cutoff_date->format('d M Y')" icon="clock" tone="{{ $block->isHolding() && $block->cutoff_date->lte(bdate()->copy()->addDays(3)) ? 'red' : 'slate' }}"/>
  <x-stat label="Pickup" :value="$pct.'%'" icon="users" tone="purple"/>
</div>
<div class="grid g-2 gbd-main-grid">
  <div class="card gbd-card"><div class="card-head gbd-card-head"><div><h3>Pickup by night</h3><p class="tiny muted">Picked rooms compared with the held allotment</p></div><span class="gbd-legend"><i class="gbd-legend-dot is-picked"></i> Picked / held</span></div><div class="card-body table-wrap gbd-table-wrap">
    <table class="table gbd-pickup-table" aria-label="Room pickup by type and night"><thead><tr><th scope="col">Room type</th>@foreach(array_keys(reset($grid) ?: []) as $d)<th scope="col" class="center nowrap">{{ \Carbon\Carbon::parse($d)->format('D d M') }}</th>@endforeach</tr></thead><tbody>
    @foreach($block->lines as $l)
      <tr><th scope="row" class="bold">{{ $l->roomType->code }}</th>
      @foreach($grid[$l->room_type_id] ?? [] as $d => $c)
        <td class="center"><span class="badge {{ $c['picked'] >= $c['held'] ? 'badge-green' : ($c['picked'] ? 'badge-amber' : 'badge-slate') }}" aria-label="{{ $l->roomType->name }}, {{ \Carbon\Carbon::parse($d)->format('l j F Y') }}: {{ $c['picked'] }} picked of {{ $c['held'] }} held">{{ $c['picked'] }}/{{ $c['held'] }}</span></td>
      @endforeach</tr>
    @endforeach
    </tbody></table>
    <div class="gbd-details-grid" aria-label="Group block information">
      <div class="gbd-detail-item"><span>Company</span><strong>{{ $block->company?->name ?? '—' }}</strong></div>
      <div class="gbd-detail-item"><span>Business source</span><strong>{{ $block->source?->name ?? '—' }}</strong></div>
      <div class="gbd-detail-item"><span>Billing instruction</span><strong>{{ $block->billing_instruction === 'company' ? 'Bill to company' : 'Guest pays' }}</strong></div>
      <div class="gbd-detail-item"><span>Rate</span><strong>{{ $block->rate !== null ? money($block->rate).' / night' : 'Rate plan' }}</strong></div>
      <div class="gbd-detail-item gbd-detail-contact"><span>Group contact</span><strong>{{ $block->contact_name ?? '—' }}<small>{{ $block->contact_phone ?? '' }}{{ $block->contact_email ? ' · '.$block->contact_email : '' }}</small></strong></div>
    </div>
    @if($block->notes)<p class="gbd-notes"><strong>Notes</strong><br>{{ $block->notes }}</p>@endif
    @if($block->released_at)<p class="gbd-release-note">{{ ucfirst($block->status) }} on {{ $block->released_at->format('d M Y, H:i') }}</p>@endif
  </div></div>
  <div class="card gbd-card"><div class="card-head gbd-card-head"><div><h3>Rooming list pickup</h3><p class="tiny muted">Create confirmed reservations from the organiser’s list</p></div></div><div class="card-body">
    @if($block->isHolding() && can_do('reservations.create'))
    <form method="post" action="{{ route('blocks.pickup', $block) }}" class="form-grid gbd-pickup-form">@csrf
      <x-field name="names" label="Guest names (one per line)" type="textarea" rows="6" required class="span-all" placeholder="One guest per line, e.g. Kwame Mensah" hint="Paste the rooming list straight from the organiser's e-mail or Excel"/>
      <x-field name="room_type_id" label="Room type" type="select" :options="$block->lines->mapWithKeys(fn($l) => [$l->room_type_id => $l->roomType->name])" :placeholder="false" required/>
      <x-field name="adults" label="Adults / room" type="number" min="1" max="6" value="2" required/>
      <x-field name="arrival_date" label="Arrival" type="date" :value="$block->arrival_date->toDateString()" required/>
      <x-field name="departure_date" label="Departure" type="date" :value="$block->departure_date->toDateString()" required/>
      <div class="span-all gbd-submit-row"><button type="submit" class="btn btn-primary"><x-icon name="users" size="sm"/> Create reservations</button><span class="tiny muted">One confirmed reservation per name, linked to this block{{ $block->company ? ' and '.$block->company->name : '' }}.</span></div>
    </form>
    @elseif($block->isHolding())
      <div class="gbd-closed-state" role="status"><x-icon name="lock"/><p>You can view the rooming list and pickup totals, but your account cannot create reservations for this block.</p></div>
    @else
      <div class="gbd-closed-state" role="status"><p>This block is {{ $block->status }}. Its rooms are no longer held; add any further guests as normal reservations.</p></div>
    @endif
  </div></div>
</div>
<div class="card flush gbd-card gbd-reservations-card"><div class="card-head gbd-card-head gbd-reservations-head"><div><h3>Picked-up reservations</h3><p class="tiny muted">Reservations created from this group block</p></div><span class="gbd-count">{{ $block->reservations->count() }}</span></div><div class="card-body table-wrap gbd-table-wrap">
  <table class="table gbd-reservations-table" aria-label="Reservations picked up from this group block"><thead><tr><th scope="col">Res. No</th><th scope="col">Guest</th><th scope="col">Arrival</th><th scope="col">Departure</th><th scope="col">Room</th><th scope="col">Rate</th><th scope="col">Status</th></tr></thead><tbody>
  @forelse($block->reservations->sortBy('arrival_date') as $r)
    @foreach($r->rooms as $s)
    <tr><td><a href="{{ route('reservations.show', $r) }}" class="bold">{{ $r->reservation_no }}</a></td><td>{{ $r->guest->full_name }}</td>
      <td>{{ $s->arrival_date->format($s->arrival_date->year !== $s->departure_date->year ? 'd M Y' : 'd M') }}</td><td>{{ $s->departure_date->format($s->arrival_date->year !== $s->departure_date->year ? 'd M Y' : 'd M') }}</td>
      <td>{{ $s->room?->room_no ?? $s->roomType?->code }}</td><td>{{ money($s->rate) }}</td><td>{!! status_badge($s->status) !!}</td></tr>
    @endforeach
  @empty<tr><td colspan="7" class="empty">No guests picked up yet. Use the rooming list form above to add the first guests.</td></tr>@endforelse
  </tbody></table>
</div></div>
@endsection
