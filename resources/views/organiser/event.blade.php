@extends('organiser.layout')
@section('title', $e->event_no.' · '.$e->title)
@section('crumbs')<a href="{{ route('organiser.events') }}">My events</a> · {{ $e->event_no }}@endsection
@section('actions')
  @if($e->beo_snapshot)<a class="btn" href="{{ route('organiser.beo', $e->id) }}"><x-icon name="edit"/> BEO</a>@endif
  @if($organiser->can('events'))<a class="btn" href="{{ route('organiser.attendees', $e->id) }}"><x-icon name="users"/> Guest list ({{ $summary['total'] }})</a>@endif
@endsection
@section('content')
<div class="card" id="orgEventCard"><div class="card-head"><h3>{{ $e->title }}</h3><span>{!! $e->statusBadge() !!}
  @if($e->beo_approved_at)<span class="badge badge-green">BEO signed {{ $e->beo_approved_at->format('d M') }}</span>@elseif($e->beo_published_at)<span class="badge badge-amber">BEO awaiting your signature</span>@endif</span></div>
<div class="card-body">
  <dl class="dl-grid">
    <div><dt>Type</dt><dd>{{ \App\Models\BanquetEvent::TYPES[$e->event_type] ?? $e->event_type }}</dd></div>
    <div><dt>Dates</dt><dd>{{ $e->event_date?->format('d M Y') }}{{ $e->end_date && $e->end_date->ne($e->event_date) ? ' – '.$e->end_date->format('d M Y') : '' }}</dd></div>
    <div><dt>Contact</dt><dd>{{ $e->contact_name }}{{ $e->contact_phone ? ' · '.$e->contact_phone : '' }}</dd></div>
    <div><dt>Coordinator</dt><dd>{{ $e->salesUser?->name ?? '—' }}{{ $e->salesUser?->phone ? ' · '.$e->salesUser->phone : '' }}</dd></div>
    @if($e->requirements)<div><dt>Your requirements</dt><dd class="small">{!! nl2br(e($e->requirements)) !!}</dd></div>@endif
  </dl>
  @if($changed && $e->beo_published_at && ! $e->beo_approved_at)<div class="alert alert-info"><x-icon name="refresh"/><div>The hotel adjusted this event after publishing the BEO – the copy you sign is the published one below.</div></div>@endif
</div></div>

<div class="stats" id="orgMoney">
  <x-stat label="Event value" :value="money($money['total'])" icon="trend" tone="blue" :sub="'incl. '.money($money['tax']).' tax'"/>
  <x-stat label="Paid so far" :value="money($money['deposits'])" icon="wallet" tone="green" :sub="$money['folio_no'] ? 'event folio '.$money['folio_no'] : null"/>
  <x-stat label="Balance" :value="money($money['balance'])" icon="receipt" :tone="$money['balance'] > 0 ? 'amber' : 'green'" :sub="$money['deposit_due'] > 0 && $money['deposits'] < $money['deposit_due'] ? 'deposit '.money($money['deposit_due']).($money['deposit_due_date'] ? ' due '.$money['deposit_due_date']->format('d M') : '') : null"/>
</div>

@if($errors->has('pay'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('pay') }}</div></div>@endif
@if($canPay && $organiser->can('finance') && $money['balance'] > 0 && $e->isOpen() && ! $e->billed_at)
<div class="card no-print" id="orgPayCard"><div class="card-body">
  <form method="post" action="{{ route('organiser.pay', $e->id) }}" class="ptn-pay" id="orgPayForm">
    @csrf
    <div class="field"><label for="amount">Pay online (card / mobile money)</label><input class="input" type="number" step="0.01" min="1" max="{{ round($money['balance'], 2) }}" id="amount" name="amount" value="{{ number_format($money['balance'], 2, '.', '') }}" required></div>
    <button class="btn btn-primary" type="submit"><x-icon name="wallet"/> Pay now</button>
    <span class="tiny muted">Recorded on the event folio as soon as the payment succeeds.</span>
  </form>
