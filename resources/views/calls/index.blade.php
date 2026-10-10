@extends('layouts.app')
@section('title', 'Call Accounting')
@section('crumbs') Interfaces / Call Accounting @endsection
@section('actions')
  @if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'call_revenue') }}"><x-icon name="chart"/> Call revenue</a>@endif
  <button class="btn" data-modal="rateModal" id="rateBtn"><x-icon name="money"/> Rate calculator</button>
  @if(can_do('calls.manage'))
    <button class="btn" data-modal="manualModal" id="manualBtn"><x-icon name="plus"/> Manual call</button>
    <button class="btn btn-primary" data-modal="importModal" id="importBtn"><x-icon name="download"/> Import SMDR</button>
  @endif
@endsection
@section('content')
@php $c = $counts; $classes = \App\Models\CallTariff::CLASSES + ['internal' => 'Internal']; @endphp
@if(session('import_errors'))<div class="alert alert-warn" id="importErrors"><x-icon name="alert"/><div><b>Some lines were not imported:</b><ul class="small" style="margin:.3rem 0 0 1rem">@foreach(session('import_errors') as $e)<li>{{ $e }}</li>@endforeach</ul></div></div>@endif
@if($cfg['pbx_enabled'] !== '1')<div class="alert alert-info"><x-icon name="alert"/><div>The PABX interface is switched off – calls can still be imported by hand, but no commands are sent to the exchange.</div></div>@endif
@if($c['calls_pending'] && $tab !== 'review')<div class="alert alert-warn" id="reviewAlert"><x-icon name="phone"/><div><a href="{{ route('calls.index', ['tab' => 'review']) }}"><b>{{ $c['calls_pending'] }}</b> call(s) could not be posted automatically</a> – post them to a stay or waive them.</div></div>@endif
<div class="stats">
  <x-stat label="Calls" :value="number_format($sum->n)" icon="phone" tone="blue" :sub="dmy($from, 'd M').' – '.dmy($to, 'd M').' · '.number_format(($sum->secs ?? 0) / 60).' min'"/>
  <x-stat label="Billed to guests" :value="money($sum->charged ?? 0)" icon="receipt" tone="green" :sub="'carrier cost '.money($sum->cost ?? 0)"/>
  <x-stat label="Admin extensions" :value="money($sum->admin_cost ?? 0)" icon="building" tone="purple" sub="department phone cost"/>
  <x-stat label="Review queue" :value="$c['calls_pending']" icon="alert" :tone="$c['calls_pending'] ? 'amber' : 'green'" :href="route('calls.index', ['tab' => 'review'])" sub="unposted calls"/>
