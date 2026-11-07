@extends('layouts.print')
@section('title', 'GRC '.$stay->reservation->reservation_no)
@section('doctitle', 'Guest Registration Card')
@section('pageclass', 'grc-sheet')
@section('docmeta')
  <div class="small grc-docmeta">
    <div>Card <b>GRC-{{ $stay->reservation->reservation_no }}-{{ $stay->id }}</b></div>
    <div>Reservation <b>{{ $stay->reservation->reservation_no }}</b> · Room <b>{{ $stay->room?->room_no ?? 'Unassigned' }}</b></div>
    <div>{{ $stay->grc_signed_at ? 'E-signed '.$stay->grc_signed_at->format('d M Y H:i') : 'Guest signature pending' }}</div>
  </div>
@endsection
@section('content')
@php
  $isDayUse = (bool) $stay->is_day_use;
  $idType = $g->id_type ? config('pms.id_types.'.$g->id_type, $g->id_type) : null;
  $nights = $isDayUse ? 0 : max(1, (int) $stay->arrival_date->diffInDays($stay->departure_date));
  $released = $isDayUse ? substr((string) $stay->day_use_until, 0, 5) : null;
  $party = collect([$stay->adults.' adult'.($stay->adults === 1 ? '' : 's'), $stay->children ? $stay->children.' child'.($stay->children === 1 ? '' : 'ren') : null])->filter()->implode(' · ');
  $meals = $stay->ratePlan?->meal_plan ?? 'EP';
  $mealLabels = ['EP' => 'Room only', 'CP' => 'Bed & breakfast', 'MAP' => 'Half board', 'AP' => 'Full board'];
