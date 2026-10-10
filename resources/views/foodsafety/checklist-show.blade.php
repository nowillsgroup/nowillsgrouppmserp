@extends('layouts.app')
@section('title', $run->checklist->name)
@section('crumbs') <a href="{{ route('fs.index') }}">Food Safety</a> / <a href="{{ route('fs.checklists') }}">Checklists</a> / {{ $run->checklist->name }} @endsection
@section('actions')<button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>@endsection
@section('content')
<div class="card" style="max-width:820px" id="runShow">
  <div class="card-head"><h3>{{ $run->checklist->name }} · {{ \App\Services\FoodSafetyService::periodLabel($run->checklist, $run->period) }}</h3>
    @if($run->signed_at)<span class="badge badge-green" id="runState">signed off</span>@elseif($run->failed)<span class="badge badge-red" id="runState">{{ $run->failed }} failed – awaiting sign-off</span>@else<span class="badge badge-green" id="runState">all OK</span>@endif</div>
  <div class="card-body table-wrap"><table class="table" id="answerTable">
    <thead><tr><th style="width:2rem">#</th><th>Check</th><th>Result</th><th>Note</th></tr></thead>
    <tbody>@foreach($run->answers as $i => $a)<tr class="{{ $a['ok'] ? '' : 'row-alert' }}"><td>{{ $i + 1 }}</td><td>{{ $a['item'] }}</td><td>{!! $a['ok'] ? '<span class="text-green bold">✓ Yes</span>' : '<span class="text-red bold">✗ No</span>' !!}</td><td class="small">{{ $a['note'] }}</td></tr>@endforeach</tbody>
  </table>
  @if($run->notes)<p class="small"><b>Notes:</b> {{ $run->notes }}</p>@endif
  <ul class="list small mt-1"><li><span>Completed by</span><span>{{ $run->completer?->name }} · {{ $run->completed_at->format('d/m/Y H:i') }}</span></li>
    <li><span>Signed off</span><span>{{ $run->signed_at ? $run->signer?->name.' · '.$run->signed_at->format('d/m/Y H:i') : '—' }}{{ $run->sign_note ? ' – '.$run->sign_note : '' }}</span></li></ul>
  </div>
  @if(! $run->signed_at && can_do('foodsafety.manage') && $run->completed_by !== auth()->id())
  <form method="post" action="{{ route('fs.checklists.sign', $run) }}" class="card-foot flex gap-1 items-end" id="signForm">@csrf
    <div class="field" style="flex:1"><label for="f_sign_note">Sign-off note {{ $run->failed ? '(required – follow-up of failed items)' : '(optional)' }}</label><input name="sign_note" id="f_sign_note" maxlength="255" @if($run->failed) required @endif></div>
    <button class="btn btn-primary" id="signBtn"><x-icon name="check"/> Sign off</button>
  </form>
  @endif
</div>
@endsection