</div>

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\CallController::TABS as $k => $l)<a href="{{ route('calls.index', ['tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}@if($k === 'review' && $c['calls_pending']) <span class="badge badge-amber">{{ $c['calls_pending'] }}</span>@endif</a>@endforeach
  </div>
  @if($tab === 'log')
  <form method="get" class="flex" style="gap:.4rem;flex-wrap:wrap"><input type="hidden" name="tab" value="log">
    <input class="input" type="date" name="from" value="{{ $from }}" style="max-width:9.5rem"><input class="input" type="date" name="to" value="{{ $to }}" style="max-width:9.5rem">
    <input class="input" name="q" value="{{ request('q') }}" placeholder="Room, ext or number" style="max-width:11rem">
    <select class="input" name="status" style="max-width:8rem"><option value="">All statuses</option>@foreach(\App\Models\CallRecord::STATUSES as $k => $s)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $s[0] }}</option>@endforeach</select>
    <select class="input" name="class" style="max-width:9rem"><option value="">All classes</option>@foreach($classes as $k => $l)<option value="{{ $k }}" @selected(request('class') === $k)>{{ $l }}</option>@endforeach</select>
    <button class="btn btn-sm">Filter</button></form>
  @elseif($tab === 'review' && can_do('calls.manage'))
    <form method="post" action="{{ route('calls.reprocess') }}">@csrf<button class="btn btn-sm" id="reprocessBtn"><x-icon name="refresh"/> Re-run queue</button></form>
  @elseif($tab === 'tariffs' && can_do('calls.manage'))
    <button class="btn btn-sm btn-primary" data-modal="tariffModal" data-title="New tariff" data-action="{{ route('calls.tariffs.store') }}" id="newTariffBtn"><x-icon name="plus"/> Tariff</button>
  @elseif($tab === 'extensions' && can_do('calls.manage'))
    <span class="flex gap-1"><form method="post" action="{{ route('calls.extensions.sync') }}">@csrf<button class="btn btn-sm" id="syncExtBtn"><x-icon name="refresh"/> Create room extensions</button></form>
    <button class="btn btn-sm btn-primary" data-modal="extModal" data-title="New extension" data-action="{{ route('calls.extensions.store') }}" id="newExtBtn"><x-icon name="plus"/> Extension</button></span>
  @endif
  </div>
  <div class="card-body table-wrap">
  @if(in_array($tab, ['log', 'review']))
    <table class="table table-compact" id="callsTable"><thead><tr><th>Time</th><th>Ext / room</th><th>Number</th><th>Class</th><th class="text-right">Duration</th><th class="text-right">Cost</th><th class="text-right">Charge</th><th>Status</th>@if($tab === 'review' && can_do('calls.manage'))<th></th>@endif</tr></thead><tbody>
    @forelse($calls as $k)
      <tr data-call="{{ $k->id }}" data-status="{{ $k->status }}"><td class="nowrap small">{{ $k->started_at->format('d M H:i') }}</td>
        <td class="nowrap">{{ $k->extension }}@if($k->room) <span class="muted small">· {{ $k->room->room_no }}</span>@elseif($k->phoneExtension && $k->phoneExtension->type !== 'room') <span class="muted small">· {{ $k->phoneExtension->department ?? $k->phoneExtension->label }}</span>@endif
          @if($k->stay)<div class="small muted">{{ $k->stay->guest?->full_name }}</div>@endif</td>
        <td class="nowrap mono">{{ $k->dialled }}@if($k->trunk)<div class="small muted">trunk {{ $k->trunk }}</div>@endif</td>
        <td class="small">{{ $classes[$k->call_class] ?? '—' }}@if($k->pulses)<div class="muted">{{ $k->pulses }} pulse(s)</div>@endif</td>
        <td class="text-right mono">{{ $k->durationLabel() }}</td><td class="text-right">{{ $k->cost > 0 ? money($k->cost) : '—' }}</td><td class="text-right"><b>{{ $k->charge > 0 ? money($k->charge) : '—' }}</b></td>
        <td><span class="badge badge-{{ $k->statusTone() }}">{{ $k->statusLabel() }}</span>@if($k->note)<div class="small muted" style="max-width:16rem">{{ $k->note }}</div>@endif
          @if($k->status === 'posted' && $k->stay?->folio)<div class="small"><a href="{{ route('folios.show', $k->stay->folio) }}">folio</a></div>@endif</td>
        @if($tab === 'review' && can_do('calls.manage'))<td class="nowrap">
          @if($k->charge > 0)<button class="btn btn-sm btn-primary" data-modal="postModal" data-action="{{ route('calls.post', $k) }}" data-fstay_id="{{ $k->reservation_room_id && $k->stay?->status === 'checked_in' ? $k->reservation_room_id : '' }}" data-title="Post call to {{ $k->dialled }} ({{ money($k->charge) }})">Post</button>@endif
          <button class="btn btn-sm" data-modal="waiveModal" data-action="{{ route('calls.waive', $k) }}" data-title="Waive call to {{ $k->dialled }}">Waive</button></td>@endif
      </tr>
    @empty<tr><td colspan="9"><x-empty icon="phone" :text="$tab === 'review' ? 'Nothing to review – every call has been posted, costed or waived.' : 'No calls in this period.'"/></td></tr>@endforelse
    </tbody></table>
  @elseif($tab === 'tariffs')
    <table class="table table-compact" id="tariffTable"><thead><tr><th>Tariff</th><th>Prefix</th><th>Class</th><th class="text-right">Pulse</th><th class="text-right">Carrier / pulse</th><th class="text-right">Markup</th><th class="text-right">Guest / min</th><th class="text-right">Min. charge</th><th class="text-right">Free secs</th><th></th></tr></thead><tbody>
    @forelse($tariffs as $t)
      <tr data-tariff="{{ $t->prefix === '' ? 'default' : $t->prefix }}" class="{{ $t->is_active ? '' : 'muted' }}"><td><b>{{ $t->name }}</b>@unless($t->is_active) <span class="badge badge-slate">Inactive</span>@endunless</td>
        <td class="mono">{{ $t->prefix === '' ? '(default)' : $t->prefix }}</td><td>{{ \App\Models\CallTariff::CLASSES[$t->call_class] ?? $t->call_class }}</td>
        <td class="text-right">{{ $t->pulse_secs }} s</td><td class="text-right">{{ money($t->cost_per_pulse) }}</td><td class="text-right">{{ rtrim(rtrim(number_format($t->markup_pct, 2), '0'), '.') }} %</td>
        <td class="text-right"><b>{{ money($t->pricePerMinute()) }}</b></td><td class="text-right">{{ $t->min_charge > 0 ? money($t->min_charge) : '—' }}</td><td class="text-right">{{ $t->free_secs ?: '—' }}</td>
        <td>@if(can_do('calls.manage'))<button class="btn btn-sm" data-modal="tariffModal" data-title="Edit {{ $t->name }}" data-action="{{ route('calls.tariffs.update', $t) }}" data-fname="{{ $t->name }}" data-fprefix="{{ $t->prefix }}" data-fcall_class="{{ $t->call_class }}" data-fpulse_secs="{{ $t->pulse_secs }}" data-fcost_per_pulse="{{ $t->cost_per_pulse }}" data-fmarkup_pct="{{ $t->markup_pct }}" data-fmin_charge="{{ $t->min_charge }}" data-ffree_secs="{{ $t->free_secs }}" data-fis_active="{{ $t->is_active ? 1 : 0 }}"><x-icon name="edit" size="sm"/></button>@endif</td></tr>
    @empty<tr><td colspan="10"><x-empty icon="money" text="No tariffs yet – add at least a default (blank prefix) tariff."/></td></tr>@endforelse
    </tbody></table>
    <div class="small muted" style="padding:.6rem 1rem">The longest matching prefix wins; the blank-prefix tariff rates everything else. Guest charge = pulses × carrier cost × (1 + markup), never below the minimum charge. Numbers in the free list ({{ str_replace(',', ', ', $cfg['pbx_free_numbers']) }}) and calls under {{ $cfg['pbx_min_secs'] }} s are never charged.</div>
  @else
    <table class="table table-compact" id="extTable"><thead><tr><th>Extension</th><th>Type</th><th>Room / department</th><th>Label</th><th class="text-right">Calls</th><th></th></tr></thead><tbody>
    @forelse($extensions as $x)
      <tr data-ext="{{ $x->extension }}" class="{{ $x->is_active ? '' : 'muted' }}"><td class="mono"><b>{{ $x->extension }}</b></td><td><span class="badge badge-{{ ['room' => 'blue', 'admin' => 'purple', 'public' => 'slate'][$x->type] }}">{{ \App\Models\PhoneExtension::TYPES[$x->type] }}</span>@unless($x->is_active) <span class="badge badge-slate">Inactive</span>@endunless</td>
        <td>{{ $x->room ? 'Room '.$x->room->room_no : ($x->department ?? '—') }}</td><td class="small">{{ $x->label }}</td><td class="text-right">{{ $x->calls_count }}</td>
        <td>@if(can_do('calls.manage'))<button class="btn btn-sm" data-modal="extModal" data-title="Edit extension {{ $x->extension }}" data-action="{{ route('calls.extensions.update', $x) }}" data-fextension="{{ $x->extension }}" data-ftype="{{ $x->type }}" data-froom_id="{{ $x->room_id }}" data-fdepartment="{{ $x->department }}" data-flabel="{{ $x->label }}" data-fis_active="{{ $x->is_active ? 1 : 0 }}"><x-icon name="edit" size="sm"/></button>@endif</td></tr>
    @empty<tr><td colspan="6"><x-empty icon="phone" text="No extensions – use “Create room extensions”."/></td></tr>@endforelse
    </tbody></table>
  @endif
  </div>
  @if($calls && $calls->hasPages())<div class="card-foot">{{ $calls->links() }}</div>@endif
