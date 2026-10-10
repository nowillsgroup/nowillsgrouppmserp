@extends('layouts.app')
@section('title', 'Requests & Approvals')
@section('crumbs') Organiser Portal / Requests @endsection
@section('content')
@if($errors->has('request'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('request') }}</div></div>@endif
<div class="flex justify-between items-center mb-2" style="flex-wrap:wrap;gap:10px">
  <div class="tabs" id="ogaTabs">@foreach(['pending' => 'Pending ('.$counts->sum().')', 'approved' => 'Approved', 'done' => 'Done', 'declined' => 'Declined', 'withdrawn' => 'Withdrawn', 'all' => 'All'] as $k => $l)<a class="{{ $status === $k ? 'active' : '' }}" href="{{ route('organiser-admin.requests', ['status' => $k] + request()->only('type')) }}">{{ $l }}</a> @endforeach</div>
  <div class="tabs">@foreach(['' => 'All types'] + \App\Models\OrganiserRequest::TYPES as $k => $l)<a class="{{ (string) request('type') === (string) $k ? 'active' : '' }}" href="{{ route('organiser-admin.requests', ['status' => $status, 'type' => $k ?: null]) }}">{{ $l }}{{ $k && ($counts[$k] ?? 0) ? ' ('.$counts[$k].')' : '' }}</a> @endforeach</div>
</div>
@if($rows->isEmpty())<div class="card"><div class="card-body"><x-empty text="No requests here." icon="check"/></div></div>@endif
@foreach($rows as $q)
  @php $e = $q->event; @endphp
  <div class="card" id="oreq-{{ $q->id }}"><div class="card-head"><h3>{!! $q->statusBadge() !!} {{ $q->number }} · {{ \App\Models\OrganiserRequest::TYPES[$q->type] }} · {{ $q->company?->name ?? 'Private client' }}</h3>
    <span class="tiny muted">{{ $q->organiserUser?->name }} · {{ $q->created_at->format('d M Y H:i') }} · {{ $q->status === 'pending' ? 'waiting '.$q->responseHours().' h' : 'answered in '.$q->responseHours().' h' }}</span></div>
    <div class="card-body">
      <div class="g-2-1">
        <div>
          @if($e)<p class="m-0"><a href="{{ route('organiser-admin.event', $e->id) }}"><b>{{ $e->event_no }}</b></a> · {{ $e->title }} · {{ $e->event_date?->format('d M Y') }} · {{ $e->pax() }} guests · {{ money($e->total()) }} · <span class="small">{{ $e->status }}</span></p>@endif
          @if($q->type === 'quote' && $q->payload)<p class="m-0"><b>{{ $q->payload['title'] ?? '' }}</b> · {{ \App\Models\BanquetEvent::TYPES[$q->payload['event_type'] ?? ''] ?? '' }} · {{ isset($q->payload['event_date']) ? \Carbon\Carbon::parse($q->payload['event_date'])->format('d M Y') : '' }} · {{ $q->payload['pax'] ?? '?' }} guests</p>@endif
          @if($q->type === 'change' && $q->payload)<p class="m-0 small">Requested: @foreach($q->payload as $pk => $pv){{ $pk }} <b>{{ $pv }}</b> @endforeach</p>@endif
          @if($q->type === 'cancel' && $q->payload)<p class="m-0 small">Quoted when sent: <b>{{ money($q->payload['fee'] ?? 0) }}</b> ({{ $q->payload['pct'] ?? 0 }}% · {{ $q->payload['days'] ?? '?' }} days before)</p>@endif
          @if($q->details)<p class="small mt-1 m-0" style="white-space:pre-line">{{ $q->details }}</p>@endif
          @if($q->decision_note)<p class="small mt-1 m-0 muted">Answer{{ $q->decider ? ' by '.$q->decider->name : '' }}: {{ $q->decision_note }}</p>@endif
        </div>
        @if($q->status === 'pending' && can_do('organiser.approve'))
        <form method="post" action="{{ route('organiser-admin.decide', $q) }}" class="ptaDecide">
          @csrf
          <div class="field"><input class="input" name="note" maxlength="500" placeholder="{{ $q->type === 'cancel' ? 'Note for the organiser (required to decline)' : 'What you changed / quoted (sent to the organiser)' }}"></div>
          <div class="flex gap-1">
            @if($q->type === 'cancel')<button class="btn btn-primary" name="do" value="approve" type="submit" onclick="return confirm('Approve the cancellation? The event is released (a fee may post).')"><x-icon name="check" size="sm"/> Approve cancellation</button>
            @else<button class="btn btn-primary" name="do" value="done" type="submit"><x-icon name="check" size="sm"/> Mark done</button>@endif
            <button class="btn btn-danger" name="do" value="decline" type="submit">Decline</button>
          </div>
        </form>
        @endif
      </div>
    </div></div>
@endforeach
{{ $rows->links('partials.pagination') }}
@endsection