</div></div>
@endif

<div class="card" id="orgFunctions"><div class="card-head"><h3><x-icon name="calendar"/> Functions &amp; guest numbers</h3></div><div class="card-body table-wrap">
  <table class="table table-compact"><thead><tr><th>Function</th><th>Date</th><th>Venue &amp; set-up</th><th>Menu</th><th class="right">Expected</th><th class="right">Guarantee</th><th></th></tr></thead><tbody>
  @foreach($e->functions as $f)
    <tr><td><b>{{ $f->name }}</b><div class="tiny muted">{{ $f->timeRange() }}</div></td>
      <td class="nowrap small">{{ $f->date->format('d M Y') }}</td>
      <td class="small">{{ $f->hall?->name }} · {{ \App\Models\BanquetFunction::SETUPS[$f->setup_style] ?? '' }}</td>
      <td class="small">{{ $f->package?->name ?? '—' }}@if($f->rate_per_pax) · {{ money($f->rate_per_pax) }}/guest @endif</td>
      <td class="right">{{ $f->expected_pax }}</td>
      <td class="right">@if($f->guaranteed_pax)<b>{{ $f->guaranteed_pax }}</b>@else<span class="tiny muted">not set</span>@endif<div class="tiny muted">final {{ $f->guaranteeCutoff()->format('d M') }}</div></td>
      <td>@if($organiser->can('events') && $e->isEditable())
        <form method="post" action="{{ route('organiser.guarantee', $e->id) }}" class="flex gap-1" id="orgGuaranteeForm-{{ $f->id }}">
          @csrf<input type="hidden" name="function_id" value="{{ $f->id }}">
          <input class="input" style="width:5.5em" type="number" min="1" max="100000" name="pax" value="{{ $f->guaranteed_pax ?? $f->expected_pax }}" required aria-label="Guarantee for {{ $f->name }}">
          <button class="btn btn-sm" type="submit">Set</button>
        </form>@endif</td></tr>
    @if($f->notes)<tr><td></td><td colspan="6" class="tiny muted">{{ $f->notes }}</td></tr>@endif
  @endforeach
  </tbody></table>
  @if($errors->has('guarantee'))<div class="alert alert-error mt-1"><x-icon name="alert"/><div>{{ $errors->first('guarantee') }}</div></div>@endif
  <p class="tiny muted m-0">The guarantee is the number of guests you pay for. It can be lowered until {{ setting('banquet_guarantee_days', 3) }} days before each function – afterwards only upwards.</p>
</div></div>

@if($e->beo_published_at)
<div class="card" id="orgBeoCard"><div class="card-head"><h3><x-icon name="edit"/> Banquet event order</h3><a class="btn btn-sm" href="{{ route('organiser.beo', $e->id) }}">Open the BEO</a></div><div class="card-body">
  @if($e->beo_approved_at)<p class="m-0"><span class="badge badge-green">Signed</span> by <b>{{ $e->beo_sign_name }}</b> on {{ $e->beo_approved_at->format('d M Y H:i') }}. Published {{ $e->beo_published_at->format('d M Y H:i') }}.</p>
  @elseif($organiser->can('events'))
    @if($errors->has('beo'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('beo') }}</div></div>@endif
    <form method="post" action="{{ route('organiser.beo.sign', $e->id) }}" class="ptn-pay" id="orgSignForm" onsubmit="return confirm('Sign this banquet event order? It becomes the agreement for your event.')">
      @csrf
      <div class="field"><label for="sign_name">Type your full name to sign</label><input class="input" id="sign_name" name="sign_name" value="{{ old('sign_name', $organiser->name) }}" required maxlength="120"></div>
      <button class="btn btn-primary" type="submit" id="orgBeoSign"><x-icon name="edit"/> Sign the BEO</button>
      <span class="tiny muted">Published {{ $e->beo_published_at->format('d M Y H:i') }} · please sign before {{ \Carbon\Carbon::parse($e->event_date)->subDays((int) setting('organiser_beo_days', 7))->format('d M Y') }}.</span>
    </form>
  @else<p class="muted m-0">Published {{ $e->beo_published_at->format('d M Y H:i') }} – awaiting the planner's signature.</p>@endif
