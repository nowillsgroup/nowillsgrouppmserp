@extends('layouts.app')
@section('title', 'Requests & Approvals')
@section('crumbs') Partner Portal / Requests @endsection
@section('content')
@if($errors->has('request'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('request') }}</div></div>@endif
<div class="flex justify-between items-center mb-2" style="flex-wrap:wrap;gap:10px">
  <div class="tabs" id="ptaTabs">@foreach(['pending' => 'Pending ('.$counts->sum().')', 'approved' => 'Approved', 'done' => 'Done', 'declined' => 'Declined', 'withdrawn' => 'Withdrawn', 'all' => 'All'] as $k => $l)<a class="{{ $status === $k ? 'active' : '' }}" href="{{ route('partner-admin.requests', ['status' => $k] + request()->only('type')) }}">{{ $l }}</a> @endforeach</div>
  <div class="tabs">@foreach(['' => 'All types'] + \App\Models\PartnerRequest::TYPES as $k => $l)<a class="{{ (string) request('type') === (string) $k ? 'active' : '' }}" href="{{ route('partner-admin.requests', ['status' => $status, 'type' => $k ?: null]) }}">{{ $l }}{{ $k && ($counts[$k] ?? 0) ? ' ('.$counts[$k].')' : '' }}</a> @endforeach</div>
</div>
@if($rows->isEmpty())<div class="card"><div class="card-body"><x-empty text="No requests here." icon="check"/></div></div>@endif
@foreach($rows as $q)
  @php $r = $q->reservation; @endphp
  <div class="card" id="preq-{{ $q->id }}"><div class="card-head"><h3><span class="badge {{ $q->badge() }}">{{ \App\Models\PartnerRequest::STATUSES[$q->status] }}</span> {{ $q->number }} · {{ \App\Models\PartnerRequest::TYPES[$q->type] }} · {{ $q->company?->name }}</h3>
    <span class="tiny muted">{{ $q->partnerUser?->name }} · {{ $q->created_at->format('d M Y H:i') }} · {{ $q->status === 'pending' ? 'waiting '.$q->responseHours().' h' : 'answered in '.$q->responseHours().' h' }}</span></div>
    <div class="card-body">
      <div class="g-2-1">
        <div>
          @if($r)<p class="m-0"><a href="{{ route('reservations.show', $r->id) }}"><b>{{ $r->reservation_no }}</b></a> · {{ $r->guest?->full_name }} · {{ $r->arrival_date->format('d M') }} – {{ $r->departure_date->format('d M Y') }} · {{ $r->rooms->where('status', '!=', 'cancelled')->count() }} × {{ $r->rooms->first()?->roomType?->name }} · {{ money($r->rooms->where('status', '!=', 'cancelled')->sum(fn ($s) => \App\Services\PartnerService::stayValue($s))) }} · bill to {{ $r->billing_instruction }} · <span class="small">{{ $r->status }}</span></p>@endif
          @if($q->type === 'group' && $q->payload)<p class="m-0"><b>{{ $q->payload['rooms'] ?? '?' }} rooms</b>, {{ \Carbon\Carbon::parse($q->payload['arrival'])->format('d M') }} – {{ \Carbon\Carbon::parse($q->payload['departure'])->format('d M Y') }}{{ ! empty($q->payload['name']) ? ' · '.$q->payload['name'] : '' }}</p>@endif
          @if($q->type === 'amendment' && $q->payload)<p class="m-0 small">Requested: @foreach($q->payload as $pk => $pv){{ $pk }} <b>{{ $pv }}</b> @endforeach</p>@endif
          @if($q->reason)<p class="small mt-1 m-0"><x-icon name="alert" size="sm"/> <b>Held because:</b> {{ $q->reason }}</p>@endif
          @if($q->details)<p class="small mt-1 m-0" style="white-space:pre-line">{{ $q->details }}</p>@endif
          @if($q->decision_note)<p class="small mt-1 m-0 muted">Answer{{ $q->decider ? ' by '.$q->decider->name : '' }}: {{ $q->decision_note }}</p>@endif
        </div>
        @if($q->status === 'pending' && can_do('partner.approve'))
        <form method="post" action="{{ route('partner-admin.decide', $q) }}" class="ptaDecide">
          @csrf
          <div class="field"><input class="input" name="note" maxlength="500" placeholder="{{ $q->type === 'approval' ? 'Note for the partner (required to decline)' : 'What you changed / quoted (sent to the partner)' }}"></div>
          <div class="flex gap-1">
            @if($q->type === 'approval')<button class="btn btn-primary" name="do" value="approve" type="submit"><x-icon name="check" size="sm"/> Approve &amp; confirm</button>
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
