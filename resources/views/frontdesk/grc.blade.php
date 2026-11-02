@extends('layouts.print')
@section('title', 'GRC '.$stay->reservation->reservation_no)
@section('doctitle', 'Guest Registration Card')
@section('pageclass', 'grc-sheet')
@section('docmeta')
  <div class="small grc-docmeta">
    <div>Reservation <b>{{ $stay->reservation->reservation_no }}</b></div>
    <div>Room <b>{{ $stay->room?->room_no ?? 'Unassigned' }}</b> · {{ $stay->roomType?->name ?? 'Room type not set' }}</div>
    <div>Registration <b>{{ strtoupper(str_replace('_', ' ', $stay->status)) }}</b></div>
    <div>Signature <b>{{ $stay->grc_signed_at ? 'On file' : 'Pending' }}</b></div>
  </div>
@endsection
@section('content')
@php
  $address = collect([$g->address, $g->city, $g->state, $g->country, $g->pincode])->filter(fn ($part) => filled($part))->implode(', ');
  $idType = $g->id_type ? config('pms.id_types.'.$g->id_type, $g->id_type) : null;
  $documents = $g->documents ?? collect();
  $signature = null;
  if ($stay->grc_signature_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($stay->grc_signature_path)) {
      $signature = base64_encode(\Illuminate\Support\Facades\Storage::disk('local')->get($stay->grc_signature_path));
  }
