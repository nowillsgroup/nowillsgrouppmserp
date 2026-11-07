@extends('layouts.app')
@section('title', 'e-GRC · '.$g->full_name)
@section('crumbs') Front Office / e-Registration card / {{ $stay->reservation->reservation_no }} @endsection
@section('actions')
  <a href="{{ route('frontdesk.grc', $stay) }}" target="_blank" rel="noopener noreferrer" class="btn"><x-icon name="print"/> Print GRC</a>
  @if(can_do('reservations.view'))<a href="{{ route('reservations.show', $stay->reservation) }}" class="btn btn-ghost">Back to reservation</a>@endif
@endsection
@section('content')
@php
  $address = collect([$g->address, $g->city, $g->state, $g->country, $g->pincode])->filter()->implode(', ');
  $documents = $g->documents ?? collect();
  $boards = ['EP' => 'Room only', 'CP' => 'Bed & breakfast', 'MAP' => 'Half board', 'AP' => 'Full board'];
  $board = $boards[$stay->ratePlan?->meal_plan ?? 'EP'] ?? ($stay->ratePlan?->meal_plan ?? 'EP');
@endphp
@push('head')
<style>
  .egrc-page { --egrc-green:#17623b; --egrc-ink:#17251b; --egrc-soft:#eff7f0; color:var(--c-text); }
  .egrc-hero { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1rem; padding:1.1rem 1.25rem; border:1px solid var(--c-border); border-radius:var(--radius); background:linear-gradient(115deg,var(--egrc-soft),var(--c-surface) 72%); }
  .egrc-eyebrow { margin:0 0 .2rem; color:var(--egrc-green); font-size:.68rem; font-weight:800; letter-spacing:.09em; text-transform:uppercase; }
  .egrc-hero h1 { margin:0; color:var(--c-text); font-size:1.35rem; line-height:1.25; }
  .egrc-hero p { margin:.3rem 0 0; color:var(--c-text-2); font-size:.83rem; }
  .egrc-hero-meta { display:flex; flex-wrap:wrap; gap:.4rem; justify-content:flex-end; }
  .egrc-chip { display:inline-flex; align-items:center; gap:.3rem; padding:.3rem .55rem; border:1px solid var(--c-border); border-radius:999px; background:var(--c-surface); color:var(--c-text-2); font-size:.7rem; font-weight:700; white-space:nowrap; }
  .egrc-grid { display:grid; grid-template-columns:minmax(0,1.1fr) minmax(320px,.9fr); gap:1rem; align-items:start; }
  .egrc-card { min-width:0; margin-bottom:1rem; overflow:hidden; background:var(--c-surface); border:1px solid var(--c-border); border-radius:var(--radius); box-shadow:var(--shadow-sm); }
  .egrc-card-head { display:flex; justify-content:space-between; align-items:center; gap:.65rem; padding:.8rem 1rem; border-bottom:1px solid var(--c-border); }
  .egrc-card-head h2 { display:flex; align-items:center; gap:.45rem; margin:0; color:var(--c-text); font-size:.91rem; }
  .egrc-card-body { padding:1rem; }
  .egrc-facts { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.1rem .9rem; margin:0; }
  .egrc-fact { min-width:0; padding:.55rem 0; border-bottom:1px solid var(--c-border); }
  .egrc-fact.wide { grid-column:1/-1; }
  .egrc-fact dt { margin:0 0 .1rem; color:var(--c-text-2); font-size:.66rem; font-weight:750; letter-spacing:.045em; text-transform:uppercase; }
  .egrc-fact dd { margin:0; color:var(--c-text); font-size:.82rem; font-weight:600; overflow-wrap:anywhere; }
  .egrc-fact dd.empty { color:var(--c-muted); font-weight:450; }
  .egrc-terms { max-height:220px; overflow:auto; padding:.8rem; border:1px solid var(--c-border); border-radius:var(--radius-sm); background:var(--c-bg); color:var(--c-text-2); font-size:.76rem; line-height:1.55; }
  .egrc-terms p { margin:.1rem 0 .5rem; }
  .egrc-terms p:last-child { margin-bottom:0; }
  .egrc-sign-state { display:inline-flex; align-items:center; gap:.35rem; padding:.28rem .55rem; border-radius:999px; font-size:.68rem; font-weight:750; }
  .egrc-sign-state.signed { background:#e9f6ec; color:#17623b; }
  .egrc-sign-state.pending { background:#fff5df; color:#8a5a00; }
  .egrc-sign-existing { margin-bottom:.9rem; padding:.7rem; border:1px solid var(--c-border); border-radius:var(--radius-sm); background:var(--c-bg); }
  .egrc-sign-existing img { display:block; width:100%; max-width:420px; height:90px; object-fit:contain; object-position:left center; background:#fff; border:1px dashed var(--c-border-strong); border-radius:6px; }
  .egrc-sign-existing a { display:inline-block; margin-top:.4rem; font-size:.75rem; }
  .egrc-sign-pad { display:block; width:100%; height:auto; aspect-ratio:3/1; margin:.35rem 0 .55rem; border:2px dashed var(--c-border-strong); border-radius:var(--radius-sm); background:#fff; touch-action:none; cursor:crosshair; }
  .egrc-sign-help { margin:.15rem 0 .6rem; color:var(--c-text-2); font-size:.74rem; }
  .egrc-accept { display:flex; align-items:flex-start; gap:.55rem; margin:.7rem 0; color:var(--c-text); font-size:.79rem; line-height:1.45; }
  .egrc-accept input { margin-top:.2rem; accent-color:var(--egrc-green); }
  .egrc-sign-actions { display:flex; flex-wrap:wrap; gap:.5rem; }
  .egrc-doc-head-note { color:var(--c-text-2); font-size:.72rem; }
  .egrc-upload { display:grid; grid-template-columns:minmax(145px,.7fr) minmax(220px,1.5fr) auto; gap:.75rem; align-items:end; }
  .egrc-upload .field { min-width:0; }
  .egrc-upload .field input,.egrc-upload .field select { width:100%; }
  .egrc-docs-empty { margin:.8rem 0 0; color:var(--c-muted); font-size:.8rem; }
  body.dark .egrc-hero { --egrc-soft:#193425; }
  body.dark .egrc-sign-state.signed { background:#173b27; color:#8cdda5; }
  body.dark .egrc-sign-state.pending { background:#463719; color:#f4cf75; }
  @media(max-width:900px) { .egrc-grid{grid-template-columns:1fr}.egrc-upload{grid-template-columns:1fr 1.5fr auto} }
  @media(max-width:600px) { .egrc-hero{display:block;padding:.9rem}.egrc-hero-meta{justify-content:flex-start;margin-top:.7rem}.egrc-facts{grid-template-columns:1fr 1fr}.egrc-card-body{padding:.8rem}.egrc-upload{grid-template-columns:1fr}.egrc-upload button{justify-self:start}.egrc-sign-actions .btn{flex:1} }
</style>
@endpush

<div class="egrc-page">
  <header class="egrc-hero">
    <div>
      <p class="egrc-eyebrow">Guest registration · {{ $stay->reservation->reservation_no }}</p>
      <h1>Registration &amp; e-signature</h1>
      <p>Review the stay details and capture or replace the guest’s signed registration record.</p>
    </div>
    <div class="egrc-hero-meta" aria-label="Stay status">
      <span class="egrc-chip">{{ $stay->room?->room_no ?? 'Room unassigned' }} · {{ $stay->roomType?->name ?? 'Room type not set' }}</span>
      @if($stay->is_day_use)<span class="egrc-chip">Day use · released {{ substr((string) $stay->day_use_until, 0, 5) }}</span>@endif
      <span class="egrc-chip">{{ strtoupper(str_replace('_', ' ', $stay->status)) }}</span>
      @if($stay->grc_signed_at)<span class="egrc-sign-state signed">Signed {{ $stay->grc_signed_at->format('d M Y · H:i') }}</span>@else<span class="egrc-sign-state pending">Signature pending</span>@endif
    </div>
  </header>

  <div class="egrc-grid">
    <div>
      <section class="egrc-card" aria-labelledby="egrcGuestTitle">
        <div class="egrc-card-head"><h2 id="egrcGuestTitle"><x-icon name="id"/> Guest identity &amp; contact</h2></div>
        <div class="egrc-card-body">
          <dl class="egrc-facts">
            <div class="egrc-fact wide"><dt>Guest</dt><dd>{{ $g->full_name ?: 'Not provided' }}</dd></div>
            <div class="egrc-fact"><dt>Mobile</dt><dd class="{{ $g->phone ? '' : 'empty' }}">{{ $g->phone ?: 'Not provided' }}</dd></div>
            <div class="egrc-fact"><dt>Email</dt><dd class="{{ $g->email ? '' : 'empty' }}">{{ $g->email ?: 'Not provided' }}</dd></div>
            <div class="egrc-fact wide"><dt>Address</dt><dd class="{{ $address ? '' : 'empty' }}">{{ $address ?: 'Not provided' }}</dd></div>
            <div class="egrc-fact"><dt>Nationality</dt><dd class="{{ $g->nationality ? '' : 'empty' }}">{{ $g->nationality ?: 'Not provided' }}</dd></div>
            <div class="egrc-fact"><dt>Date of birth</dt><dd class="{{ $g->dob ? '' : 'empty' }}">{{ $g->dob ? dmy($g->dob) : 'Not provided' }}</dd></div>
            <div class="egrc-fact"><dt>ID type</dt><dd class="{{ $g->id_type ? '' : 'empty' }}">{{ $g->id_type ? config('pms.id_types.'.$g->id_type, $g->id_type) : 'Not provided' }}</dd></div>
            <div class="egrc-fact"><dt>ID number</dt><dd class="{{ $g->id_number ? '' : 'empty' }}">{{ $g->id_number ?: 'Not provided' }}</dd></div>
            <div class="egrc-fact"><dt>Passport number</dt><dd class="{{ $g->passport_no ? '' : 'empty' }}">{{ $g->passport_no ?: 'Not provided' }}</dd></div>
            <div class="egrc-fact"><dt>Visa expiry</dt><dd class="{{ $g->visa_expiry ? '' : 'empty' }}">{{ $g->visa_expiry ? dmy($g->visa_expiry) : 'Not provided' }}</dd></div>
            <div class="egrc-fact"><dt>Guest TIN</dt><dd class="{{ $g->tax_number ? '' : 'empty' }}">{{ $g->tax_number ?: 'Not provided' }}</dd></div>
          </dl>
        </div>
      </section>

      <section class="egrc-card" aria-labelledby="egrcStayTitle">
        <div class="egrc-card-head"><h2 id="egrcStayTitle"><x-icon name="calendar"/> Reservation &amp; stay details</h2></div>
        <div class="egrc-card-body">
          <dl class="egrc-facts">
            <div class="egrc-fact"><dt>Reservation</dt><dd>{{ $stay->reservation->reservation_no }}</dd></div>
            <div class="egrc-fact"><dt>Room</dt><dd>{{ $stay->room?->room_no ?? 'Unassigned' }} · {{ $stay->roomType?->name ?? 'Room type not set' }}</dd></div>
            <div class="egrc-fact"><dt>Arrival</dt><dd>{{ $stay->checked_in_at?->format('d M Y · H:i') ?? $stay->arrival_date->format('d M Y') }}</dd></div>
            {{-- A day use sells hours: the guest must not sign that they took a night. --}}
            <div class="egrc-fact"><dt>{{ $stay->is_day_use ? 'Room released' : 'Departure' }}</dt><dd>{{ $stay->is_day_use ? substr((string) $stay->day_use_until, 0, 5) : $stay->departure_date->format('d M Y') }}</dd></div>
            <div class="egrc-fact"><dt>Length of stay</dt><dd>{{ $stay->is_day_use ? 'Day use · charged once' : $stay->arrival_date->diffInDays($stay->departure_date).' night(s)' }}</dd></div>
            <div class="egrc-fact"><dt>Party</dt><dd>{{ $stay->adults }} adult(s) · {{ $stay->children }} child(ren)</dd></div>
            <div class="egrc-fact"><dt>{{ $stay->is_day_use ? 'Day-use charge' : 'Room rate' }}</dt><dd>{{ money($stay->rate) }}{{ $stay->is_day_use ? '' : ' / night · '.$board }}</dd></div>
            <div class="egrc-fact"><dt>Company</dt><dd class="{{ $stay->reservation->company?->name ? '' : 'empty' }}">{{ $stay->reservation->company?->name ?? 'Not provided' }}</dd></div>
            <div class="egrc-fact wide"><dt>Purpose of visit</dt><dd class="{{ $stay->reservation->purpose ? '' : 'empty' }}">{{ $stay->reservation->purpose ?: 'Not provided' }}</dd></div>
          </dl>
          <h3 class="form-section">Guest declaration &amp; terms</h3>
          <div class="egrc-terms" id="egrcTerms">
            @if(setting('grc_terms'))<p>{!! nl2br(e(setting('grc_terms'))) !!}</p>@endif
            <p>I agree to abide by the hotel's terms and conditions. I am responsible for all charges incurred during my stay. The management is not responsible for valuables not deposited at the front desk. Check-out time is <b>{{ substr($currentHotel->checkout_time, 0, 5) }}</b>.</p>
          </div>
        </div>
      </section>
    </div>

    <div>
      <section class="egrc-card" aria-labelledby="egrcSignatureTitle">
        <div class="egrc-card-head">
          <h2 id="egrcSignatureTitle"><x-icon name="edit"/> Guest signature</h2>
          @if($stay->grc_signed_at)<span class="egrc-sign-state signed">Signed</span>@else<span class="egrc-sign-state pending">Not signed</span>@endif
        </div>
        <div class="egrc-card-body">
          @if($signatureAvailable && $canViewSignature)
            <div class="egrc-sign-existing">
              <div class="tiny muted" style="margin-bottom:.4rem">Current signature on file</div>
              <img src="{{ route('frontdesk.grc.signature', $stay) }}" alt="Saved guest signature">
              <a href="{{ route('frontdesk.grc.signature', $stay) }}" target="_blank" rel="noopener noreferrer">Open original signature image</a>
            </div>
            <p class="small muted">Draw again below to replace the saved signature.</p>
          @elseif($signatureAvailable)
            <p class="alert alert-info">A signature is on file. Your role cannot open its image preview.</p>
          @endif
          <form method="post" action="{{ route('frontdesk.grc.signature.store', $stay) }}" id="sigForm" aria-describedby="egrcTerms">@csrf
            <label class="lbl" for="sigPad">Sign in the box</label>
            <canvas id="sigPad" class="egrc-sign-pad" width="900" height="300" aria-label="Draw the guest signature here" aria-describedby="sigInstructions"></canvas>
            <p class="egrc-sign-help" id="sigInstructions">Use a finger, stylus or mouse. Clear the box to start again.</p>
            <input type="hidden" name="signature" id="sigData">
            <label class="egrc-accept"><input type="checkbox" name="accept" value="1" required> <span>I, {{ $g->full_name }}, confirm the details above and accept the guest terms.</span></label>
            <div class="egrc-sign-actions">
              <button type="button" class="btn" id="sigClear"><x-icon name="refresh" size="sm"/> Clear signature</button>
              <button class="btn btn-primary" id="sigSave" disabled><x-icon name="check" size="sm"/> Save signature</button>
            </div>
          </form>
        </div>
      </section>
    </div>
  </div>

  <section class="egrc-card" aria-labelledby="egrcDocumentsTitle">
    <div class="egrc-card-head">
      <h2 id="egrcDocumentsTitle"><x-icon name="id"/> Identity documents</h2>
      <span class="egrc-doc-head-note">Stored privately · document views are logged</span>
    </div>
    <div class="egrc-card-body">
      <form method="post" action="{{ route('guests.documents.store', $g) }}" enctype="multipart/form-data" class="egrc-upload">@csrf
        <input type="hidden" name="reservation_room_id" value="{{ $stay->id }}">
        <div class="field"><label for="egrcDocumentType">Document type</label><select id="egrcDocumentType" name="type">@foreach(\App\Models\GuestDocument::TYPES as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
        <div class="field"><label for="egrcDocumentFile">Scan or photo (JPG, PNG, WebP or PDF · max 5 MB)</label><input id="egrcDocumentFile" type="file" name="file" accept="image/jpeg,image/png,image/webp,application/pdf" capture="environment" required></div>
        <button class="btn btn-primary"><x-icon name="plus" size="sm"/> Upload document</button>
      </form>
      @include('guests._documents', ['docs' => $documents])
    </div>
  </section>
</div>
@endsection
@push('scripts')
<script>
(() => {
  const canvas = document.getElementById('sigPad');
  const form = document.getElementById('sigForm');
  const save = document.getElementById('sigSave');
  if (!canvas || !form || !save) return;
  const context = canvas.getContext('2d');
  if (!context) return;

  let drawing = false;
  let hasInk = false;
  let last = null;
  context.lineWidth = 3.2;
  context.lineCap = 'round';
  context.lineJoin = 'round';
  context.strokeStyle = '#17623b';
  context.fillStyle = '#17623b';

  const point = event => {
    const rect = canvas.getBoundingClientRect();
    return [(event.clientX - rect.left) * canvas.width / rect.width, (event.clientY - rect.top) * canvas.height / rect.height];
  };
  canvas.addEventListener('pointerdown', event => {
    event.preventDefault();
    drawing = true;
    hasInk = true;
    save.disabled = false;
    last = point(event);
    canvas.setPointerCapture(event.pointerId);
    context.beginPath();
    context.arc(last[0], last[1], 1.8, 0, Math.PI * 2);
    context.fill();
  });
  canvas.addEventListener('pointermove', event => {
    if (!drawing) return;
    event.preventDefault();
    const next = point(event);
    context.beginPath();
    context.moveTo(last[0], last[1]);
    context.lineTo(next[0], next[1]);
    context.stroke();
    last = next;
  });
  const stopDrawing = () => { drawing = false; last = null; };
  canvas.addEventListener('pointerup', stopDrawing);
  canvas.addEventListener('pointercancel', stopDrawing);
  canvas.addEventListener('lostpointercapture', stopDrawing);
  document.getElementById('sigClear').addEventListener('click', () => {
    context.clearRect(0, 0, canvas.width, canvas.height);
    hasInk = false;
    save.disabled = true;
  });
  form.addEventListener('submit', event => {
    if (!hasInk) {
      event.preventDefault();
      return;
    }
    document.getElementById('sigData').value = canvas.toDataURL('image/png');
  });
})();
</script>
@endpush
