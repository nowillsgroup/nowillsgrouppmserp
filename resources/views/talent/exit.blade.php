@extends('layouts.app')
@section('title', $x->number)
@section('crumbs') Talent Management / <a href="{{ route('talent.exits') }}">Exits</a> / {{ $x->number }} @endsection
@php $open = $x->status === 'notice'; $iv = $x->interview ?? []; @endphp
@section('actions')<a class="btn" href="{{ route('talent.exit.letter', $x) }}" id="talExitLetter"><x-icon name="print"/> {{ $x->isVoluntary() ? 'Acceptance letter' : 'Termination letter' }}</a>@endsection
@section('content')
<div class="g-2-1">
  <div>
    <div class="card"><div class="card-head"><h3>{{ $x->employee->name }} – {{ $x->typeLabel() }}</h3>@include('talent._badge', ['s' => $x->status, 'l' => $x->statusLabel()])</div><div class="card-body"><div class="kv-grid" id="talExitKv">
      <div><span>Position</span><b>{{ $x->employee->designation }} · {{ $x->employee->department?->name }}</b></div><div><span>Service</span><b>{{ $x->employee->joining_date?->format('d M Y') }} – {{ number_format(\App\Services\TalentService::serviceYears($x->employee, $x->last_day), 1) }} years</b></div>
      <div><span>Notice given</span><b>{{ $x->notice_on->format('d M Y') }}</b></div><div><span>Notice</span><b>{{ $x->notice_days }} days – {{ $x->notice_basis }}</b></div>
      <div><span>Last day</span><b>{{ $x->last_day->format('d M Y') }}</b></div><div><span>Final pay due by</span><b>{{ $x->final_pay_due->format('d M Y') }}</b></div>
      @if($x->pilon)<div><span>Pay in lieu of notice</span><b>{{ money($x->pilon_amount) }}</b></div>@endif
      @if($x->clo_notified_on)<div><span>Chief Labour Officer notified</span><b>{{ $x->clo_notified_on->format('d M Y') }}</b></div>@endif
      @if($x->disciplinaryCase)<div><span>Disciplinary case</span><b><a href="{{ route('talent.case', $x->disciplinaryCase) }}">{{ $x->disciplinaryCase->number }}</a></b></div>@endif
      <div><span>Recorded by</span><b>{{ $x->creator?->name }}</b></div>@if($x->completer)<div><span>Completed</span><b>{{ $x->completer->name }} · {{ $x->completed_at->format('d M Y') }}</b></div>@endif
    </div>@if($x->reason)<p class="small pre-line mt-1">{{ $x->reason }}</p>@endif</div></div>
    <div class="card flush"><div class="card-head"><h3>Clearance</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="talClearance"><thead><tr><th>Item</th><th>Status</th><th></th></tr></thead><tbody>
    @foreach(\App\Services\TalentService::CLEARANCE as $k => $l)@php $c = $x->clearance[$k] ?? []; @endphp<tr>
      <td>{{ $l }}@if($k === 'keys')<div class="tiny {{ $facts['keys'] ? 'text-red' : 'muted' }}">{{ $facts['keys'] }} key(s) signed out</div>@endif
        @if($k === 'advance')<div class="tiny {{ $facts['advance'] > 0 ? 'text-amber' : 'muted' }}">outstanding {{ money($facts['advance']) }}</div>@endif
        @if($k === 'leave')<div class="tiny muted">{{ rtrim(rtrim(number_format($facts['leave_days'], 1), '0'), '.') }} day(s) earned leave ≈ {{ money($facts['leave_value']) }}</div>@endif
        @if($k === 'access')<div class="tiny muted">optional – the login is deactivated on completion</div>@endif</td>
      <td class="small">@if(! empty($c['done']))<span class="text-green">✓ {{ $c['by'] }}</span>@else Pending @endif @if(! empty($c['note']))<div class="tiny muted">{{ $c['note'] }}</div>@endif</td>
      <td class="nowrap">@if($open)<form method="post" action="{{ route('talent.exit.action', [$x, 'clearance']) }}" class="tal-inline-form">@csrf<input type="hidden" name="item" value="{{ $k }}"><input type="hidden" name="done" value="{{ empty($c['done']) ? 1 : 0 }}">
        @if(empty($c['done']))<input name="note" placeholder="note" class="tal-note">@endif<button class="btn btn-sm" id="talClr_{{ $k }}">{{ empty($c['done']) ? 'Done' : 'Undo' }}</button></form>@endif</td></tr>@endforeach
    </tbody></table></div></div>
  </div>
  <div>
    @if($open)<div class="card"><div class="card-head"><h3>Complete</h3></div><div class="card-body">
      <p class="small">On or after the last day, with clearance done, completing marks {{ $x->employee->name }} as left (payroll stops after {{ $x->last_day->format('d M Y') }}) and deactivates the login.</p>
      <form method="post" action="{{ route('talent.exit.action', [$x, 'complete']) }}">@csrf<button class="btn btn-primary" id="talExitComplete">Complete exit</button></form>
      @unless(in_array($x->type, ['death', 'contract_end'], true))<form method="post" action="{{ route('talent.exit.action', [$x, 'withdraw']) }}" class="mt-1">@csrf<x-field name="reason" label="Withdraw – reason"/><button class="btn btn-sm">Withdraw exit</button></form>@endunless
    </div></div>@endif
    <form method="post" action="{{ route('talent.exit.action', [$x, 'interview']) }}" class="card" id="talExitInterview">@csrf<div class="card-head"><h3>Exit interview</h3></div><div class="card-body">
      <x-field name="primary" label="Main reason for leaving" type="select" :options="\App\Services\TalentService::EXIT_REASONS" :value="$iv['primary'] ?? null" required/>
      <x-field name="satisfaction" label="Overall satisfaction (1–5)" type="select" :options="[5 => '5', 4 => '4', 3 => '3', 2 => '2', 1 => '1']" :value="$iv['satisfaction'] ?? null" required/>
      <x-field name="liked" label="What they valued" type="textarea" :value="$iv['liked'] ?? null"/><x-field name="improve" label="What we should improve" type="textarea" :value="$iv['improve'] ?? null"/>
      <label class="check small"><input type="checkbox" name="recommend" value="1" @checked($iv['recommend'] ?? false)> Would recommend us as an employer</label>
      <label class="check small"><input type="checkbox" name="regretted" value="1" @checked($x->regretted)> Regretted loss</label>
      <label class="check small"><input type="checkbox" name="rehire" value="1" @checked($x->rehire)> Eligible for re-hire</label>
    </div><div class="card-foot"><button class="btn" id="talExitIvSave">Save</button>@if($iv)<span class="tiny muted"> recorded by {{ $iv['by'] ?? '' }} on {{ $iv['on'] ?? '' }}</span>@endif</div></form>
  </div>
</div>
@endsection
