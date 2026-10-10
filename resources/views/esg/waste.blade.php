@extends('layouts.app')
@section('title', 'Waste')
@section('crumbs') Sustainability &amp; ESG / Waste @endsection
@php $u = auth()->user(); $canRec = \App\Services\EsgService::canRecord($u); $canMg = \App\Services\EsgService::canManage($u); $W = \App\Models\EsgWasteLog::class; @endphp
@section('actions')
  <a class="btn" href="{{ route('reports.show', ['report' => 'esg_waste', 'from' => $from, 'to' => $to]) }}"><x-icon name="download"/> Waste report</a>
@endsection
@section('content')
<div class="stats" id="esgWasteStats">
  <x-stat label="Waste" :value="number_format($s['total'], 0).' kg'" icon="trash" tone="slate" :sub="$s['count'].' consignments · '.($s['per_room'] !== null ? $s['per_room'].' kg per occupied room' : 'no occupancy')"/>
  <x-stat label="Diverted from landfill" :value="$s['diversion'] !== null ? $s['diversion'].'%' : '—'" icon="refresh" :tone="($s['diversion'] ?? 0) >= 50 ? 'green' : 'amber'" :sub="number_format($s['diverted'], 0).' kg recycled, composted, donated…'"/>
  <x-stat label="Food waste" :value="number_format($s['food'], 0).' kg'" icon="coffee" tone="amber" :sub="$s['rooms'] ? number_format($s['food'] / $s['rooms'], 2).' kg per occupied room' : ''"/>
  <x-stat label="Waste carbon" :value="number_format($s['co2'], 0).' kg'" icon="tree" tone="green" :sub="number_format($s['avoided'], 0).' kg CO₂e avoided vs all-landfill'"/>
  <x-stat label="Cost / income" :value="money($s['cost'])" icon="money" tone="purple" :sub="money($s['income']).' from recyclables & used oil'"/>
