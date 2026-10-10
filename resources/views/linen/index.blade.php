@extends('layouts.app')
@section('title', 'Linen Stock & Par')
@section('crumbs') Laundry & Linen / Linen stock @endsection
@section('actions')
<a class="btn" href="{{ route('linen.batches') }}"><x-icon name="swap"/> Laundry batches</a>
@if(can_do('laundry.linen'))
<button class="btn" data-modal="countModal"><x-icon name="edit"/> Record a count</button>
<button class="btn btn-primary" data-modal="moveModal"><x-icon name="plus"/> Linen movement</button>
@endif
@endsection
@section('content')
<div class="stats wide">
  <x-stat label="Clean in linen room" :value="number_format($stats['store'])" icon="box" tone="green"/>
  <x-stat label="On floors / in use" :value="number_format($stats['floor'])" icon="bed" tone="blue"/>
  <x-stat label="Soiled – to send" :value="number_format($stats['soiled'])" icon="alert" tone="amber" :href="route('linen.batches')"/>
  <x-stat label="At the laundry" :value="number_format($stats['laundry'])" icon="swap" tone="purple" :href="route('linen.batches', ['status' => 'open'])"/>
  <x-stat label="Types below par" :value="$stats['short']" icon="layers" :tone="$stats['short'] ? 'red' : 'green'" :sub="$levels.' par × '.$rooms.' rooms'"/>
  <x-stat label="Written off this month" :value="money($stats['loss'])" icon="trash" tone="slate" :sub="$stats['loss_n'].' pcs discarded / lost'"/>
</div>
<div class="card flush"><div class="card-head"><h3>Stock by location vs. par</h3><span class="small muted">Par = rooms × pieces per room × par levels (or a fixed par for non-room linen).</span></div>
<div class="card-body table-wrap"><table class="table" id="parTable">
<thead><tr><th>Code</th><th>Linen</th><th class="num">Per room</th><th class="num">Clean</th><th class="num">Floors</th><th class="num">Soiled</th><th class="num">At laundry</th><th class="num">Total</th><th class="num">Par</th><th>Cover</th><th class="num">Short</th><th></th></tr></thead><tbody>
@forelse($types as $t)
<tr><td class="bold">{{ $t->code }}</td><td>{{ $t->name }}<div class="tiny muted">cost {{ money($t->unit_cost) }} · wash {{ money($t->wash_rate) }}/pc</div></td>
<td class="num">{{ (float) $t->per_room ? rtrim(rtrim(number_format($t->per_room, 2), '0'), '.') : '—' }}</td>
<td class="num">{{ $t->qty_store }}</td><td class="num">{{ $t->qty_floor }}</td><td class="num {{ $t->qty_soiled ? 'text-amber' : '' }}">{{ $t->qty_soiled }}</td><td class="num">{{ $t->qty_laundry }}</td>
<td class="num bold">{{ $t->total_qty }}</td><td class="num">{{ $t->par_qty ?: '—' }}@if($t->par_override !== null)<div class="tiny muted">fixed</div>@endif</td>
<td>@if($t->cover !== null)<div class="flex items-center gap-1"><div class="cover-bar {{ $t->cover < 75 ? 'low' : ($t->cover < 100 ? 'mid' : '') }}"><span style="width:{{ min(100, $t->cover) }}%"></span></div><span class="tiny">{{ $t->cover }}%</span></div>@else<span class="muted">—</span>@endif</td>
<td class="num {{ $t->short ? 'text-red bold' : 'muted' }}">{{ $t->short ?: '—' }}</td>
<td class="right nowrap">@if(can_do('laundry.manage'))<button class="btn btn-sm btn-ghost" title="Edit" data-modal="typeModal" data-fid="{{ $t->id }}" data-fcode="{{ $t->code }}" data-fname="{{ $t->name }}" data-fper_room="{{ (float) $t->per_room }}" data-fpar_override="{{ $t->par_override }}" data-funit_cost="{{ $t->unit_cost }}" data-fwash_rate="{{ $t->wash_rate }}"><x-icon name="edit" size="sm"/></button>@endif</td></tr>
@empty<tr><td colspan="12"><x-empty text="No linen types set up yet." icon="layers"/></td></tr>@endforelse
</tbody>
@if($types->count())<tfoot><tr><td colspan="3" class="right">Total</td><td class="num">{{ $stats['store'] }}</td><td class="num">{{ $stats['floor'] }}</td><td class="num">{{ $stats['soiled'] }}</td><td class="num">{{ $stats['laundry'] }}</td><td class="num bold">{{ $types->sum('total_qty') }}</td><td class="num">{{ $types->sum('par_qty') }}</td><td colspan="3" class="small muted">Stock value {{ money($types->sum(fn ($t) => $t->total_qty * (float) $t->unit_cost)) }}</td></tr></tfoot>@endif
</table></div></div>
<div class="grid g-2-1">
<div class="card flush"><div class="card-head"><h3>Recent movements</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="moveTable">
<thead><tr><th>Date</th><th>Linen</th><th>Movement</th><th class="num">Qty</th><th>From → to</th><th class="num">Value</th><th>By</th></tr></thead><tbody>
@forelse($moves as $m)<tr><td class="small">{{ $m->date->format('d M') }}</td><td>{{ $m->linen?->code }}</td>
<td>{{ \App\Models\LinenMovement::TYPES[$m->type] ?? $m->type }}@if($m->batch) <a class="tiny" href="{{ route('linen.batch', $m->batch) }}">{{ $m->batch->batch_no }}</a>@endif @if($m->reason)<div class="tiny muted">{{ $m->reason }}</div>@endif</td>
<td class="num {{ $m->qty < 0 ? 'text-red' : '' }}">{{ $m->qty }}</td><td class="small">{{ $m->from_loc ?: '—' }} → {{ $m->to_loc ?: '—' }}</td><td class="num small">{{ (float) $m->value ? money($m->value, false) : '' }}</td><td class="small muted">{{ $m->user?->name }}</td></tr>
@empty<tr><td colspan="7" class="muted center">No movements yet.</td></tr>@endforelse
</tbody></table></div></div>
<div>
  @if(can_do('laundry.manage'))
  <div class="card"><div class="card-head"><h3>Par levels</h3></div><div class="card-body">
    <form method="post" action="{{ route('linen.levels.save') }}" class="flex gap-1 items-end">@csrf<div class="field m-0" style="flex:1"><label>Sets per room</label><input type="number" name="linen_par_levels" class="input" min="1" max="10" step="0.5" value="{{ $levels }}"></div><button class="btn btn-primary">Save</button></form>
    <p class="small muted mb-0">Industry practice is 3–5 par: one in the room, one in the laundry, one clean in the linen room, plus spare.</p>
    <button class="btn btn-sm mt-1" data-modal="typeModal" data-fid="" data-fcode="" data-fname="" data-fper_room="0" data-fpar_override="" data-funit_cost="" data-fwash_rate=""><x-icon name="plus"/> Add linen type</button>
  </div></div>
  @endif
  <div class="card"><div class="card-body small muted"><b>Linen cycle:</b> new stock → <i>linen room</i> → issued to <i>floors</i> → collected <i>soiled</i> → sent in a <i>laundry batch</i> → returned clean to the linen room. Damaged pieces are discarded at their cost; pieces the laundry never returns are written off as lost.</div></div>