</div>

@if(can_do('calls.manage'))
<div class="modal" id="importModal"><div class="modal-box" style="max-width:720px"><form method="post" action="{{ route('calls.import') }}" enctype="multipart/form-data" id="importForm">@csrf
  <div class="modal-head"><h3>Import call records (SMDR)</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small muted">Upload the call-logger export or paste the lines. Column order: <code>{{ $cfg['pbx_format'] }}</code> (change it in the Interface Hub). Duplicates are skipped, so the same file can safely be imported twice. The PBX can also push records automatically.</p>
    <div class="field"><label>File (.csv, .txt, .log)</label><input type="file" name="file" accept=".csv,.txt,.log,.smdr,.dat"></div>
    <div class="field"><label>… or paste</label><textarea name="text" rows="7" class="mono" id="smdrText" placeholder="{{ bdate()->format('Y-m-d') }},14:05:10,101,T1,04422334455,00:03:25"></textarea></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="doImport">Import & rate</button></div>
</form></div></div>

<div class="modal" id="manualModal"><div class="modal-box" style="max-width:600px"><form method="post" action="{{ route('calls.store') }}" id="manualForm">@csrf
  <div class="modal-head"><h3>Record a call manually</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small muted">For operator-connected calls or when the call logger was down.</p><div class="form-grid">
    <x-field name="ext" label="Extension" required placeholder="e.g. 101"/>
    <x-field name="number" label="Number dialled" required/>
    <x-field name="started_at" label="Call started" type="datetime-local" :value="now()->subMinutes(10)->format('Y-m-d\TH:i')" required/>
    <x-field name="duration" label="Duration" required placeholder="m:ss or seconds"/>
    <x-field name="trunk" label="Trunk / line"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveManual">Rate & record</button></div>
