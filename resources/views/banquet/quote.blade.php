@php
    $isContract = in_array($e->status, ['definite', 'completed'], true);
    $documentTitle = $isContract ? 'Banquet Contract' : 'Event Proposal';
    $statusLabel = \App\Models\BanquetEvent::STATUSES[$e->status] ?? ucfirst((string) $e->status);
    $statusTone = \App\Models\BanquetEvent::TONES[$e->status] ?? 'slate';
    $depositTarget = $e->deposit_due > 0
        ? $e->deposit_due
        : round($e->total() * (float) setting('banquet_deposit_pct', '25') / 100 / 100) * 100;
    $pol = collect(explode(',', setting('banquet_cancel_policy', \App\Services\BanquetSalesService::DEFAULTS['banquet_cancel_policy'])))
        ->map(fn ($t) => array_map('trim', explode(':', $t)))
        ->filter(fn ($p) => count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1]))
        ->sortByDesc(fn ($p) => (int) $p[0])->values();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $documentTitle }} · {{ $e->event_no }}</title>
  @include('banquet._docstyle')
</head>
<body class="banquet-document banquet-quote">
<div class="document-actions noprint"><button type="button" onclick="window.print()" aria-label="Print or save {{ strtolower($documentTitle) }} as PDF">Print / Save as PDF</button></div>
<main class="document">
  <header class="head quote-head">
    <div class="brand">
      <h1>{{ $hotel->name }}</h1>
      <div class="m">{{ $hotel->address ?? '' }}{{ $hotel->phone ? ' · '.$hotel->phone : '' }}{{ $hotel->email ? ' · '.$hotel->email : '' }}</div>
    </div>
    <div class="doc">
      <div class="m">{{ strtoupper($documentTitle) }}</div>
      <h2>{{ $e->event_no }}</h2>
      <span class="stamp status-{{ $statusTone }}">{{ $statusLabel }}</span>
      <div class="m">Issued {{ now()->format('d M Y') }}</div>
    </div>
  </header>

  <section class="quote-recipient" aria-label="Client and event details">
    <div>
      <span class="quote-field-label">Bill to</span>
      <h2>{{ $e->company?->name ?? $e->contact_name }}</h2>
      @if($e->company)<div class="m">Attention: {{ $e->contact_name }}</div>@endif
      @if($e->contact_email || $e->contact_phone)
        <div class="m">{{ $e->company ? 'Contact: ' : '' }}{{ $e->contact_email }}{{ $e->contact_email && $e->contact_phone ? ' · ' : '' }}{{ $e->contact_phone }}</div>
      @endif
    </div>
    <div>
      <span class="quote-field-label">Event</span>
      <div class="quote-event-title">{{ $e->title }}</div>
      <div class="m">{{ \App\Models\BanquetEvent::TYPES[$e->event_type] ?? $e->event_type }}</div>
    </div>
  </section>

  <p class="quote-message">
    @if($isContract)
      This contract confirms the arrangements for <b>{{ $e->title }}</b>. Please review the function details, charges and terms below.
    @else
      Thank you for considering {{ $hotel->name }} for <b>{{ $e->title }}</b>. We are pleased to propose the following arrangements.
    @endif
  </p>
  @if($e->status === 'tentative' && $e->option_date)
    <div class="quote-option"><strong>Venue option:</strong> held for you until {{ $e->option_date->format('l d F Y') }}.</div>
  @endif

  <p class="quote-table-hint">On smaller screens, scroll horizontally to see all charge columns.</p>
  <div class="quote-table-wrap">
    <table class="quote-table" aria-label="Function details and charges">
      <caption class="sr-only">Function details, guest counts and estimated charges for {{ $e->event_no }}</caption>
      <thead><tr><th scope="col">Function</th><th scope="col">Venue &amp; set-up</th><th scope="col" class="r">Billable guests</th><th scope="col">Description</th><th scope="col" class="r">Amount · {{ $hotel->currency_symbol ?? 'GH₵' }}</th></tr></thead>
      <tbody>
      @forelse($e->functions as $f)
        @php
          $rows = collect();
          if ($f->rate_per_pax > 0) $rows->push([$f->package?->name ?? 'Food & beverage', $f->billablePax().' × '.money($f->rate_per_pax, false), $f->fnbAmount()]);
          if ($f->barAmount() > 0) $rows->push([$f->barPackage?->name ?? 'Bar', $f->billablePax().' × '.money($f->bar_rate_per_pax, false), $f->barAmount()]);
          $rows->push(['Venue rental', $f->hours().' hours', (float) $f->hall_rate]);
          foreach ($f->items as $i) $rows->push([$i->description, rtrim(rtrim(number_format($i->qty, 2), '0'), '.').' × '.money($i->unit_price, false), $i->amount()]);
        @endphp
        @foreach($rows as $k => [$description, $calculation, $amount])
          <tr>
            @if($k === 0)
              <td rowspan="{{ $rows->count() }}"><span class="quote-function-name">{{ $f->name }}</span><span class="quote-function-meta note">{{ $f->date->format('D d M Y') }} · {{ $f->timeRange() }}</span></td>
              <td rowspan="{{ $rows->count() }}">{{ $f->hall->name }}<span class="quote-setup">{{ \App\Models\BanquetFunction::SETUPS[$f->setup_style] ?? $f->setup_style }}</span></td>
              <td rowspan="{{ $rows->count() }}" class="r"><span class="quote-pax">{{ $f->billablePax() }}</span><span class="quote-pax-note">billable pax</span></td>
            @endif
            <td>{{ $description }} <span class="note">{{ $calculation }}</span></td>
            <td class="r">{{ money($amount, false) }}</td>
          </tr>
        @endforeach
        @if($f->package && $f->package->itemList())
          <tr class="quote-menu-row"><td colspan="2"></td><td></td><td colspan="2"><span class="quote-menu-label">Menu:</span> {{ implode(' · ', $f->package->itemList()) }}</td></tr>
        @endif
        @if($f->barPackage && $f->barPackage->itemList())
          <tr class="quote-menu-row"><td colspan="2"></td><td></td><td colspan="2"><span class="quote-menu-label">Bar:</span> {{ implode(' · ', $f->barPackage->itemList()) }}</td></tr>
        @endif
      @empty
        <tr><td colspan="5" class="muted">No function details have been added to this event.</td></tr>
      @endforelse
      </tbody>
      <tfoot>
        <tr><td colspan="4" class="r quote-summary-label">Sub-total</td><td class="r">{{ money($e->net(), false) }}</td></tr>
        <tr><td colspan="4" class="r quote-summary-label">Taxes</td><td class="r">{{ money($e->tax(), false) }}</td></tr>
        <tr class="quote-total-row"><td colspan="4" class="r">Estimated total</td><td class="r" id="quoteTotal">{{ money($e->total()) }}</td></tr>
      </tfoot>
    </table>
  </div>
  <p class="quote-pricing-note">Food &amp; beverage charges use the higher of the guaranteed count (or expected count if no guarantee is set) and actual attendance.</p>

  <section class="quote-support" aria-label="Payment and cancellation terms">
    <div class="quote-support-card">
      <span>{{ $isContract ? 'Deposit requirement' : 'Deposit to confirm' }}</span>
      <p><strong>{{ money($depositTarget) }}</strong>@if($e->deposit_due_date) <span class="note">due by {{ $e->deposit_due_date->format('d M Y') }}</span>@endif</p>
      @if($deposits > 0)<p class="note" style="margin-top:4px">Received so far: <b>{{ money($deposits) }}</b></p>@endif
    </div>
    <div class="quote-support-card">
      <span>Cancellation charges · % of estimated value</span>
      <p>
        @forelse($pol as $k => $p)
          {{ $k === 0 ? $p[0].'+ days' : $p[0].'–'.((int) $pol[$k - 1][0] - 1).' days' }}: <b>{{ $p[1] }}%</b>@if(! $loop->last) · @endif
        @empty
          <span class="note">Not configured</span>
        @endforelse
      </p>
    </div>
  </section>

  <section class="quote-terms pre" aria-label="Terms and conditions"><span class="lbl">Terms &amp; conditions</span>{{ $terms }}</section>

  <section class="sig quote-signatures" aria-label="Contract signatures">
    <div>
      <strong>For {{ $hotel->name }}</strong>
      <span class="quote-signing-space" aria-hidden="true"></span>
      <span class="quote-signatory">{{ $e->salesUser?->name }}</span>
      <span class="quote-signature-meta">Authorised signature and date</span>
    </div>
    <div>
      <strong>Accepted by client</strong>
      <span class="quote-signing-space" aria-hidden="true"></span>
      <span class="quote-signatory">Name</span>
      <span class="quote-signature-meta">Signature and date</span>
    </div>
  </section>
</main>
</body>
</html>