@endphp
@push('head')
<style>
  .grc-sheet { --grc-ink:#14231a; --grc-quiet:#5c6b61; --grc-line:#d7e0da; --grc-line-soft:#e8eeea; --grc-green:#14532d; --grc-soft:#eef6f0; }
  .grc-docmeta { line-height:1.6; }
  .grc { border:1px solid var(--grc-line); border-radius:12px; overflow:hidden; background:#fff; color:var(--grc-ink); font-variant-numeric:tabular-nums; }
  .grc-figure { margin:0; }

  /* the band that tells the desk what this card is, at a glance */
  .grc-band { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; padding:.85rem 1.05rem; border-bottom:2px solid var(--grc-green); background:linear-gradient(105deg,var(--grc-soft),#fff 78%); }
  .grc-band h2 { margin:0; font-size:1.02rem; letter-spacing:.08em; text-transform:uppercase; color:var(--grc-green); }
  .grc-band p { margin:.2rem 0 0; color:var(--grc-quiet); font-size:.72rem; }
  .grc-ref { font-weight:700; color:#33443a; letter-spacing:.02em; }
  .grc-chips { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:.3rem; }
  .grc-chip { display:inline-block; padding:.22rem .5rem; border:1px solid var(--grc-line); border-radius:999px; background:#fff; color:#3c4c42; font-size:.62rem; font-weight:800; letter-spacing:.06em; text-transform:uppercase; white-space:nowrap; }
  .grc-chip.status { border-color:#c6dfcd; background:var(--grc-soft); color:#14532d; }
  .grc-chip.pending { border-color:#e6d3ae; background:#fdf6e9; color:#8a5a13; }
  .grc-chip.day { border-color:#bfc9f0; background:#eef1fe; color:#3730a3; }

  /* the four facts the desk reads first */
  .grc-glance { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); border-bottom:1px solid var(--grc-line); }
  .grc-glance > div { min-width:0; padding:.6rem 1.05rem .65rem; border-right:1px solid var(--grc-line-soft); }
  .grc-glance > div:last-child { border-right:0; }
  .grc-glance span { display:block; color:var(--grc-quiet); font-size:.6rem; font-weight:750; letter-spacing:.08em; text-transform:uppercase; }
  .grc-glance b { display:block; margin-top:.12rem; color:var(--grc-ink); font-size:.9rem; font-weight:800; letter-spacing:-.01em; overflow-wrap:anywhere; }
  .grc-glance small { display:block; margin-top:.1rem; color:var(--grc-quiet); font-size:.68rem; }

  /* numbered blocks, so a clerk can be told "section 3" over the phone */
  .grc-block { padding:.7rem 1.05rem .75rem; border-bottom:1px solid var(--grc-line); }
  .grc-block:last-of-type { border-bottom:0; }
  .grc-block > h3 { display:flex; align-items:center; gap:.42rem; margin:0 0 .5rem; color:#26402f; font-size:.68rem; font-weight:800; letter-spacing:.09em; text-transform:uppercase; }
  .grc-block > h3 i { display:grid; place-items:center; width:1.05rem; height:1.05rem; border-radius:50%; background:var(--grc-green); color:#fff; font-size:.58rem; font-style:normal; letter-spacing:0; }
  .grc-block > h3 em { margin-left:auto; color:var(--grc-quiet); font-size:.6rem; font-style:normal; font-weight:600; letter-spacing:.02em; text-transform:none; }

  .grc-fields { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:0; margin:0; border-top:1px solid var(--grc-line-soft); border-left:1px solid var(--grc-line-soft); }
  .grc-f { min-width:0; padding:.42rem .55rem; border-right:1px solid var(--grc-line-soft); border-bottom:1px solid var(--grc-line-soft); }
  .grc-f.wide { grid-column:span 2; }
  .grc-f.full { grid-column:1 / -1; }
  .grc-f dt { margin:0 0 .1rem; color:var(--grc-quiet); font-size:.58rem; font-weight:800; letter-spacing:.07em; text-transform:uppercase; }
  .grc-f dd { margin:0; color:var(--grc-ink); font-size:.79rem; font-weight:650; line-height:1.35; overflow-wrap:anywhere; }
  .grc-f.name dd { font-size:.95rem; font-weight:800; letter-spacing:-.01em; }
  .grc-f.money dd { font-weight:800; }
  .grc-f dd.blank { min-height:.95rem; border-bottom:1px dotted #a9b7ad; }
  .grc-note { margin:.45rem 0 0; color:var(--grc-quiet); font-size:.68rem; }

  /* the declaration, kept short enough to be read before signing */
  .grc-declare { color:#3b4a40; font-size:.71rem; line-height:1.55; }
  .grc-declare p { margin:0 0 .45rem; }
  .grc-declare ul { display:grid; grid-template-columns:1fr 1fr; gap:.15rem 1.2rem; margin:.35rem 0 0; padding:0; list-style:none; }
  .grc-declare li { position:relative; padding-left:.85rem; }
  .grc-declare li::before { content:""; position:absolute; left:0; top:.42rem; width:.32rem; height:.32rem; border:1px solid var(--grc-green); border-radius:50%; }

  /* the part the guest actually signs */
  .grc-sign { display:grid; grid-template-columns:1.15fr 1fr; gap:1.6rem; padding:.75rem 1.05rem .85rem; border-bottom:1px solid var(--grc-line); background:#fcfdfc; }
  .grc-sign-guest { display:flex; flex-direction:column; justify-content:flex-end; min-height:78px; }
  .grc-sign-guest img { max-width:230px; max-height:58px; margin:0 0 .15rem; object-fit:contain; object-position:left bottom; }
  .grc-sign-guest .unsigned { margin:.1rem 0 .3rem; color:var(--grc-quiet); font-size:.66rem; font-style:italic; }
  .grc-sign-line { padding-top:.3rem; border-top:1px solid #33443a; color:#33443a; font-size:.68rem; font-weight:700; }
  .grc-sign-line small { display:block; margin-top:.12rem; color:var(--grc-quiet); font-size:.62rem; font-weight:500; }
  .grc-office { padding:.55rem 1.05rem .65rem; background:var(--grc-soft); border-bottom:1px solid var(--grc-line); }
  .grc-office h4 { margin:0 0 .35rem; color:#2b4636; font-size:.6rem; font-weight:800; letter-spacing:.09em; text-transform:uppercase; }
  .grc-office-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.35rem .9rem; }
  .grc-tick { display:flex; align-items:center; gap:.35rem; color:#3b4a40; font-size:.66rem; }
  .grc-tick::before { content:""; width:.62rem; height:.62rem; border:1px solid #7d8d82; border-radius:2px; background:#fff; flex:none; }
  .grc-tick.line { border-bottom:1px solid #a9b7ad; padding-bottom:.12rem; }
  .grc-footer { display:flex; justify-content:space-between; gap:.75rem; padding:.42rem 1.05rem; color:#6d7c72; font-size:.6rem; letter-spacing:.02em; }

  @media(max-width:760px) {
    .grc-glance { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .grc-glance > div:nth-child(2) { border-right:0; }
    .grc-fields { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .grc-sign { grid-template-columns:1fr; gap:.9rem; }
    .grc-office-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .grc-declare ul { grid-template-columns:1fr; }
  }
  @media print {
    @page { size:A4 portrait; margin:11mm; }
    .grc { border:1px solid #8d998f; border-radius:0; }
    .grc-band, .grc-chip, .grc-glance b, .grc-office, .grc-block > h3 i { print-color-adjust:exact; -webkit-print-color-adjust:exact; }
    .grc-band { padding:.6rem .8rem; }
    .grc-glance > div { padding:.45rem .8rem .5rem; }
    .grc-block { padding:.5rem .8rem .55rem; break-inside:avoid; }
    .grc-f { padding:.33rem .45rem; }
    .grc-f dd { font-size:.75rem; }
    .grc-sign { padding:.55rem .8rem .6rem; break-inside:avoid; }
    .grc-office, .grc-footer { padding-left:.8rem; padding-right:.8rem; }
  }
</style>
@endpush
<article class="grc" id="guestRegistrationCard" aria-labelledby="grcHeading">
  <header class="grc-band">
    <div>
      <h2 id="grcHeading">Guest registration</h2>
      <p>
        <span class="grc-ref">GRC-{{ $stay->reservation->reservation_no }}-{{ $stay->id }}</span>
        · {{ $isDayUse ? 'Day use' : $nights.' night'.($nights === 1 ? '' : 's') }}
        · {{ $currentHotel->name }}
      </p>
    </div>
    <div class="grc-chips">
      @if($isDayUse)<span class="grc-chip day">Day use</span>@endif
      <span class="grc-chip status">{{ strtoupper(str_replace('_', ' ', $stay->status)) }}</span>
      @if($stay->grc_signed_at)
        <span class="grc-chip status">E-signed</span>
      @else
        <span class="grc-chip pending">Signature required</span>
      @endif
    </div>
  </header>

  <div class="grc-glance">
    <div>
      <span>Guest</span>
      <b>{{ $g->full_name ?: '—' }}</b>
      <small>{{ collect([$g->nationality, $idType])->filter()->implode(' · ') ?: 'Nationality not recorded' }}</small>
    </div>
    <div>
      <span>Room</span>
      <b>{{ $stay->room?->room_no ?? 'Unassigned' }}</b>
      <small>{{ $stay->roomType?->name ?? 'Room type not set' }}</small>
    </div>
    <div>
      <span>{{ $isDayUse ? 'Today' : 'Stay' }}</span>
      <b>{{ $isDayUse ? 'Day use' : $stay->arrival_date->format('d M').' → '.$stay->departure_date->format('d M Y') }}</b>
      <small>{{ $isDayUse ? 'Room released '.$released : $party }}</small>
    </div>
    <div>
      <span>{{ $isDayUse ? 'Day-use charge' : 'Rate / night' }}</span>
      <b>{{ money($stay->rate) }}</b>
      <small>{{ $isDayUse ? 'Charged once · no night sold' : ($mealLabels[$meals] ?? $meals) }}</small>
    </div>
  </div>

  <section class="grc-block" aria-labelledby="grcGuestTitle">
    <h3 id="grcGuestTitle"><i>1</i> Guest particulars <em>{{ $g->guest_no ?? 'Guest profile' }}</em></h3>
    <dl class="grc-fields">
      <div class="grc-f wide name"><dt>Full name</dt><dd class="{{ $g->full_name ? '' : 'blank' }}">{{ $g->full_name }}</dd></div>
      <div class="grc-f"><dt>Gender</dt><dd class="{{ $g->gender ? '' : 'blank' }}">{{ $g->gender }}</dd></div>
      <div class="grc-f"><dt>Date of birth</dt><dd class="{{ $g->dob ? '' : 'blank' }}">{{ $g->dob ? dmy($g->dob) : '' }}</dd></div>
      <div class="grc-f"><dt>Nationality</dt><dd class="{{ $g->nationality ? '' : 'blank' }}">{{ $g->nationality }}</dd></div>
      <div class="grc-f"><dt>Mobile</dt><dd class="{{ $g->phone ? '' : 'blank' }}">{{ $g->phone }}</dd></div>
      <div class="grc-f"><dt>Email</dt><dd class="{{ $g->email ? '' : 'blank' }}">{{ $g->email }}</dd></div>
      <div class="grc-f"><dt>Guest TIN</dt><dd class="{{ $g->tax_number ? '' : 'blank' }}">{{ $g->tax_number }}</dd></div>
      <div class="grc-f wide"><dt>Residential address</dt><dd class="{{ ($g->address || $g->city) ? '' : 'blank' }}">{{ $g->address }}@if($g->address && $g->city), @endif{{ $g->city }}@if($g->state), {{ $g->state }}@endif</dd></div>
      <div class="grc-f wide"><dt>Country / postal code</dt><dd class="{{ ($g->country || $g->pincode) ? '' : 'blank' }}">{{ $g->country }}@if($g->country && $g->pincode) · @endif{{ $g->pincode }}</dd></div>
    </dl>
  </section>

  <section class="grc-block" aria-labelledby="grcIdTitle">
    <h3 id="grcIdTitle"><i>2</i> Identity document <em>{{ count($documentTypes) ? count($documentTypes).' scan(s) on file' : 'No scan on file' }}</em></h3>
    <dl class="grc-fields">
      <div class="grc-f"><dt>Document type</dt><dd class="{{ $idType ? '' : 'blank' }}">{{ $idType }}</dd></div>
      <div class="grc-f"><dt>Document number</dt><dd class="{{ $g->id_number ? '' : 'blank' }}">{{ $g->id_number }}</dd></div>
      <div class="grc-f"><dt>Passport number</dt><dd class="{{ $g->passport_no ? '' : 'blank' }}">{{ $g->passport_no }}</dd></div>
      <div class="grc-f"><dt>Visa expiry</dt><dd class="{{ $g->visa_expiry ? '' : 'blank' }}">{{ $g->visa_expiry ? dmy($g->visa_expiry) : '' }}</dd></div>
    </dl>
    @if(count($documentTypes))
      <p class="grc-note"><b>Scans held:</b> {{ implode(', ', $documentTypes) }}</p>
    @endif
  </section>

  <section class="grc-block" aria-labelledby="grcStayTitle">
    <h3 id="grcStayTitle"><i>3</i> Stay, room and charges <em>{{ $stay->reservation->reservation_no }}</em></h3>
    <dl class="grc-fields">
      <div class="grc-f"><dt>Arrival</dt><dd>{{ $stay->checked_in_at?->format('d M Y H:i') ?? $stay->arrival_date->format('d M Y') }}</dd></div>
      <div class="grc-f"><dt>{{ $isDayUse ? 'Room released' : 'Scheduled departure' }}</dt><dd>{{ $isDayUse ? $released : $stay->departure_date->format('d M Y') }}</dd></div>
      <div class="grc-f"><dt>{{ $isDayUse ? 'Sold as' : 'Nights' }}</dt><dd>{{ $isDayUse ? 'Day use · charged once' : $nights }}</dd></div>
      <div class="grc-f"><dt>Party</dt><dd>{{ $party }}</dd></div>
      <div class="grc-f money"><dt>{{ $isDayUse ? 'Day-use charge' : 'Room rate' }}</dt><dd>{{ money($stay->rate) }}{{ $isDayUse ? '' : ' / night' }}</dd></div>
      <div class="grc-f"><dt>Board</dt><dd>{{ $mealLabels[$meals] ?? $meals }}</dd></div>
      <div class="grc-f"><dt>Rate plan</dt><dd class="{{ $stay->ratePlan?->name ? '' : 'blank' }}">{{ $stay->ratePlan?->name }}</dd></div>
      <div class="grc-f"><dt>Booking source</dt><dd class="{{ $stay->reservation->source?->name ? '' : 'blank' }}">{{ $stay->reservation->source?->name }}</dd></div>
      <div class="grc-f wide"><dt>Company / account</dt><dd class="{{ $stay->reservation->company?->name ? '' : 'blank' }}">{{ $stay->reservation->company?->name }}</dd></div>
      <div class="grc-f wide"><dt>Company TIN</dt><dd class="{{ $stay->reservation->company?->tax_number ? '' : 'blank' }}">{{ $stay->reservation->company?->tax_number }}</dd></div>
      <div class="grc-f wide"><dt>Purpose of visit</dt><dd class="{{ $stay->reservation->purpose ? '' : 'blank' }}">{{ $stay->reservation->purpose }}</dd></div>
      <div class="grc-f wide"><dt>Special request on file</dt><dd class="{{ $stay->reservation->special_request ? '' : 'blank' }}">{{ $stay->reservation->special_request }}</dd></div>
    </dl>
  </section>

  <section class="grc-block grc-declare" aria-labelledby="grcTermsTitle">
    <h3 id="grcTermsTitle"><i>4</i> Guest declaration <em>Read before signing</em></h3>
    @if(setting('grc_terms'))<p>{!! nl2br(e(setting('grc_terms'))) !!}</p>@endif
    <p>I confirm that the particulars above are correct, that I am the person named in the identity document presented, and that I agree to the hotel's terms of residence and to pay all charges incurred on this account.</p>
    <ul>
      <li>Check-out time is <b>{{ substr($currentHotel->checkout_time, 0, 5) }}</b> unless a late departure is agreed in writing.</li>
      <li>Valuables are only covered when deposited in the safe at the front desk.</li>
      <li>The room is let for the persons named here; guests beyond that must be registered.</li>
      <li>Smoking, damage or missing items are charged to this account at the hotel's rates.</li>
    </ul>
  </section>

  <div class="grc-sign">
    <div class="grc-sign-guest">
      @if($signature)
        <img src="{{ $signature }}" alt="Guest signature">
      @else
        <p class="unsigned">Signed at the desk on arrival.</p>
      @endif
      <div class="grc-sign-line">Guest signature
        <small>{{ $stay->grc_signed_at ? 'E-signed '.$stay->grc_signed_at->format('d M Y H:i') : 'Name: '.($g->full_name ?: '') }} · Date: {{ $stay->grc_signed_at?->format('d M Y') ?? now()->format('d M Y') }}</small>
      </div>
    </div>
    <div class="grc-sign-guest">
      <div class="grc-sign-line">Front-office representative
        <small>Name and signature of the clerk taking this registration</small>
      </div>
    </div>
  </div>

  <div class="grc-office">
    <h4>For office use</h4>
    <div class="grc-office-grid">
      <span class="grc-tick">Identity document seen</span>
      <span class="grc-tick">Key card issued</span>
      <span class="grc-tick line">Checked in by</span>
      <span class="grc-tick line">Time</span>
    </div>
  </div>

  <footer class="grc-footer">
    <span>Guest Registration Card · GRC-{{ $stay->reservation->reservation_no }}-{{ $stay->id }}</span>
    <span>Printed {{ now()->format('d M Y H:i') }}</span>
  </footer>
</article>
@endsection
