@extends('layouts.app')
@section('title', 'Portal Activity')
@section('crumbs') Partner Portal / Activity @endsection
@section('content')
<form method="get" class="card mb-2"><div class="card-body g-4" style="align-items:end">
  <div class="field m-0"><label>Partner</label><select class="input" name="company"><option value="">All</option>@foreach($companies as $id => $n)<option value="{{ $id }}" @selected((string) request('company') === (string) $id)>{{ $n }}</option>@endforeach</select></div>
  <div class="field m-0"><label>Action</label><select class="input" name="action"><option value="">All</option>@foreach($actions as $a)<option value="{{ $a }}" @selected(request('action') === $a)>{{ str_replace('_', ' ', $a) }}</option>@endforeach</select></div>
  <div class="field m-0"><label>From</label><input class="input" type="date" name="from" value="{{ request('from') }}"></div>
  <div class="field m-0"><label>To</label><div class="flex gap-1"><input class="input" type="date" name="to" value="{{ request('to') }}"><button class="btn" type="submit"><x-icon name="search" size="sm"/></button></div></div>
</div></form>
<div class="card"><div class="card-body table-wrap">
  @if($rows->isEmpty())<x-empty text="No portal activity matches." icon="eye"/>@else
  <table class="table table-compact" id="ptaActivity"><thead><tr><th>When</th><th>Partner</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th></tr></thead><tbody>
  @foreach($rows as $a)
    <tr class="{{ in_array($a->action, ['locked', 'login_blocked']) ? 'row-red' : '' }}"><td class="small nowrap">{{ $a->created_at->format('d M Y H:i:s') }}</td><td>{{ $a->company?->name ?? '—' }}</td><td class="small">{{ $a->partnerUser?->name ?? ($a->partner_user_id ? '#'.$a->partner_user_id : 'hotel / unknown') }}</td>
      <td><span class="badge {{ str_starts_with($a->action, 'login_f') || $a->action === 'locked' ? 'badge-red' : 'badge-slate' }}">{{ str_replace('_', ' ', $a->action) }}</span></td><td class="small">{{ $a->detail }}</td><td class="tiny muted" title="{{ $a->user_agent }}">{{ $a->ip }}</td></tr>
  @endforeach
  </tbody></table>
  {{ $rows->links('partials.pagination') }}
  @endif
</div></div>
@endsection