@endphp
@push('head')
<style>
  .grc-sheet { --grc-ink:#14251a; --grc-green:#17623b; color:#17231b; }
  .grc-document { border:1px solid #d8e0da; border-radius:12px; overflow:hidden; background:#fff; }
  .grc-title { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; padding:1rem 1.1rem; border-bottom:1px solid #d8e0da; background:linear-gradient(110deg,#eef8f0,#fff 74%); }
  .grc-title h2 { margin:0; color:var(--grc-green); font-size:1.15rem; letter-spacing:.015em; }
  .grc-title p { margin:.24rem 0 0; color:#53645a; font-size:.77rem; }
  .grc-stamp { display:inline-block; padding:.25rem .48rem; border:1px solid #bfd8c5; border-radius:999px; color:#17623b; font-size:.66rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; white-space:nowrap; }
  .grc-section { padding:.85rem 1.05rem; border-bottom:1px solid #e2e8e3; }
  .grc-section:last-child { border-bottom:0; }
  .grc-section h3 { margin:0 0 .6rem; color:#284d35; font-size:.78rem; letter-spacing:.07em; text-transform:uppercase; }
  .grc-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:0; border-top:1px solid #e7ece8; border-left:1px solid #e7ece8; }
  .grc-field { min-width:0; padding:.52rem .62rem; border-right:1px solid #e7ece8; border-bottom:1px solid #e7ece8; overflow-wrap:anywhere; }
  .grc-field.wide { grid-column:span 2; }
  .grc-field dt { margin:0 0 .12rem; color:#66746a; font-size:.62rem; font-weight:750; letter-spacing:.045em; text-transform:uppercase; }
  .grc-field dd { margin:0; color:var(--grc-ink); font-size:.78rem; line-height:1.35; font-weight:600; }
  .grc-field dd.empty { color:#7a837c; font-weight:450; }
  .grc-terms { color:#3f4c42; font-size:.72rem; line-height:1.55; }
  .grc-terms p { margin:.25rem 0 .55rem; }
  .grc-signatures { display:grid; grid-template-columns:1fr 1fr; gap:2rem; align-items:end; padding:.8rem 1.05rem 1rem; }
  .grc-signature { min-height:86px; display:flex; flex-direction:column; justify-content:flex-end; }
  .grc-signature img { max-width:240px; max-height:64px; object-fit:contain; object-position:left bottom; margin:0 0 .2rem; }
  .grc-signature-line { padding-top:.3rem; border-top:1px solid #38453b; color:#435046; font-size:.7rem; }
  .grc-signature-line small { display:block; margin-top:.1rem; color:#718076; }
  .grc-footer { display:flex; justify-content:space-between; gap:.6rem; padding:.5rem 1.05rem; border-top:1px solid #d8e0da; color:#738077; font-size:.64rem; }
  .grc-docmeta { line-height:1.55; }
  .grc-documents { margin:.3rem 0 0; padding-left:1rem; }
  @media(max-width:700px) { .grc-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.grc-title{padding:.8rem}.grc-section{padding:.7rem}.grc-field{padding:.45rem}.grc-signatures{gap:1rem;padding:.75rem} }
  @media print {
    .grc-document{border:1px solid #8e9990;border-radius:0;box-shadow:none}
    .grc-title{background:#f5f8f5!important;print-color-adjust:exact;-webkit-print-color-adjust:exact}
    .grc-section{break-inside:avoid;padding:.65rem .8rem}
    .grc-field{padding:.4rem .5rem}
    .grc-section h3{color:#1c3926}
    .grc-signatures{break-inside:avoid}
    .grc-stamp{print-color-adjust:exact;-webkit-print-color-adjust:exact}
  }
</style>
@endpush
<div class="grc-document" id="guestRegistrationCard">
  <header class="grc-title">
    <div>
      <h2>Guest registration details</h2>
      <p>Complete and retain this card with the guest’s registration record.</p>
    </div>
    <span class="grc-stamp">{{ $stay->grc_signed_at ? 'E-signed' : 'Signature required' }}</span>
  </header>

  <section class="grc-section" aria-labelledby="grcIdentityTitle">
    <h3 id="grcIdentityTitle">Guest identity &amp; contact</h3>
    <dl class="grc-grid">
      <div class="grc-field wide"><dt>Full name</dt><dd>{{ $g->full_name ?: '—' }}</dd></div>
      <div class="grc-field"><dt>Gender</dt><dd class="{{ $g->gender ? '' : 'empty' }}">{{ $g->gender ?: 'Not provided' }}</dd></div>
      <div class="grc-field"><dt>Date of birth</dt><dd class="{{ $g->dob ? '' : 'empty' }}">{{ $g->dob ? dmy($g->dob) : 'Not provided' }}</dd></div>
      <div class="grc-field"><dt>Mobile</dt><dd class="{{ $g->phone ? '' : 'empty' }}">{{ $g->phone ?: 'Not provided' }}</dd></div>
      <div class="grc-field"><dt>Email</dt><dd class="{{ $g->email ? '' : 'empty' }}">{{ $g->email ?: 'Not provided' }}</dd></div>
      <div class="grc-field wide"><dt>Address</dt><dd class="{{ $address ? '' : 'empty' }}">{{ $address ?: 'Not provided' }}</dd></div>
      <div class="grc-field"><dt>Nationality</dt><dd class="{{ $g->nationality ? '' : 'empty' }}">{{ $g->nationality ?: 'Not provided' }}</dd></div>
      <div class="grc-field"><dt>Guest TIN</dt><dd class="{{ $g->tax_number ? '' : 'empty' }}">{{ $g->tax_number ?: 'Not provided' }}</dd></div>
    </dl>
  </section>

  <section class="grc-section" aria-labelledby="grcIdentityDocumentTitle">
    <h3 id="grcIdentityDocumentTitle">Identity documents</h3>
    <dl class="grc-grid">
      <div class="grc-field"><dt>ID type</dt><dd class="{{ $idType ? '' : 'empty' }}">{{ $idType ?: 'Not provided' }}</dd></div>
      <div class="grc-field"><dt>ID number</dt><dd class="{{ $g->id_number ? '' : 'empty' }}">{{ $g->id_number ?: 'Not provided' }}</dd></div>
      <div class="grc-field"><dt>Passport number</dt><dd class="{{ $g->passport_no ? '' : 'empty' }}">{{ $g->passport_no ?: 'Not provided' }}</dd></div>
      <div class="grc-field"><dt>Visa expiry</dt><dd class="{{ $g->visa_expiry ? '' : 'empty' }}">{{ $g->visa_expiry ? dmy($g->visa_expiry) : 'Not provided' }}</dd></div>
    </dl>
    @if($documents->isNotEmpty())
      <div class="small mt-1"><b>Document types on file:</b> {{ $documents->map(fn ($document) => \App\Models\GuestDocument::TYPES[$document->type] ?? $document->type)->unique()->join(', ') }}</div>
    @endif
  </section>

  <section class="grc-section" aria-labelledby="grcStayTitle">
    <h3 id="grcStayTitle">Reservation &amp; stay</h3>
    <dl class="grc-grid">
      <div class="grc-field"><dt>Reservation</dt><dd>{{ $stay->reservation->reservation_no }}</dd></div>
      <div class="grc-field"><dt>Room</dt><dd>{{ $stay->room?->room_no ?? 'Unassigned' }} · {{ $stay->roomType?->name ?? 'Room type not set' }}</dd></div>
      <div class="grc-field"><dt>Arrival</dt><dd>{{ $stay->checked_in_at?->format('d M Y H:i') ?? $stay->arrival_date->format('d M Y') }}</dd></div>
      <div class="grc-field"><dt>Scheduled departure</dt><dd>{{ $stay->departure_date->format('d M Y') }}</dd></div>
      <div class="grc-field"><dt>Party</dt><dd>{{ $stay->adults }} adult(s) · {{ $stay->children }} child(ren)</dd></div>
      <div class="grc-field"><dt>Room rate</dt><dd>{{ money($stay->rate) }} / night · {{ $stay->ratePlan?->meal_plan ?? 'EP' }}</dd></div>
      <div class="grc-field"><dt>Company</dt><dd class="{{ $stay->reservation->company?->name ? '' : 'empty' }}">{{ $stay->reservation->company?->name ?? 'Not provided' }}</dd></div>
      <div class="grc-field"><dt>Company TIN</dt><dd class="{{ $stay->reservation->company?->tax_number ? '' : 'empty' }}">{{ $stay->reservation->company?->tax_number ?? 'Not provided' }}</dd></div>
      <div class="grc-field"><dt>Purpose of visit</dt><dd class="{{ $stay->reservation->purpose ? '' : 'empty' }}">{{ $stay->reservation->purpose ?: 'Not provided' }}</dd></div>
      <div class="grc-field"><dt>Booking source</dt><dd class="{{ $stay->reservation->source?->name ? '' : 'empty' }}">{{ $stay->reservation->source?->name ?? 'Not provided' }}</dd></div>
    </dl>
  </section>

  <section class="grc-section grc-terms" aria-labelledby="grcTermsTitle">
    <h3 id="grcTermsTitle">Guest declaration &amp; terms</h3>
    @if(setting('grc_terms'))<p>{!! nl2br(e(setting('grc_terms'))) !!}</p>@endif
    <p>I confirm that the registration details above are accurate, agree to abide by the hotel’s terms and conditions, and accept responsibility for charges incurred during my stay. The hotel is not responsible for valuables not deposited at the front desk. Check-out time is <b>{{ substr($currentHotel->checkout_time, 0, 5) }}</b>.</p>
  </section>

  <div class="grc-signatures">
    <div class="grc-signature">
      @if($signature)<img src="data:image/png;base64,{{ $signature }}" alt="Guest signature on file">@endif
      <div class="grc-signature-line">Guest signature
        @if($stay->grc_signed_at)<small>E-signed {{ $stay->grc_signed_at->format('d M Y H:i') }}</small>@endif
      </div>
    </div>
    <div class="grc-signature"><div class="grc-signature-line">Front-office representative</div></div>
  </div>
  <footer class="grc-footer"><span>Guest Registration Card · {{ $stay->reservation->reservation_no }}</span><span>Printed {{ now()->format('d M Y H:i') }}</span></footer>
</div>
@endsection