</div>
<div class="card"><div class="card-body"><form method="get" class="filters" style="align-items:flex-end">
      <div class="field"><label>From</label><input type="date" name="from" value="{{ $from }}"></div><div class="field"><label>To</label><input type="date" name="to" value="{{ $to }}"></div>
      <div class="field"><label>Stream</label><select name="stream"><option value="">All streams</option>@foreach($W::STREAMS as $k => $l)<option value="{{ $k }}" @selected(request('stream') === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="field"><label>Route</label><select name="disposal"><option value="">All routes</option>@foreach($W::DISPOSALS as $k => $l)<option value="{{ $k }}" @selected(request('disposal') === $k)>{{ $l }}</option>@endforeach</select></div>
      <label class="small"><input type="checkbox" name="cancelled" value="1" @checked(request()->boolean('cancelled'))> include cancelled</label>
      <button class="btn btn-sm">Filter</button></form></div></div>
<div class="g-2-1">
    <div class="card flush"><div class="card-head"><h3>Stream × disposal route (kg)</h3></div><div class="card-body table-wrap">
      @php $short = ['landfill' => 'Landfill', 'recycling' => 'Recycled', 'composting' => 'Composted', 'anaerobic' => 'Biogas', 'incineration' => 'Incinerated', 'donation' => 'Donated', 'rendering' => 'Rendered']; $used = collect($s['matrix'])->flatMap(fn ($r) => array_keys($r))->unique()->sortBy(fn ($d) => array_search($d, array_keys($W::DISPOSALS)))->values(); @endphp
      <table class="table table-compact esg-matrix" id="esgWasteMatrix"><thead><tr><th>Stream</th>@foreach($used as $d)<th class="num">{{ $short[$d] ?? $W::DISPOSALS[$d] }}</th>@endforeach<th class="num">Total</th></tr></thead><tbody>
      @forelse($s['matrix'] as $st => $row)<tr><td class="small nowrap">{{ $W::STREAMS[$st] ?? $st }}</td>@foreach($used as $d)<td class="num small {{ in_array($d, $W::DIVERTED, true) && ($row[$d] ?? 0) ? 'text-green' : '' }}">{{ isset($row[$d]) ? number_format($row[$d], 0) : '' }}</td>@endforeach<td class="num"><b>{{ number_format(array_sum($row), 0) }}</b></td></tr>
      @empty<tr><td class="muted small">No waste recorded in the period.</td></tr>@endforelse
      </tbody></table></div></div>
    <div class="card"><div class="card-head"><h3>12-month trend</h3><span class="tiny muted"><i class="esg-key esg-div"></i>diverted <i class="esg-key esg-land"></i>landfill / incinerated</span></div><div class="card-body">
      @php $mx = max(1, collect($trend)->max(fn ($t) => $t['diverted'] + $t['disposed'])); @endphp
      <div class="bar-chart esg-trend" id="esgWasteTrend" style="height:150px">@foreach($trend as $t)@php $tot = $t['diverted'] + $t['disposed']; @endphp<div class="col">
        <div class="esg-stack" style="height:{{ round($tot / $mx * 100) }}%" title="{{ number_format($t['diverted']) }} kg diverted · {{ number_format($t['disposed']) }} kg disposed">@if($t['disposed'])<i class="esg-land" style="flex:{{ $t['disposed'] }}"></i>@endif @if($t['diverted'])<i class="esg-div" style="flex:{{ $t['diverted'] }}"></i>@endif</div><small>{{ $t['label'] }}</small></div>@endforeach</div>
    </div></div>
</div>
<div class="g-2-1">
  <div>
    <div class="card flush"><div class="card-head"><h3>Consignments</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="esgWasteList"><thead><tr><th>Ref</th><th>Date</th><th>Stream → route</th><th class="num">kg</th><th>Contractor / manifest</th><th class="num">Cost</th><th></th></tr></thead><tbody>
      @forelse($list as $w)<tr class="{{ $w->cancelled_at ? 'muted' : '' }}"><td class="nowrap"><b>{{ $w->number }}</b></td><td class="small nowrap">{{ $w->date->format('d M Y') }}</td>
        <td class="small">{{ $w->streamLabel() }} → <span class="badge badge-{{ $w->isDiverted() ? 'green' : 'slate' }}">{{ $w->disposalLabel() }}</span><div class="tiny muted">{{ $w->department?->name }}{{ $w->notes ? ' · '.$w->notes : '' }}</div></td>
        <td class="num">{{ number_format($w->weight_kg, 1) }}</td><td class="small">{{ $w->supplier?->name ?? $w->contractor ?? '—' }}@if($w->manifest_no)<div class="tiny muted">WTN {{ $w->manifest_no }}</div>@endif</td>
        <td class="num small">{{ (float) $w->cost ? money($w->cost) : '' }}{{ (float) $w->income ? ' +'.money($w->income) : '' }}</td>
        <td class="nowrap">@if($w->cancelled_at)<span class="badge badge-red" title="{{ $w->cancel_reason }}">cancelled</span>@elseif($canMg || ($w->user_id === $u->id && $w->date->toDateString() === bdate()->toDateString()))
          <details class="esg-pop"><summary class="btn btn-sm">Cancel</summary><form method="post" action="{{ route('esg.waste.cancel', $w) }}" onsubmit="return this.reason.value.trim() !== '' || (alert('Give a reason'), false)">@csrf<input name="reason" class="input" placeholder="Reason for cancelling" maxlength="190" required><button class="btn btn-sm btn-danger">Cancel entry</button></form></details>@endif</td></tr>
      @empty<tr><td colspan="7" class="muted">Nothing recorded.</td></tr>@endforelse
    </tbody></table></div></div>
    {{ $list->links() }}
  </div>
  <div>
    @if($canRec)<div class="card" id="new"><div class="card-head"><h3>Log waste</h3></div><div class="card-body"><form method="post" action="{{ route('esg.waste.store') }}" class="form-grid" id="esgWasteForm">@csrf
      <div class="field"><label>Date</label><input type="date" name="date" value="{{ old('date', bdate()->toDateString()) }}" max="{{ bdate()->toDateString() }}" required></div>
      <div class="field"><label>Weight (kg)</label><input type="number" name="weight_kg" step="0.01" min="0.01" value="{{ old('weight_kg') }}" required></div>
      <div class="field"><label>Stream</label><select name="stream" required>@foreach($W::STREAMS as $k => $l)<option value="{{ $k }}" @selected(old('stream') === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="field"><label>Went to</label><select name="disposal" required>@foreach($W::DISPOSALS as $k => $l)<option value="{{ $k }}" @selected(old('disposal') === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="field"><label>Department</label><select name="department_id"><option value="">—</option>@foreach($departments as $d)<option value="{{ $d->id }}" @selected(old('department_id') == $d->id)>{{ $d->name }}</option>@endforeach</select></div>
      <div class="field"><label>Contractor (supplier)</label><select name="supplier_id"><option value="">—</option>@foreach($suppliers as $d)<option value="{{ $d->id }}" @selected(old('supplier_id') == $d->id)>{{ $d->name }}</option>@endforeach</select></div>
      <div class="field"><label>Or contractor name</label><input name="contractor" maxlength="120" value="{{ old('contractor') }}" placeholder="e.g. Zoomlion"></div>
      <div class="field"><label>Waste transfer note / manifest</label><input name="manifest_no" maxlength="60" value="{{ old('manifest_no') }}"></div>
      <div class="field"><label>Cost (GH₵)</label><input type="number" name="cost" step="0.01" min="0" value="{{ old('cost') }}"></div>
      <div class="field"><label>Income (GH₵)</label><input type="number" name="income" step="0.01" min="0" value="{{ old('income') }}"></div>
      <div class="field span-all"><label>Notes</label><input name="notes" maxlength="255" value="{{ old('notes') }}"></div>
      <div class="span-all"><button class="btn btn-primary">Record</button></div>
    </form></div></div>@endif
  </div>
</div>
@endsection
