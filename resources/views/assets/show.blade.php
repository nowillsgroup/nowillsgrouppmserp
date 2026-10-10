@extends('layouts.app')
@section('title', $a->asset_no.' · '.$a->name)
@section('crumbs') <a href="{{ route('assets.index') }}">Asset register</a> / {{ $a->asset_no }} @endsection
@section('actions')
<span class="badge badge-{{ \App\Models\Asset::TONES[$a->status] }}" id="assetStatus">{{ \App\Models\Asset::STATUSES[$a->status] }}</span>
<a class="btn" href="{{ route('assets.tags', ['ids' => [$a->id]]) }}" target="_blank"><x-icon name="qr"/> Tag</a>
@if(! $a->isDisposed())
  @if(can_do('maintenance.manage'))<button class="btn" data-modal="ticketModal" id="logTicket"><x-icon name="tool"/> Log ticket</button>@endif
  @if(can_do('assets.manage'))
    <a class="btn" href="{{ route('assets.edit', $a) }}"><x-icon name="edit"/> Edit</a>
    <form method="post" action="{{ route('assets.status', $a) }}" class="inline">@csrf<input type="hidden" name="status" value="{{ $a->status === 'active' ? 'in_repair' : 'active' }}"><button class="btn" id="toggleRepair">{{ $a->status === 'active' ? 'Mark under repair' : 'Back in use' }}</button></form>
    <button class="btn btn-danger" data-modal="disposeModal" id="disposeBtn"><x-icon name="trash"/> Dispose</button>
  @endif
@endif
@endsection
@section('content')
@php
  $acc = $a->isDisposed() ? round((float) $a->cost - (float) $a->disposal_nbv, 2) : $a->accumulated();
  $nbv = $a->isDisposed() ? (float) $a->disposal_nbv : $a->nbv();
  $c = max(0.01, (float) $a->cost); $res = min((float) $a->residual_value, $nbv);
  $w = $a->warrantyState();
