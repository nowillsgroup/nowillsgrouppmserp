@extends('layouts.app')
@section('title', 'Gate Passes')
@section('crumbs') Security / Gate passes @endsection
@section('actions')
  <form method="get" class="inline" style="display:inline-flex;gap:.3rem"><input type="hidden" name="f" value="all"><input class="input" style="width:13rem" name="q" value="{{ $q }}" placeholder="Pass no. / party / item" id="gpSearch"></form>
  @if(can_do('security.gatepass'))<a class="btn btn-primary" href="{{ route('security.gp.create') }}" id="newGpBtn"><x-icon name="plus"/> New gate pass</a>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="Awaiting approval" :value="$counts['pending']" icon="clock" :tone="$counts['pending'] ? 'amber' : 'green'" :href="route('security.gp.index', ['f' => 'pending'])"/>
  <x-stat label="Approved – ready to go out" :value="$counts['approved']" icon="check" tone="blue" :href="route('security.gp.index', ['f' => 'approved'])"/>
  <x-stat label="Goods outside the property" :value="$counts['away']" icon="box" tone="purple" :href="route('security.gp.index', ['f' => 'away'])" :sub="'est. '.money($counts['value_out']).' still out'"/>
  <x-stat label="Overdue returns" :value="$counts['overdue']" icon="alert" :tone="$counts['overdue'] ? 'red' : 'green'" :href="route('security.gp.index', ['f' => 'overdue'])"/>
</div>
<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\GatePassController::FILTERS as $k => $l)<a href="{{ route('security.gp.index', ['f' => $k]) }}" class="{{ $f === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}</a>@endforeach
  </div></div>
  <div class="table-wrap"><table class="table" id="gpTable"><thead><tr><th>Pass</th><th>Party / purpose</th><th>Items</th><th>Return by</th><th>Raised by</th><th>Status</th></tr></thead><tbody>
  @forelse($list as $p)
    <tr data-gp="{{ $p->pass_no }}" class="{{ $p->isOverdue() ? 'row-alert' : '' }}"><td class="nowrap"><a href="{{ route('security.gp.show', $p) }}" class="mono"><b>{{ $p->pass_no }}</b></a><div class="tiny muted">{{ \App\Models\GatePass::TYPES[$p->type] }}</div></td>
      <td>{{ $p->party_name }}<div class="tiny muted">{{ \App\Models\GatePass::PURPOSES[$p->purpose] ?? $p->purpose }}{{ $p->department ? ' · '.$p->department->name : '' }}</div></td>
      <td class="small">{{ $p->items->map(fn ($i) => rtrim(rtrim(number_format((float) $i->quantity, 2), '0'), '.').' × '.$i->description)->take(3)->join(', ') }}{{ $p->items->count() > 3 ? ' +'.($p->items->count() - 3).' more' : '' }}</td>
      <td class="nowrap small">@if($p->type === 'returnable'){{ $p->expected_return_date?->format('d M Y') }}@if($p->isOverdue())<div class="tiny text-red">{{ $p->daysOverdue() }} day(s) overdue</div>@endif @else<span class="muted">–</span>@endif</td>
      <td class="small">{{ $p->requester?->name }}<div class="tiny muted">{{ $p->created_at->format('d M H:i') }}</div></td>
      <td><span class="badge badge-{{ $p->statusTone() }}">{{ $p->statusLabel() }}</span></td></tr>
  @empty<tr><td colspan="6"><x-empty icon="box" text="No gate passes here."/></td></tr>@endforelse
  </tbody></table></div>
  <div class="card-body pad">{{ $list->links() }}<p class="tiny muted m-0">Nothing leaves the property without an approved gate pass. Security checks the goods against the pass at the gate and records returns; returnable goods not back by the due date are flagged in the log book.</p></div>
</div>
@endsection
