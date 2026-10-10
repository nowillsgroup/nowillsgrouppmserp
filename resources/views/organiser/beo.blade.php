@extends('organiser.layout')
@section('title', 'Banquet event order · '.$e->event_no)
@section('crumbs')<a href="{{ route('organiser.events') }}">My events</a> · <a href="{{ route('organiser.event', $e->id) }}">{{ $e->event_no }}</a> · BEO@endsection
@section('actions') <button class="btn no-print" type="button" onclick="window.print()"><x-icon name="print"/> Print</button> @endsection
@section('content')
<div class="card" id="orgBeo"><div class="card-head"><h3>Banquet event order – {{ $snap['event_no'] }}</h3><span>
  @if($e->beo_approved_at)<span class="badge badge-green">Signed by {{ $e->beo_sign_name }}</span>@else<span class="badge badge-amber">Awaiting signature</span>@endif</span></div>
<div class="card-body">
  <p class="small muted">Published {{ $e->beo_published_at?->format('d M Y H:i') }}{{ $e->beo_approved_at ? ' · signed '.$e->beo_approved_at->format('d M Y H:i') : '' }}. This is the agreement for your event – check every line before signing.</p>
  @if($changed && ! $e->beo_approved_at)<div class="alert alert-info"><x-icon name="refresh"/><div>The hotel adjusted the event after this copy was published – ask your coordinator for a fresh copy if anything below looks wrong.</div></div>@endif
  <dl class="dl-grid">
    <div><dt>Event</dt><dd><b>{{ $snap['title'] }}</b> · {{ $snap['type'] }}</dd></div>
    <div><dt>Dates</dt><dd>{{ \Carbon\Carbon::parse($snap['event_date'])->format('d M Y') }}{{ $snap['end_date'] && $snap['end_date'] !== $snap['event_date'] ? ' – '.\Carbon\Carbon::parse($snap['end_date'])->format('d M Y') : '' }}</dd></div>
    <div><dt>Client</dt><dd>{{ $snap['company'] ?? $snap['contact']['name'] }}{{ $snap['contact']['phone'] ? ' · '.$snap['contact']['phone'] : '' }}</dd></div>
    <div><dt>Guests</dt><dd>expected {{ $snap['expected_pax'] }}{{ $snap['guaranteed_pax'] ? ' · guaranteed '.$snap['guaranteed_pax'] : '' }}</dd></div>
  </dl>
  @foreach($snap['functions'] as $f)
  <h3 class="mt-2">{{ $f['name'] }} <span class="tiny muted">{{ \Carbon\Carbon::parse($f['date'])->format('d M Y') }} · {{ $f['time'] }} · {{ $f['hall'] }} · {{ $f['setup'] }}</span></h3>
  <table class="table table-compact"><thead><tr><th>Item</th><th class="right">Guests</th><th class="right">Rate</th><th class="right">Net</th></tr></thead><tbody>
    <tr><td>{{ $f['package'] ?? 'Venue & service' }}</td><td class="right">{{ max($f['expected_pax'], $f['guaranteed_pax'] ?? 0) }}</td><td class="right">{{ $f['rate_per_pax'] ? money($f['rate_per_pax']).'/guest' : '—' }}</td><td class="right">{{ money($f['net']) }}</td></tr>
    @foreach($f['items'] as $it)<tr><td class="small">{{ $it['desc'] }} × {{ $it['qty'] }}</td><td></td><td class="right small">{{ money($it['price']) }}</td><td class="right small">{{ money($it['amount']) }}</td></tr>@endforeach
  </tbody></table>
  @if($f['notes'])<p class="tiny muted">{{ $f['notes'] }}</p>@endif
  @endforeach
  @if($snap['requirements'])<p class="small"><b>Requirements:</b> {!! nl2br(e($snap['requirements'])) !!}</p>@endif
  <table class="table table-compact" style="max-width:22em;margin-left:auto"><tbody>
    <tr><td>Net</td><td class="right">{{ money($snap['money']['net']) }}</td></tr>
    <tr><td>Tax</td><td class="right">{{ money($snap['money']['tax']) }}</td></tr>
    <tr><td><b>Total</b></td><td class="right"><b>{{ money($snap['money']['total']) }}</b></td></tr>
    <tr><td>Paid</td><td class="right">{{ money($snap['money']['deposits']) }}</td></tr>
    <tr><td>Balance</td><td class="right">{{ money($snap['money']['balance']) }}</td></tr>
  </tbody></table>
  <p class="tiny muted">Guest numbers are final {{ $snap['terms']['guarantee_days'] }} day(s) before each function. Cancellation policy: {{ $snap['terms']['cancel_policy'] ?: 'as agreed' }}.</p>
  @if($e->beo_approved_at)
    <p class="m-0">Signed by <b>{{ $e->beo_sign_name }}</b> on {{ $e->beo_approved_at->format('d M Y H:i') }}.</p>
  @elseif($organiser->can('events'))
    @if($errors->has('beo'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('beo') }}</div></div>@endif
    <form method="post" action="{{ route('organiser.beo.sign', $e->id) }}" class="ptn-pay no-print" id="orgSignForm" onsubmit="return confirm('Sign this banquet event order? It becomes the agreement for your event.')">
      @csrf
      <div class="field"><label for="sign_name">Type your full name to sign</label><input class="input" id="sign_name" name="sign_name" value="{{ old('sign_name', $organiser->name) }}" required maxlength="120"></div>
      <button class="btn btn-primary" type="submit" id="orgBeoSign"><x-icon name="edit"/> Sign the BEO</button>
    </form>
  @endif
</div></div>
@endsection
