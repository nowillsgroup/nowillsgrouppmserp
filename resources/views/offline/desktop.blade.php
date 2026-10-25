@extends('layouts.app')
@section('title', 'Desktop sync')

@section('actions')
<a class="btn btn-primary" id="downloadPack" href="{{ route('offline.desktop.pack', ['days' => $window, 'device' => 'desk-01']) }}"><x-icon name="download"/> Download {{ $window }}-day pack</a>
@endsection

@section('content')
@if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if (session('warning'))<div class="alert alert-warning">{{ session('warning') }}</div>@endif

<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h3>Offline pack</h3></div>
    <div class="card-body">
    <p class="muted">The pack carries the next {{ $window }} days of desk work — reservations, stays, rooms, rates, payment modes — plus a sync cursor. The desktop keeps working offline and syncs its queue back with the <code>POST /offline/desktop/sync</code> endpoint (each operation carries the <code>occurred_at</code> it happened at).</p>
      <table class="table">
        <tbody>
          @foreach ($counts as $section => $n)
          <tr><td>{{ str_replace('_', ' ', ucfirst($section)) }}</td><td class="bold text-right">{{ $n }}</td></tr>
          @endforeach
        </tbody>
      </table>
      <div class="flex gap">
        <span class="badge badge-blue">Covers {{ \Illuminate\Support\Carbon::parse($pack['covers']['from'])->format('D j M') }} – {{ \Illuminate\Support\Carbon::parse($pack['covers']['until'])->format('D j M') }}</span>
        <span class="badge badge-slate">Cursor {{ \Illuminate\Support\Carbon::parse($pack['cursor'])->format('j M H:i') }}</span>
      </div>
    </div>
  </div>

  @if (auth()->user()->hasPermission('offline.desktop.sync'))
  <div class="card">
    <div class="card-head"><h3>Sync a desktop queue</h3></div>
    <div class="card-body">
    <p class="muted">Paste the desktop’s operation queue (JSON array, or <code>{"device_id": …, "operations": […]}</code>). Retries are safe — an operation id already synced is reported as <em>duplicate</em> and skipped.</p>
    <form method="post" action="{{ route('offline.desktop.sync') }}" id="syncForm">@csrf
      <div class="x-field"><label>Device id</label><input name="device_id" value="desk-01" maxlength="80"></div>
      <div class="x-field"><label>Operations</label>
        <textarea name="operations_json" id="opsJson" rows="8" placeholder='[{"operation_id":"desk01-20261012-0001","operation":"desk.check_in","occurred_at":"2026-10-12T14:20:00Z","payload":{"stay_id":1,"room_id":3}}]'></textarea>
      </div>
      <button class="btn btn-primary" id="doSync"><x-icon name="refresh"/> Sync queue</button>
    </form>
    </div>
  </div>
  @endif
</div>

<div class="card">
  <div class="card-head"><h3>Recent sync operations</h3></div>
  <div class="card-body">
  @if ($ops->isEmpty())
    <x-empty text="Nothing synced from desktops yet." icon="refresh"/>
  @else
    <table class="table">
      <thead><tr><th>Operation</th><th>Kind</th><th>Device</th><th>Status</th><th>When</th><th>Error</th></tr></thead>
      <tbody>
        @foreach ($ops as $o)
        <tr>
          <td class="mono small">{{ $o->operation_id }}</td>
          <td>{{ $o->operation }}</td>
          <td class="small">{{ $o->device_id ?: '—' }}</td>
          <td><span class="badge badge-{{ $o->status === 'applied' ? 'green' : ($o->status === 'duplicate' ? 'slate' : 'red') }}">{{ $o->status }}</span></td>
          <td class="small">{{ $o->applied_at?->format('D j M H:i') }}</td>
          <td class="small" style="max-width:320px">{{ \Illuminate\Support\Str::limit($o->error, 70) }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  @endif
  </div>
</div>

@endsection

@push('scripts')
<script>
document.getElementById('syncForm')?.addEventListener('submit', (e) => {
  const ta = document.getElementById('opsJson');
  if (!ta || !ta.value.trim()) { e.preventDefault(); return; }
  e.preventDefault();
  let ops;
  try {
    const parsed = JSON.parse(ta.value);
    ops = Array.isArray(parsed) ? parsed : parsed.operations;
  } catch { alert('The operations box is not valid JSON.'); return; }
  if (!Array.isArray(ops) || !ops.length) { alert('Provide a JSON array of operations.'); return; }
  fetch(@json(route('offline.desktop.sync')), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
    body: JSON.stringify({ device_id: document.querySelector('[name=device_id]').value || null, operations: ops }),
  }).then(r => r.json()).then(res => {
    const counts = {};
    (res.results ?? []).forEach(r => counts[r.status] = (counts[r.status] ?? 0) + 1);
    alert('Sync complete: ' + Object.entries(counts).map(([k, v]) => v + ' ' + k).join(', '));
    location.reload();
  }).catch(() => alert('The sync request failed.'));
});
</script>
@endpush