</div>
</div>

<div class="modal" id="moveModal"><div class="modal-box"><form method="post" action="{{ route('linen.move') }}">@csrf
  <div class="modal-head"><h3>Linen movement</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <div class="field span-2"><label>Linen</label><select name="linen_type_id" class="input" required>@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->code }} – {{ $t->name }} (clean {{ $t->qty_store }}, floor {{ $t->qty_floor }})</option>@endforeach</select></div>
    <div class="field"><label>Movement</label><select name="type" class="input" id="mvType" onchange="mvToggle()">@foreach(\App\Services\LinenService::MANUAL as $k)<option value="{{ $k }}">{{ \App\Models\LinenMovement::TYPES[$k] }}</option>@endforeach</select></div>
    <div class="field"><label>Quantity</label><input type="number" name="qty" min="1" class="input" required></div>
    <div class="field mv-from"><label>From</label><select name="from" class="input">@foreach(\App\Models\LinenType::LOCATIONS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field mv-val"><label>Value</label><input type="number" name="value" min="0" step="0.01" class="input" placeholder="default: qty × unit cost"></div>
    <div class="field span-all"><label>Reason / reference</label><input name="reason" class="input" maxlength="190" placeholder="e.g. PO-00012, torn, stained beyond repair"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Record</button></div>
</form></div></div>
<div class="modal" id="countModal"><div class="modal-box"><form method="post" action="{{ route('linen.count') }}">@csrf
  <div class="modal-head"><h3>Record a physical count</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <div class="field span-2"><label>Linen</label><select name="linen_type_id" class="input" required>@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->code }} – {{ $t->name }}</option>@endforeach</select></div>
    <div class="field"><label>Location</label><select name="loc" class="input">@foreach(\App\Models\LinenType::LOCATIONS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Counted</label><input type="number" name="counted" min="0" class="input" required></div>
    <div class="field span-all"><label>Reason</label><input name="reason" class="input" required maxlength="190" value="Monthly linen count"></div>
    <p class="small muted span-all m-0">The difference to the system figure is recorded as a count adjustment.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save count</button></div>
</form></div></div>
<div class="modal" id="typeModal"><div class="modal-box"><form method="post" action="{{ route('linen.types.save') }}">@csrf<input type="hidden" name="id">
  <div class="modal-head"><h3>Linen type</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <div class="field"><label>Code</label><input name="code" class="input" required maxlength="12"></div>
    <div class="field span-2"><label>Name</label><input name="name" class="input" required maxlength="80"></div>
    <div class="field"><label>Pieces per room</label><input type="number" name="per_room" min="0" step="0.5" class="input"></div>
    <div class="field"><label>Fixed par</label><input type="number" name="par_override" min="0" class="input" placeholder="auto"></div>
    <div class="field"><label>Unit cost</label><input type="number" name="unit_cost" min="0" step="0.01" class="input"></div>
    <div class="field"><label>Laundry rate / pc</label><input type="number" name="wash_rate" min="0" step="0.01" class="input"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
<script>
function mvToggle() { const t = document.getElementById('mvType').value; const w = ['discard', 'lost'].includes(t);
  document.querySelector('.mv-from').style.display = w ? '' : 'none'; document.querySelector('.mv-val').style.display = (w || t === 'purchase') ? '' : 'none'; }
mvToggle();
</script>
@endsection
