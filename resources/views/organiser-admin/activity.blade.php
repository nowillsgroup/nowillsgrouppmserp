@extends('layouts.app')
@section('title', 'Portal Activity')
@section('crumbs') Organiser Portal / Activity @endsection
@section('content')
<div class="card"><div class="card-body">
  <form method="get" action="{{ route('organiser-admin.activity') }}" class="flex gap-1" style="flex-wrap:wrap">
    <select class="input" name="event" style="width:16em"><option value="">All portal events</option>@foreach($events as $e)<option value="{{ $e->id }}" {{ (string) request('event') === (string) $e->id ? 'selected' : '' }}>{{ $e->event_no }} · {{ $e->title }}</option>@endforeach</select>
    <select class="input" name="action" style="width:12em"><option value="">All actions</option>@foreach($actions as $a)<option value="{{ $a }}" {{ request('action') === $a ? 'selected' : '' }}>{{ str_replace('_', ' ', $a) }}</option>@endforeach</select>
    <input class="input" type="date" name="from" value="{{ request('from') }}" style="width:10em"><input class="input" type="date" name="to" value="{{ request('to') }}" style="width:10em">
    <button class="btn" type="submit">Filter</button>
  </form>
</div></div>
<div class="card"><div class="card-body table-wrap">
  @if($rows->isEmpty())<x-empty text="No activity matches." icon="eye"/>@else
  <table class="table table-compact" id="ogaActivity"><thead><tr><th>When</th><th>Event / account</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead><tbody>
  @foreach($rows as $a)
    <tr class="{{ in_array($a->action, ['locked', 'login_blocked']) ? 'row-red' : ($a->action === 'login_failed' ? 'row-amber' : '') }}"><td class="small nowrap">{{ $a->created_at->format('d M H:i') }}</td>
      <td class="small">@if($a->event)<a href="{{ route('organiser-admin.event', $a->event->id) }}">{{ $a->event->event_no }}</a>@else{{ $a->company?->name ?? '—' }}@endif</td>
      <td class="small">{{ $a->organiserUser?->name ?? '—' }}</td><td><span class="badge badge-slate">{{ str_replace('_', ' ', $a->action) }}</span></td>
      <td class="small">{{ $a->detail }}</td><td class="small">{{ $a->ip }}</td></tr>
  @endforeach
  </tbody></table>
  {{ $rows->links('partials.pagination') }}
  @endif
</div></div>
@endsection