@endphp
<div class="grid g-2-1">
<div>
  <div class="card"><div class="card-body">
    <div class="asset-hero">
      <div class="qr" title="Scan to open this asset">{!! $qr !!}</div>
      <div style="flex:1">
        <div class="tiny muted">{{ $a->asset_no }} · {{ strtoupper($a->category?->name) }}</div>
        <h2 class="m-0">{{ $a->name }}</h2>
        <div class="small muted">{{ trim(($a->make ?? '').' '.($a->model ?? '')) ?: '—' }}{{ $a->serial_no ? ' · S/N '.$a->serial_no : '' }}</div>
        <div class="flex gap-2 mt-2 wrap" id="bvFigures">
          <div><div class="tiny muted">COST</div><div class="bold" style="font-size:1.15rem">{{ money($a->cost) }}</div></div>
          <div><div class="tiny muted">ACCUMULATED DEP.</div><div class="bold" style="font-size:1.15rem">{{ money($acc) }}</div></div>
          <div><div class="tiny muted">{{ $a->isDisposed() ? 'BOOK VALUE AT DISPOSAL' : 'NET BOOK VALUE' }}</div><div class="bold text-green" style="font-size:1.15rem" id="assetNbv">{{ money($nbv) }}</div></div>
        </div>
        <div class="bv-bar mt-1" title="Written off / book value / residual"><span style="width:{{ round($acc * 100 / $c, 1) }}%"></span><span class="bv-nbv" style="width:{{ round(max(0, $nbv - $res) * 100 / $c, 1) }}%"></span><span class="bv-res" style="width:{{ round($res * 100 / $c, 1) }}%"></span></div>
        <div class="bv-legend"><span><i style="background:#fde68a"></i>Written off {{ round($acc * 100 / $c) }} %</span><span><i style="background:#16a34a"></i>Book value</span><span><i style="background:#94a3b8"></i>Residual {{ money($a->residual_value) }}</span></div>
      </div>
    </div>
    <div class="kv-grid mt-2">
      <div><span>Location</span><b>{{ $a->placeLabel() ?: '—' }}</b>@if($a->department)<span class="tiny muted">{{ $a->department }}</span>@endif</div>
      <div><span>Custodian</span><b>{{ $a->custodian?->name ?? '—' }}</b></div>
      <div><span>Acquired</span><b>{{ $a->purchase_date->format('d M Y') }}</b><span class="tiny muted">{{ \App\Models\Asset::VIA[$a->acquired_via] }}</span></div>
      <div><span>Source</span><b>@if($a->bill)<a href="{{ route('ap.bills.show', $a->bill) }}">{{ $a->bill->doc_no }}</a>@else{{ $a->reference ?: '—' }}@endif</b><span class="tiny muted">{{ $a->supplier?->name }}{{ $a->paymentMode ? ' · paid by '.$a->paymentMode->name : '' }}</span></div>
      <div><span>Depreciation</span><b>{{ $a->method === 'wdv' ? 'Reducing balance '.rtrim(rtrim(number_format((float) $a->wdv_rate, 2), '0'), '.').' % p.a.' : 'Straight line · '.$a->life_months.' months' }}</b><span class="tiny muted">from {{ $a->dep_start->format('d M Y') }}{{ $a->dep_through ? ' · done to '.$a->dep_through->format('M Y') : ' · not yet depreciated' }}</span></div>
      @if((float) $a->opening_acc_dep > 0)<div><span>Brought in</span><b>{{ money($a->opening_acc_dep) }}</b><span class="tiny muted">accumulated before go-live</span></div>@endif
      <div><span>Warranty</span><b class="{{ $w === 'expired' ? 'muted' : ($w === 'expiring' ? 'text-red' : '') }}">{{ $a->warranty_until?->format('d M Y') ?? '—' }}</b>@if($w)<span class="badge badge-{{ ['valid' => 'green', 'expiring' => 'red', 'expired' => 'slate'][$w] }}">{{ ['valid' => 'In warranty', 'expiring' => 'Ending soon', 'expired' => 'Expired'][$w] }}</span>@endif</div>
      <div><span>AMC</span><b>{{ $a->amc_vendor ?: '—' }}</b>@if($a->amc_until)<span class="tiny {{ $a->amc_until->lt(bdate()) ? 'text-red' : 'muted' }}">until {{ $a->amc_until->format('d M Y') }}</span>@endif</div>
      <div><span>Repairs to date</span><b>{{ money($repairCost) }}</b><span class="tiny muted">{{ $tickets->count() }} ticket(s)</span></div>
      @if($a->isDisposed())<div class="span-all"><span>Disposed</span><b class="text-red">{{ \App\Models\Asset::DISPOSALS[$a->disposal_type] }} on {{ $a->disposed_date->format('d M Y') }}{{ (float) $a->disposal_proceeds ? ' for '.money($a->disposal_proceeds) : '' }}</b>
        @php $g = round((float) $a->disposal_proceeds - (float) $a->disposal_nbv, 2); @endphp<span class="tiny muted">{{ $g >= 0 ? 'Gain' : 'Loss' }} on disposal {{ money(abs($g)) }}{{ $a->disposal_note ? ' · '.$a->disposal_note : '' }}</span></div>@endif
      @if($a->notes)<div class="span-all"><span>Notes</span><b>{{ $a->notes }}</b></div>@endif
    </div>
  </div></div>

  <div class="card flush"><div class="card-head"><h3>Depreciation history</h3><span class="small muted">{{ $lines->where('run.status', 'posted')->count() }} month(s) posted</span></div><div class="card-body table-wrap"><table class="table table-compact" id="depHistory">
    <thead><tr><th>Month</th><th>Run</th><th class="num">Opening book value</th><th class="num">Depreciation</th><th class="num">Closing book value</th><th></th></tr></thead><tbody>
    @forelse($lines as $l)
    <tr class="{{ $l->run->status === 'reversed' ? 'muted' : '' }}"><td>{{ $l->run->period_end->format('M Y') }}</td><td><a href="{{ route('assets.depreciation.show', $l->run) }}">{{ $l->run->run_no }}</a></td><td class="num">{{ money($l->opening_nbv, false) }}</td><td class="num">{{ money($l->amount, false) }}@if($l->days && $l->days < $l->run->period_end->daysInMonth)<div class="tiny muted">{{ $l->days }} days</div>@endif</td><td class="num">{{ money((float) $l->opening_nbv - (float) $l->amount, false) }}</td><td>@if($l->run->status === 'reversed')<span class="badge badge-slate">Reversed</span>@endif</td></tr>
    @empty<tr><td colspan="6" class="muted center">No depreciation posted yet{{ (float) $a->opening_acc_dep ? ' – '.money($a->opening_acc_dep).' was brought in at go-live' : '' }}.</td></tr>@endforelse
    </tbody></table></div></div>

  <div class="card flush"><div class="card-head"><h3>Maintenance history</h3>@if(can_do('maintenance.manage'))<a class="small" href="{{ route('maintenance.index', ['status' => 'all', 'asset' => $a->id]) }}">All tickets →</a>@endif</div><div class="card-body table-wrap"><table class="table table-compact" id="assetTickets">
    <thead><tr><th>Ticket</th><th>Issue</th><th>Status</th><th>Logged</th><th class="num">Cost</th></tr></thead><tbody>
    @forelse($tickets as $t)
    <tr><td class="bold">{{ $t->ticket_no }}@if($t->isPm()) <span class="badge badge-blue">PM</span>@endif</td><td>{{ $t->title }}@if($t->resolution)<div class="tiny text-green">✔ {{ \Illuminate\Support\Str::limit($t->resolution, 90) }}</div>@endif</td><td>{!! status_badge($t->status) !!}</td><td class="small">{{ $t->created_at->format('d M Y') }}</td><td class="num">{{ (float) $t->cost ? money($t->cost, false) : '—' }}</td></tr>
    @empty<tr><td colspan="5" class="muted center">No tickets for this asset.</td></tr>@endforelse
    </tbody></table></div></div>
