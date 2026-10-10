@extends('layouts.app')
@section('title', 'Survey Invitations')
@section('crumbs') <a href="{{ route('rep.surveys.index') }}">Surveys</a> / Invitations @endsection
@section('actions')
@if(can_do('feedback.manage'))<a class="btn btn-primary" href="#" data-modal="inviteModal" id="inviteBtn"><x-icon name="mail"/> Send a survey</a>@endif
@endsection
@section('content')
@include('reputation._nav')
<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    <a href="{{ route('rep.invitations', array_filter(['survey' => request('survey')])) }}" class="{{ ! $status ? 'active' : '' }}">All <span class="muted">{{ $counts->sum() }}</span></a>
    @foreach(\App\Models\SurveyInvitation::STATUSES as $k => $l)<a href="{{ route('rep.invitations', array_filter(['status' => $k, 'survey' => request('survey')])) }}" class="{{ $status === $k ? 'active' : '' }}">{{ $l }} <span class="muted">{{ $counts[$k] ?? 0 }}</span></a>@endforeach
  </div>
  <form class="filters" style="margin:0"><input type="hidden" name="status" value="{{ $status }}"><select name="survey" onchange="this.form.submit()"><option value="">All surveys</option>@foreach($surveys as $id => $n)<option value="{{ $id }}" @selected((int) request('survey') === $id)>{{ $n }}</option>@endforeach</select></form></div>
  <div class="card-body table-wrap"><table class="table" id="invTable">
    <thead><tr><th>Guest</th><th>Reservation</th><th>Survey</th><th>Channel</th><th>Status</th><th>Sent</th><th>Opened</th><th>Answered</th><th>Note</th><th></th></tr></thead><tbody>
    @forelse($items as $i)
    <tr><td class="small">@if($i->guest)<a href="{{ route('guests.show', $i->guest) }}">{{ $i->guest->full_name }}</a>@else<span class="muted">—</span>@endif<div class="tiny muted">{{ $i->channel === 'sms' ? $i->to_phone : $i->to_email }}</div></td>
      <td class="small">@if($i->reservation)<a href="{{ route('reservations.show', $i->reservation) }}">{{ $i->reservation->reservation_no }}</a>@endif</td>
      <td class="small">{{ $i->survey?->name }}</td><td class="small">{{ \App\Models\Survey::CHANNELS[$i->channel] ?? $i->channel }}</td>
      <td>{!! status_badge($i->status) !!}</td>
      <td class="small nowrap">{{ $i->sent_at?->format('d M H:i') ?? ($i->send_at ? 'due '.$i->send_at->format('d M H:i') : '—') }}@if($i->reminded_at)<div class="tiny muted">reminded {{ $i->reminded_at->format('d M') }}</div>@endif</td>
      <td class="small nowrap">{{ $i->opened_at?->format('d M H:i') ?? '—' }}</td>
      <td class="small nowrap">@if($i->response)<a href="{{ route('rep.responses.show', $i->response) }}">{{ $i->completed_at?->format('d M H:i') }}</a> {!! nps_badge($i->response->nps) !!}@else—@endif</td>
      <td class="small {{ $i->status === 'failed' ? 'text-red' : 'muted' }}">{{ $i->skip_reason ?? $i->error }}{{ in_array($i->status, ['sent', 'opened']) && $i->expires_at ? 'open until '.$i->expires_at->format('d M') : '' }}</td>
      <td class="right">@if(can_do('feedback.manage') && in_array($i->status, ['scheduled', 'failed']))<form method="post" action="{{ route('rep.invitations.send', $i) }}">@csrf<button class="btn btn-sm">Send now</button></form>@endif</td></tr>
    @empty<tr><td colspan="10"><x-empty text="No invitations yet." icon="mail"/></td></tr>@endforelse
  </tbody></table>{{ $items->links() }}</div>
</div>
@if(can_do('feedback.manage'))
<div class="modal" id="inviteModal"><div class="modal-box"><form method="post" action="{{ route('rep.invitations.store') }}">@csrf
  <div class="modal-head"><h3>Send a survey now</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="reservation_no" label="Reservation no." required placeholder="RES-00012"/>
    <x-field name="survey_id" label="Survey" type="select" :options="\App\Models\Survey::where('is_active', true)->orderByDesc('is_default')->pluck('name', 'id')->all()" required/>
    <p class="tiny muted span-all">For an in-house or checked-out stay. Sent straight away (the per-guest cooldown doesn't apply); one invitation per stay and survey.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="sendInvite">Send</button></div>
</form></div></div>
@endif
@endsection
