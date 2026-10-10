@extends('layouts.app')
@section('title', 'e-GRC · '.$g->full_name)
@section('crumbs') Front Office / e-Registration card / {{ $stay->reservation->reservation_no }} @endsection
@section('actions')
  <a href="{{ route('frontdesk.grc', $stay) }}" target="_blank" class="btn"><x-icon name="print"/> Print GRC</a>
  <a href="{{ route('reservations.show', $stay->reservation) }}" class="btn btn-ghost">Back to reservation</a>
@endsection
@section('content')
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3><x-icon name="id"/> Registration details</h3>{!! status_badge($stay->status) !!}</div><div class="card-body">
    <dl class="kv">
      <dt>Guest</dt><dd class="bold">{{ $g->full_name }}</dd>
      <dt>Mobile / email</dt><dd>{{ $g->phone ?: '—' }} {{ $g->email }}</dd>
      <dt>Nationality</dt><dd>{{ $g->nationality ?: '—' }}</dd>
      <dt>ID</dt><dd>{{ $g->id_type ? config('pms.id_types.'.$g->id_type) : '—' }} {{ $g->id_number }}</dd>
      <dt>Room</dt><dd>{{ $stay->room?->room_no ?? 'Not assigned' }} · {{ $stay->roomType->name }}</dd>
      <dt>Stay</dt><dd>{{ $stay->arrival_date->format('d M Y') }} → {{ $stay->departure_date->format('d M Y') }} ({{ $stay->arrival_date->diffInDays($stay->departure_date) }} night(s))</dd>
      <dt>Tariff</dt><dd>{{ money($stay->rate) }} / night</dd>
      @if($stay->reservation->company)<dt>Company</dt><dd>{{ $stay->reservation->company->name }}</dd>@endif
    </dl>
    <div class="small muted" style="margin-top:.8rem;max-height:160px;overflow:auto;border:1px solid var(--c-border);border-radius:var(--radius-sm);padding:.6rem">
      @if(setting('grc_terms')){!! nl2br(e(setting('grc_terms'))) !!}<br>@endif
      I agree to abide by the hotel's terms &amp; conditions. I am responsible for all charges incurred during my stay. The management is not responsible for valuables not deposited at the front desk. Check-out time is {{ substr($currentHotel->checkout_time, 0, 5) }}.
    </div>
  </div></div>

  <div class="card"><div class="card-head"><h3><x-icon name="edit"/> Guest signature</h3>
      @if($stay->grc_signed_at)<span class="badge badge-green">Signed {{ $stay->grc_signed_at->format('d M H:i') }}</span>@else<span class="badge badge-amber">Not signed</span>@endif</div>
    <div class="card-body">
      @if($stay->grc_signature_path)
        <div class="tiny muted">Signature on file:</div>
        <img src="{{ route('frontdesk.grc.signature', $stay) }}" alt="Guest signature" style="max-width:100%;height:90px;border:1px dashed var(--c-border);border-radius:var(--radius-sm);background:#fff">
        <div class="tiny muted" style="margin:.6rem 0 .3rem">Sign again below to replace it.</div>
      @endif
      <form method="post" action="{{ route('frontdesk.grc.signature.store', $stay) }}" id="sigForm">@csrf
        <canvas id="sigPad" width="900" height="300" style="width:100%;height:200px;border:2px dashed var(--c-border-strong);border-radius:var(--radius);background:#fff;touch-action:none;cursor:crosshair"></canvas>
        <input type="hidden" name="signature" id="sigData">
        <label class="check" style="margin:.6rem 0"><input type="checkbox" name="accept" value="1" required> I, {{ $g->full_name }}, confirm the details above and accept the terms.</label>
        <div class="flex gap-1"><button type="button" class="btn" id="sigClear"><x-icon name="refresh" size="sm"/> Clear</button><button class="btn btn-primary" id="sigSave" disabled><x-icon name="check" size="sm"/> Save signature</button></div>
      </form>
    </div></div>
</div>

<div class="card"><div class="card-head"><h3><x-icon name="id"/> ID documents</h3><span class="tiny muted">Stored privately. Only logged-in staff of this hotel can view them, and every view is logged.</span></div><div class="card-body">
  <form method="post" action="{{ route('guests.documents.store', $g) }}" enctype="multipart/form-data" class="filters">@csrf
    <input type="hidden" name="reservation_room_id" value="{{ $stay->id }}">
    <div class="field"><label>Type</label><select name="type">@foreach(\App\Models\GuestDocument::TYPES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    <div class="field" style="flex:1;min-width:220px"><label>Scan / photo (JPG, PNG, WebP, PDF · max 5 MB)</label><input type="file" name="file" accept="image/*,application/pdf" capture="environment" required></div>
    <button class="btn btn-primary"><x-icon name="plus" size="sm"/> Upload</button>
  </form>
  @include('guests._documents', ['docs' => $g->documents])
</div></div>
@endsection
@push('scripts')
<script>
(() => {
  const c = document.getElementById('sigPad'), ctx = c.getContext('2d'), save = document.getElementById('sigSave');
  let drawing = false, strokes = 0, last = null;
  ctx.lineWidth = 3.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#0f172a';
  const pos = e => { const r = c.getBoundingClientRect(); return [(e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height]; };
  c.addEventListener('pointerdown', e => { drawing = true; last = pos(e); c.setPointerCapture(e.pointerId); ctx.beginPath(); ctx.arc(last[0], last[1], 1.4, 0, 7); ctx.fillStyle = '#0f172a'; ctx.fill(); });
  c.addEventListener('pointermove', e => { if (!drawing) return; const p = pos(e); ctx.beginPath(); ctx.moveTo(...last); ctx.lineTo(...p); ctx.stroke(); last = p; strokes++; if (strokes > 8) save.disabled = false; });
  const end = () => { drawing = false; };
  c.addEventListener('pointerup', end); c.addEventListener('pointercancel', end); c.addEventListener('pointerleave', end);
  document.getElementById('sigClear').addEventListener('click', () => { ctx.clearRect(0, 0, c.width, c.height); strokes = 0; save.disabled = true; });
  document.getElementById('sigForm').addEventListener('submit', () => { document.getElementById('sigData').value = c.toDataURL('image/png'); });
})();
</script>
@endpush