</div>
<div>
  @if($projection)
  <div class="card"><div class="card-head"><h3>Next 12 months</h3><span class="small muted">projected</span></div><div class="card-body">
    @php $max = max(0.01, collect($projection)->max('amount')); @endphp
    <div class="spark" id="projection">@foreach($projection as $p)<span style="height:{{ round($p['amount'] * 100 / $max) }}%" title="{{ $p['period']->format('M Y') }}: {{ money($p['amount']) }}"></span>@endforeach</div>
    <div class="flex justify-between tiny muted mt-1"><span>{{ $projection[0]['period']->format('M Y') }}</span><span>{{ end($projection)['period']->format('M Y') }}</span></div>
    <table class="table table-compact mt-1"><tbody>
      <tr><td>Depreciation, next 12 months</td><td class="num bold">{{ money(collect($projection)->sum('amount')) }}</td></tr>
      <tr><td>Book value after</td><td class="num">{{ money(end($projection)['nbv']) }}</td></tr>
      @if($a->method === 'slm')@php $left = (float) $a->cost - (float) $a->residual_value > 0 ? ceil(max(0, $a->nbv() - (float) $a->residual_value) / (((float) $a->cost - (float) $a->residual_value) / max(1, $a->life_months))) : 0; @endphp
      <tr><td>Remaining life</td><td class="num">{{ $left }} months</td></tr>@endif
    </tbody></table>
  </div></div>
  @endif

  <div class="card"><div class="card-head"><h3>Preventive maintenance</h3>@if(can_do('maintenance.manage') && ! $a->isDisposed())<button class="btn btn-sm" data-modal="pmModal" id="addPm"><x-icon name="plus"/> Schedule</button>@endif</div><div class="card-body" id="assetPm">
    @forelse($schedules as $s)
    @php $st = $s->state(); @endphp
    <div class="flex justify-between items-center" style="padding:.4rem 0;border-bottom:1px solid #f1f5f9">
      <div><b class="small">{{ $s->title }}</b><div class="tiny muted">{{ $s->frequency() }}{{ $s->last_done ? ' · last done '.$s->last_done->format('d M Y') : '' }}</div></div>
      <div class="right"><span class="badge badge-{{ ['overdue' => 'red', 'due' => 'amber', 'scheduled' => 'green', 'paused' => 'slate'][$st] }}">{{ $st === 'paused' ? 'Paused' : 'Due '.$s->next_due->format('d M') }}</span></div>
    </div>
    @empty<p class="small muted m-0">No preventive-maintenance schedule. Add one to have tickets raised automatically (e.g. AC filter cleaning every month).</p>@endforelse
  </div></div>

  @if($journals->isNotEmpty())
  <div class="card"><div class="card-head"><h3>Ledger postings</h3></div><div class="card-body small">
    @foreach($journals as $j)<div class="flex justify-between"><a href="{{ route('gl.journals.show', $j) }}">{{ $j->journal_no }}</a><span class="muted">{{ $j->date->format('d M Y') }} · {{ \App\Models\GlJournal::SOURCES[$j->source_type] ?? $j->source_type }}</span><span>{{ money($j->total, false) }}</span></div>@endforeach
  </div></div>
  @endif
  <div class="card"><div class="card-body small muted">
    <b>How it's accounted:</b> {{ $a->acquired_via === 'direct' ? 'the purchase posted Dr Fixed assets / Cr '.($a->paymentMode?->name ?? 'bank').'.' : ($a->acquired_via === 'bill' ? 'the supplier bill posted Dr Fixed assets / Cr Accounts payable.' : 'this asset is part of the opening balances.') }}
    Each monthly run posts Dr Depreciation (6500) / Cr Accumulated depreciation (1590). A disposal removes cost and accumulated depreciation and books the gain or loss to 4980.
    @if(can_do('assets.manage') && ! $a->isDisposed() && $lines->isEmpty())
      <form method="post" action="{{ route('assets.destroy', $a) }}" class="mt-1" onsubmit="return confirm('Delete {{ $a->asset_no }}? Only for assets entered in error.')">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost text-red"><x-icon name="trash"/> Delete (entered in error)</button></form>
    @endif
  </div></div>
