@extends('organiser.layout')
@section('title', 'Requests')
@section('content')
@if($errors->has('request'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('request') }}</div></div>@endif
<p class="small muted">Change and cancellation requests on your events, and new-event quotes – with the hotel's answers.</p>
<div class="card"><div class="card-body table-wrap">
  @if($rows->isEmpty())<x-empty text="No requests yet." icon="message"/>@else
  <table class="table" id="orgRequests"><thead><tr><th>No.</th><th>Type</th><th>Event</th><th>Details</th><th>Sent</th><th>Status</th><th>Hotel's answer</th><th></th></tr></thead><tbody>
  @foreach($rows as $q)
    <tr><td><b>{{ $q->number }}</b></td><td>{{ \App\Models\OrganiserRequest::TYPES[$q->type] }}</td>
      <td>@if($q->event)<a href="{{ route('organiser.event', $q->event->id) }}">{{ $q->event->event_no }}</a>@else<span class="tiny muted">{{ $q->payload['title'] ?? '—' }}</span>@endif</td>
      <td class="small">@if($q->type === 'quote'){{ $q->payload['pax'] ?? '?' }} guests · {{ isset($q->payload['event_date']) ? \Carbon\Carbon::parse($q->payload['event_date'])->format('d M Y') : '' }}<br>@endif{{ \Illuminate\Support\Str::limit($q->details, 120) }}</td>
      <td class="small nowrap">{{ $q->created_at->format('d M H:i') }}<div class="tiny muted">{{ $q->organiserUser?->name }}</div></td>
      <td>{!! $q->statusBadge() !!}</td>
      <td class="small">{{ $q->decision_note ?: '—' }}</td>
      <td>@if($q->status === 'pending' && $organiser->can('events'))<form method="post" action="{{ route('organiser.requests.withdraw', $q->id) }}" onsubmit="return confirm('Withdraw {{ $q->number }}?')">@csrf<button class="btn btn-sm" type="submit">Withdraw</button></form>@endif</td></tr>
  @endforeach
  </tbody></table>@endif
</div></div>
@if($organiser->can('events'))
<div class="card no-print mt-2" id="quote"><div class="card-head"><h3><x-icon name="plus"/> Ask for a new-event quote</h3></div><div class="card-body">
  @if($errors->has('quote'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('quote') }}</div></div>@endif
  <form method="post" action="{{ route('organiser.requests.quote') }}" id="orgQuoteForm">
    @csrf
    <div class="g-2">
      <div class="field"><label for="q_title">Event title</label><input class="input" id="q_title" name="title" value="{{ old('title') }}" required maxlength="120"></div>
      <div class="field"><label for="q_type">Kind of event</label><select class="input" id="q_type" name="event_type">@foreach(\App\Models\BanquetEvent::TYPES as $k => $v)<option value="{{ $k }}" {{ old('event_type') === $k ? 'selected' : '' }}>{{ $v }}</option>@endforeach</select></div>
      <div class="field"><label for="q_date">Event date</label><input class="input" type="date" id="q_date" name="event_date" value="{{ old('event_date') }}" required></div>
      <div class="field"><label for="q_pax">Expected guests</label><input class="input" type="number" min="1" max="10000" id="q_pax" name="pax" value="{{ old('pax') }}" required></div>
    </div>
    <div class="field"><label for="q_details">Anything else (venue, menu, timings)</label><textarea class="input" id="q_details" name="details" rows="2">{{ old('details') }}</textarea></div>
    <button class="btn btn-primary" type="submit">Send quote request</button>
  </form>
</div></div>
@endif
@endsection