</div></div>
@endif

@if($organiser->can('events') && $e->isEditable())
<div class="g-2">
  <div class="card no-print" id="orgChangeCard"><div class="card-head"><h3><x-icon name="message"/> Ask for a change</h3></div><div class="card-body">
    @if($openChange)<p class="muted m-0" id="orgChangeOpen">A change request is already with your coordinator – they will reply by e-mail.</p>
    @else
    @if($errors->has('change'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('change') }}</div></div>@endif
    <form method="post" action="{{ route('organiser.change', $e->id) }}" id="orgChangeForm">
      @csrf
      <div class="g-2">
        <div class="field"><label for="ch_date">New date (optional)</label><input class="input" type="date" id="ch_date" name="date" value="{{ old('date') }}"></div>
        <div class="field"><label for="ch_pax">New guest number (optional)</label><input class="input" type="number" min="1" max="10000" id="ch_pax" name="pax" value="{{ old('pax') }}"></div>
      </div>
      <div class="field"><label for="ch_package">Menu / package (optional)</label><input class="input" id="ch_package" name="package" value="{{ old('package') }}" maxlength="120" placeholder="e.g. upgrade to the Premium Wedding Buffet"></div>
      <div class="field"><label for="ch_details">What should change?</label><textarea class="input" id="ch_details" name="details" rows="3" required>{{ old('details') }}</textarea></div>
      <button class="btn btn-primary" type="submit">Send change request</button>
    </form>@endif
  </div></div>
  <div class="card no-print" id="orgCancelCard"><div class="card-head"><h3><x-icon name="x"/> Cancel this event</h3></div><div class="card-body">
    @if($openCancel)<p class="muted m-0">A cancellation request is already with the hotel.</p>
    @else
    @if($errors->has('cancel'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('cancel') }}</div></div>@endif
    <p class="small muted">The hotel's cancellation policy applies (a fee may be due on confirmed events). The venue is only released once the hotel confirms.</p>
    <form method="post" action="{{ route('organiser.cancel', $e->id) }}" id="orgCancelForm" onsubmit="return confirm('Send a cancellation request for {{ $e->event_no }}?')">
      @csrf
      <div class="field"><label for="cx_details">Why are you cancelling?</label><textarea class="input" id="cx_details" name="details" rows="2" required>{{ old('details') }}</textarea></div>
      <button class="btn" type="submit">Send cancellation request</button>
    </form>@endif
  </div></div>
</div>
@endif

@if($requests->isNotEmpty())
<div class="card" id="orgEventRequests"><div class="card-head"><h3><x-icon name="message"/> Requests on this event</h3><a class="small" href="{{ route('organiser.requests') }}">All requests</a></div><div class="card-body table-wrap">
  <table class="table table-compact"><thead><tr><th>No.</th><th>Type</th><th>Details</th><th>Status</th><th>Hotel's answer</th></tr></thead><tbody>
  @foreach($requests as $q)
    <tr><td><b>{{ $q->number }}</b><div class="tiny muted">{{ $q->created_at->format('d M H:i') }} · {{ $q->organiserUser?->name }}</div></td>
      <td class="small">{{ \App\Models\OrganiserRequest::TYPES[$q->type] }}</td><td class="small">{{ \Illuminate\Support\Str::limit($q->details, 140) }}</td>
      <td>{!! $q->statusBadge() !!}</td><td class="small">{{ $q->decision_note ?: '—' }}</td></tr>
  @endforeach
  </tbody></table>
</div></div>
@endif
@endsection