</form></div></div>

<div class="modal" id="postModal"><div class="modal-box" style="max-width:520px"><form method="post" action="#" id="postForm">@csrf
  <div class="modal-head"><h3 data-title>Post call</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="stay_id" label="Charge to in-house stay" type="select" :options="$stays" placeholder="— the stay that was in the room —" hint="Pick another room when the guest asks to move the charge, e.g. a family in two rooms"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="confirmPost">Post to folio</button></div>
</form></div></div>

<div class="modal" id="waiveModal"><div class="modal-box" style="max-width:520px"><form method="post" action="#" id="waiveForm">@csrf
  <div class="modal-head"><h3 data-title>Waive call</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required placeholder="e.g. housekeeping call from vacant room / guest dispute"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="confirmWaive">Waive</button></div>
</form></div></div>

<div class="modal" id="tariffModal"><div class="modal-box" style="max-width:640px"><form method="post" action="{{ route('calls.tariffs.store') }}" id="tariffForm" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Tariff</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="name" label="Name" required placeholder="e.g. STD (national)"/>
    <x-field name="prefix" label="Number prefix" placeholder="blank = default" hint="e.g. 0 national, 00 international, 9 mobile"/>
    <x-field name="call_class" label="Class" type="select" :options="\App\Models\CallTariff::CLASSES" value="local" :placeholder="false"/>
    <x-field name="pulse_secs" label="Pulse (seconds)" type="number" min="1" value="60" required/>
    <x-field name="cost_per_pulse" label="Carrier cost per pulse" type="number" step="0.01" min="0" value="1" required/>
    <x-field name="markup_pct" label="Markup %" type="number" step="0.01" min="0" value="300" required/>
    <x-field name="min_charge" label="Minimum charge" type="number" step="0.01" min="0" value="0"/>
    <x-field name="free_secs" label="Free seconds" type="number" min="0" value="0"/>
    <x-field name="is_active" label="Active" type="select" :options="[1 => 'Yes', 0 => 'No']" value="1" :placeholder="false"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveTariff">Save tariff</button></div>
</form></div></div>

<div class="modal" id="extModal"><div class="modal-box" style="max-width:600px"><form method="post" action="{{ route('calls.extensions.store') }}" id="extForm" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Extension</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="extension" label="Extension" required/>
    <x-field name="type" label="Type" type="select" :options="\App\Models\PhoneExtension::TYPES" value="room" :placeholder="false"/>
    <x-field name="room_id" label="Room (guest-room lines)" type="select" :options="$rooms" placeholder="—"/>
    <x-field name="department" label="Department (admin lines)" placeholder="e.g. Accounts"/>
    <x-field name="label" label="Label"/>
    <x-field name="is_active" label="Active" type="select" :options="[1 => 'Yes', 0 => 'No']" value="1" :placeholder="false"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveExt">Save extension</button></div>
</form></div></div>
@endif

<div class="modal" id="rateModal"><div class="modal-box" style="max-width:480px"><form id="rateForm" data-url="{{ route('calls.rate') }}" data-no-lock="1">
  <div class="modal-head"><h3>What will this call cost?</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="number" label="Number" required placeholder="e.g. 00442071234567"/>
    <x-field name="secs" label="Duration (seconds)" type="number" min="0" value="180" required/>
  </div><div id="rateResult" class="alert alert-info" style="display:none;margin-top:.6rem"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="doRate">Calculate</button></div>
</form></div></div>
<script>
document.getElementById('rateForm').addEventListener('submit', async e => {
  e.preventDefault();
  const f = e.target, out = document.getElementById('rateResult');
  const q = new URLSearchParams({ number: f.number.value, secs: f.secs.value });
  const r = await fetch(f.dataset.url + '?' + q, { headers: { Accept: 'application/json' } });
  const d = await r.json();
  out.style.display = 'block';
  if (!r.ok) { out.textContent = 'Check the number and duration.'; return; }
  out.innerHTML = d.error ? d.error : (d.free ? '<b>Free</b> – ' + d.free + ' (' + (d.class || '') + ')'
    : '<b>' + (d.tariff || d.class) + '</b>: ' + d.pulses + ' pulse(s) · carrier cost ' + d.cost.toFixed(2) + ' · <b>guest charge ' + d.charge.toFixed(2) + '</b> + tax');
  out.dataset.charge = d.charge;
});
</script>
@endsection