</div>
</div>

@if(can_do('assets.manage') && ! $a->isDisposed())
<div class="modal" id="disposeModal"><div class="modal-box"><form method="post" action="{{ route('assets.dispose', $a) }}">@csrf
  <div class="modal-head"><h3>Dispose of {{ $a->asset_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="disposal_type" label="What happened?" type="select" :options="\App\Models\Asset::DISPOSALS" :placeholder="false" required/>
    <x-field name="disposed_date" label="Date" type="date" :value="bdate()->toDateString()" required/>
    <x-field name="disposal_proceeds" label="Sale proceeds" type="number" step="0.01" min="0" hint="Only when sold"/>
    <x-field name="disposal_mode_id" label="Received into" type="select" :options="$modes"/>
    <x-field name="disposal_note" label="Note / buyer" class="span-all"/>
    <div class="span-all small muted">Book value today <b>{{ money($a->nbv()) }}</b>. The difference between proceeds and book value is posted as a gain or loss on disposal. Depreciation stops; open PM schedules are paused.</div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger" id="confirmDispose">Dispose</button></div>
</form></div></div>
@endif
@if(can_do('maintenance.manage') && ! $a->isDisposed())
<div class="modal" id="ticketModal"><div class="modal-box"><form method="post" action="{{ route('maintenance.store') }}">@csrf
  <input type="hidden" name="asset_id" value="{{ $a->id }}"><input type="hidden" name="room_id" value="{{ $a->room_id }}"><input type="hidden" name="location" value="{{ $a->location }}">
  <div class="modal-head"><h3>Maintenance ticket – {{ $a->asset_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="title" label="Issue" required class="span-all"/>
    <x-field name="category" label="Category" type="select" :options="\App\Http\Controllers\PmScheduleController::CATEGORIES" :placeholder="false"/>
    <x-field name="priority" label="Priority" type="select" :options="['normal' => 'Normal', 'low' => 'Low', 'high' => 'High', 'urgent' => 'Urgent']" :placeholder="false"/>
    <x-field name="description" label="Details" type="textarea" class="span-all"/>
    <label class="check span-all"><input type="checkbox" name="asset_down" value="1" checked> Asset is out of service – mark it Under repair until resolved</label>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Log ticket</button></div>
</form></div></div>
<div class="modal" id="pmModal"><div class="modal-box"><form method="post" action="{{ route('pm.store') }}">@csrf
  <input type="hidden" name="asset_id" value="{{ $a->id }}">
  <div class="modal-head"><h3>Preventive maintenance – {{ $a->asset_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">@include('assets._pm_fields', ['s' => null, 'withTarget' => false])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save schedule</button></div>
</form></div></div>
@endif
@endsection
