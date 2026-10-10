@extends('partner.layout')
@section('title', 'Requests')
@section('content')
@if($errors->has('request'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('request') }}</div></div>@endif
<p class="small muted">Bookings waiting for the hotel's approval, change requests and group quotes – with the hotel's answers.</p>
<div class="card"><div class="card-body table-wrap">
  @if($rows->isEmpty())<x-empty text="No requests yet." icon="message"/>@else
  <table class="table" id="ptnRequests"><thead><tr><th>No.</th><th>Type</th><th>Booking</th><th>Details</th><th>Sent</th><th>Status</th><th>Hotel's answer</th><th></th></tr></thead><tbody>
  @foreach($rows as $q)
    <tr><td><b>{{ $q->number }}</b></td><td>{{ \App\Models\PartnerRequest::TYPES[$q->type] }}</td>
      <td>@if($q->reservation)<a href="{{ route('partner.booking', $q->reservation_id) }}">{{ $q->reservation->reservation_no }}</a>@else — @endif</td>
      <td class="small">@if($q->type === 'group'){{ $q->payload['rooms'] ?? '?' }} rooms · {{ \Carbon\Carbon::parse($q->payload['arrival'])->format('d M') }} – {{ \Carbon\Carbon::parse($q->payload['departure'])->format('d M Y') }}{{ ! empty($q->payload['name']) ? ' · '.$q->payload['name'] : '' }}<br>@endif{{ \Illuminate\Support\Str::limit($q->details ?: $q->reason, 120) }}</td>
      <td class="small nowrap">{{ $q->created_at->format('d M H:i') }}<div class="tiny muted">{{ $q->partnerUser?->name }}</div></td>
      <td><span class="badge {{ $q->badge() }}">{{ \App\Models\PartnerRequest::STATUSES[$q->status] }}</span></td>
      <td class="small">{{ $q->decision_note ?: '—' }}</td>
      <td>@if($q->status === 'pending' && $partner->can('book'))<form method="post" action="{{ route('partner.requests.withdraw', $q->id) }}" onsubmit="return confirm('Withdraw {{ $q->number }}?{{ $q->type === 'approval' ? ' The held booking will be released.' : '' }}')">@csrf<button class="btn btn-sm" type="submit">Withdraw</button></form>@endif</td></tr>
  @endforeach
  </tbody></table>
  {{ $rows->links('partials.pagination') }}
  @endif
</div></div>
@if($partner->can('book'))<p class="small mt-2"><a href="{{ route('partner.book') }}#group"><x-icon name="users" size="sm"/> Request a group quote</a></p>@endif
@endsection
