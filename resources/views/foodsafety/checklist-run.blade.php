@extends('layouts.app')
@section('title', $c->name)
@section('crumbs') <a href="{{ route('fs.index') }}">Food Safety</a> / <a href="{{ route('fs.checklists') }}">Checklists</a> / {{ $c->name }} @endsection
@section('content')
@if($done)
  <div class="alert alert-info"><x-icon name="check"/><div>Already completed for {{ \App\Services\FoodSafetyService::periodLabel($c, $period) }} by {{ $done->completer?->name }}. <a href="{{ route('fs.checklists.show', $done) }}">Open it</a>.</div></div>
@else
<form method="post" action="{{ route('fs.checklists.complete', $c) }}" class="card" id="runForm" style="max-width:820px">@csrf
  <div class="card-head"><h3>{{ $c->name }}</h3><span class="small muted">{{ $c->area }} · {{ \App\Services\FoodSafetyService::periodLabel($c, $period) }}{{ $c->due_time ? ' · due '.substr($c->due_time, 0, 5) : '' }}</span></div>
  <div class="card-body">
    @foreach($c->items as $i => $text)
      <div class="fs-check" data-item="{{ $i }}">
        <div><b class="small">{{ $i + 1 }}.</b> {{ $text }}</div>
        <div class="yn"><label><input type="radio" name="answers[{{ $i }}][ok]" value="1" @checked(old("answers.$i.ok") === '1')><span class="ok">✓ Yes</span></label><label><input type="radio" name="answers[{{ $i }}][ok]" value="0" @checked(old("answers.$i.ok") === '0')><span class="no">✗ No</span></label></div>
        <input class="input input-sm note" name="answers[{{ $i }}][note]" value="{{ old("answers.$i.note") }}" maxlength="200" placeholder="If no: what was wrong and what was done" @if(old("answers.$i.ok") !== '0') hidden @endif>
      </div>
    @endforeach
    <div class="field mt-1"><label for="f_notes">General notes</label><textarea name="notes" id="f_notes" rows="2" maxlength="500">{{ old('notes') }}</textarea></div>
  </div>
  <div class="card-foot flex justify-between items-center"><span class="small muted">Signed electronically as {{ auth()->user()->name }} · {{ now()->format('d/m/Y H:i') }}</span><div class="flex gap-1"><button type="button" class="btn" id="allYes">All yes</button><button class="btn btn-primary" id="runSave"><x-icon name="check"/> Complete checklist</button></div></div>
</form>
@endif
@endsection
@push('scripts')
<script>
document.querySelectorAll('.fs-check').forEach(row => row.querySelectorAll('input[type=radio]').forEach(r => r.addEventListener('change', () => { const n = row.querySelector('.note'); n.hidden = r.value !== '0' || !r.checked; n.required = !n.hidden; })));
document.getElementById('allYes')?.addEventListener('click', () => document.querySelectorAll('.fs-check input[value="1"]').forEach(r => { r.checked = true; r.dispatchEvent(new Event('change')); }));
</script>
@endpush
