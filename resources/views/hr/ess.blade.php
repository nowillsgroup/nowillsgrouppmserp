@extends('layouts.app')
@section('title', 'Self-Service Desk')
@section('actions')
<button class="btn btn-primary" data-modal="announceModal" id="announceBtn"><x-icon name="plus"/> New announcement</button>
@endsection

@if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="card">
  <div class="card-head"><h3>Profile change requests</h3></div>
  <div class="card-body table-wrap">
    @if ($requests->isEmpty())
      <x-empty text="No profile change requests." icon="user"/>
    @else
      <table class="table table-compact">
        <thead><tr><th>Employee</th><th>Requested</th><th>Changes</th><th>Status</th><th>Review</th></tr></thead>
        <tbody>
          @foreach ($requests as $r)
          <tr>
            <td>{{ $r->employee?->name }}</td>
            <td class="small">{{ $r->created_at->format('j M Y H:i') }}</td>
            <td class="small" style="max-width:340px">
              @foreach ($r->changes as $f => $pair)<div><span class="muted">{{ \App\Services\EmployeeSelfService::PROFILE_FIELDS[$f] ?? $f }}:</span> {{ \Illuminate\Support\Str::limit($pair['old'], 24) ?: '—' }} → <span class="bold">{{ \Illuminate\Support\Str::limit($pair['new'], 24) }}</span></div>@endforeach
            </td>
            <td><span class="badge badge-{{ $r->statusTone() }}">{{ $r->statusLabel() }}</span>@if ($r->review_note)<div class="small muted">{{ $r->review_note }}</div>@endif</td>
            <td>
              @if ($r->status === 'pending')
              <form method="post" action="{{ route('hr.ess.decide', $r) }}" class="inline">@csrf
                <input type="hidden" name="action" value="approve">
                <button class="btn btn-sm btn-primary" data-confirm="Apply these changes to the employee record?"><x-icon name="check"/></button>
              </form>
              <form method="post" action="{{ route('hr.ess.decide', $r) }}" class="inline">@csrf
                <input type="hidden" name="action" value="reject">
                <button class="btn btn-sm" data-confirm="Reject this change request?"><x-icon name="x"/></button>
              </form>
              @else — @endif
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Document verifications</h3></div>
  <div class="card-body table-wrap">
    @if ($docs->isEmpty())
      <x-empty text="No document uploads." icon="file"/>
    @else
      <table class="table table-compact">
        <thead><tr><th>Employee</th><th>Title</th><th>Kind</th><th>Size</th><th>Status</th><th>Review</th></tr></thead>
        <tbody>
          @foreach ($docs as $d)
          <tr>
            <td>{{ $d->employee?->name }}</td>
            <td>{{ $d->title }}</td>
            <td class="small">{{ \App\Services\EmployeeSelfService::DOC_KINDS[$d->kind] ?? '—' }}</td>
            <td class="small">{{ number_format($d->bytes / 1024, 0) }} KB</td>
            <td><span class="badge badge-{{ $d->statusTone() }}">{{ $d->statusLabel() }}</span></td>
            <td>
              @if ($d->status === 'new')
              <form method="post" action="{{ route('hr.ess.doc', $d) }}" class="inline">@csrf
                <input type="hidden" name="status" value="verified">
                <button class="btn btn-sm btn-primary" data-confirm="Mark this document verified?"><x-icon name="check"/></button>
              </form>
              <form method="post" action="{{ route('hr.ess.doc', $d) }}" class="inline">@csrf
                <input type="hidden" name="status" value="rejected">
                <button class="btn btn-sm" data-confirm="Reject this document?"><x-icon name="x"/></button>
              </form>
              @else — @endif
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<div class="card">
  <div class="card-head"><h3>Recent announcements</h3></div>
  <div class="card-body">
    @foreach ($announcements as $a)
      <div class="small"><span class="bold">{{ $a->title }}</span> <span class="muted">· {{ $a->audience }} · {{ $a->publish_at->format('j M Y') }}</span></div>
    @endforeach
  </div>
</div>

<div class="modal" id="announceModal"><div class="modal-box">
  <form method="post" action="{{ route('hr.ess.announce') }}">@csrf
    <div class="modal-head"><h3>New announcement</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
    <div class="modal-body">
      <div class="x-field"><label>Title</label><input name="title" maxlength="200" required></div>
      <div class="x-field"><label>Text</label><textarea name="body" rows="5" required></textarea></div>
      <div class="x-field"><label>Audience</label>
        <select name="audience"><option value="staff">All staff</option><option value="all">Everyone</option><option value="department">One department</option></select>
      </div>
      <div class="x-field"><label>Department (when one department)</label>
        <select name="department_id"><option value="">—</option>@foreach ($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
      </div>
      <div class="x-field"><label><input type="checkbox" name="is_pinned" value="1"> Pin to the top</label></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="doAnnounce">Publish</button></div>
  </form>
</div></div>
