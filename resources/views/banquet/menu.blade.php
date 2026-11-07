<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $hotel->name }} · Kitchen menu · {{ $e->event_no }}</title>@include('banquet._docstyle')
<style>
.menu-back{display:inline-flex;align-items:center;justify-content:center;border:1px solid #c5d1c9;border-radius:8px;padding:9px 13px;background:#fff;color:#20372b;font:inherit;font-weight:700;text-decoration:none}.menu-back:hover{background:var(--doc-soft)}
.menu-banner{margin:12px 0 0;padding:9px 12px;border:1px solid #efcd82;border-radius:8px;background:var(--doc-warn-soft);color:#70420c;font-weight:650;font-size:12px}
.menu-banner.is-dead{border-color:#e4b1ad;background:#fceceb;color:#8d312a}
.menu-covers{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:7px;margin:8px 0 9px}
.menu-cover{padding:6px 9px;border:1px solid var(--doc-line);border-radius:8px;background:#fbfdfb;min-width:0}
.menu-cover span{display:block;font-size:9px;text-transform:uppercase;letter-spacing:.055em;color:var(--doc-muted);font-weight:750}
.menu-cover b{display:block;font-size:18px;line-height:1.15;font-variant-numeric:tabular-nums}
.menu-cover b.menu-setup{font-size:12px;padding-top:3px;line-height:1.2}
.menu-cover em{display:block;font-style:normal;font-size:9px;color:var(--doc-muted)}
.menu-cover.is-cook{border-color:#9dc9a9;background:var(--doc-accent-soft)}
.menu-cover.is-cook b{color:#145b37}
.menu-grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:10px}
.menu-card{padding:9px 11px;border:1px solid var(--doc-line);border-radius:9px;background:#fff;min-width:0;break-inside:avoid-page;page-break-inside:avoid}
.menu-card.menu-wide{grid-column:1/-1}
.menu-package{display:flex;align-items:baseline;flex-wrap:wrap;gap:6px}
.menu-package b{font-size:13px}
.menu-type{display:inline-block;padding:1px 7px;border:1px solid #cbd3d8;border-radius:999px;background:#f4f7f5;color:#46535b;font-size:9px;font-weight:750;text-transform:uppercase;letter-spacing:.045em}
.menu-dishes{margin:4px 0 0;padding-left:19px;font-size:12px;line-height:1.6}
.menu-dishes li{padding-left:1px}
.menu-dishes li::marker{color:var(--doc-muted);font-variant-numeric:tabular-nums;font-weight:700}
.menu-empty{margin:5px 0 0;padding:7px 9px;border:1px dashed #c8d3cc;border-radius:7px;background:#fafcfb;color:var(--doc-muted);font-size:11.5px}
.menu-diet{width:100%;margin-top:5px}
.menu-diet th,.menu-diet td{padding:3px 5px;font-size:10.5px;border-bottom:1px solid #eaeff0}
.menu-diet th{background:transparent;border-bottom:1px solid var(--doc-line)}
.menu-diet td.r{width:58px}
.menu-fill{display:inline-block;min-width:34px;border-bottom:1px solid #a3aea7}
.menu-fill-wide{min-width:80%}
.menu-received{display:flex;flex-wrap:wrap;justify-content:space-between;gap:6px 14px;margin-top:9px;padding-top:6px;border-top:1px dashed #c8d3cc;color:var(--doc-muted);font-size:10px}
.menu-received span{white-space:nowrap}
.menu-foot{display:flex;flex-wrap:wrap;justify-content:space-between;gap:6px 16px;margin-top:14px;padding-top:7px;border-top:1px solid var(--doc-line);color:var(--doc-muted);font-size:10px}
@media print{.menu-fn{break-after:page;page-break-after:always}.menu-fn:last-of-type{break-after:auto;page-break-after:auto}.menu-banner{break-inside:avoid-page}.menu-cards{break-inside:auto}}
@media(max-width:700px){.menu-covers{grid-template-columns:repeat(2,minmax(0,1fr))}.menu-grid{grid-template-columns:1fr}.menu-received{font-size:9.5px}}
</style></head><body>
<div class="document-actions noprint">
  <a class="menu-back" href="{{ route('banquet.show', $e) }}">Back to the event</a>
  <button type="button" onclick="window.print()">Print menu sheet</button>
</div>

<div class="head">
  <div><h1>{{ $hotel->name }}</h1><div class="m">{{ $hotel->address ?? '' }}</div><div class="m">Kitchen &amp; banquet production</div></div>
  <div class="doc"><div class="m">KITCHEN MENU SHEET</div><h2>{{ $e->event_no }}</h2><div class="stamp status-{{ $e->status }}">{{ strtoupper(\App\Models\BanquetEvent::STATUSES[$e->status] ?? $e->status) }}</div></div>
</div>

@if(in_array($e->status, ['cancelled', 'lost'], true))
  <div class="menu-banner is-dead">This event is {{ strtolower(\App\Models\BanquetEvent::STATUSES[$e->status] ?? $e->status) }} — nothing is to be prepared or ordered from this sheet.</div>
@elseif($e->status === 'tentative')
  <div class="menu-banner">Tentative hold — the date is not confirmed. Cook nothing from this sheet until the event is definite{{ $e->option_date ? ' (option expires '.$e->option_date->format('d M Y').')' : '' }}.</div>
@elseif($e->status === 'enquiry')
  <div class="menu-banner">Enquiry only — no commitment. This menu is for costing and kitchen feasibility, not for production.</div>
@endif

<div class="kv">
  <div style="grid-column:span 2"><span>Event</span><b>{{ $e->title ?: 'Untitled event' }}</b> · {{ \App\Models\BanquetEvent::TYPES[$e->event_type] ?? $e->event_type }}</div>
  <div><span>Date(s)</span>{{ $e->event_date->format('D d M Y') }}{{ $e->end_date && ! $e->end_date->eq($e->event_date) ? ' – '.$e->end_date->format('D d M') : '' }}</div>
  <div><span>Functions</span>{{ count($fns) }} on this sheet · {{ number_format($cookTotal ?? 0) }} covers to cook</div>
  <div><span>Host on the night</span>{{ $e->contact_name ?: 'Not recorded' }}</div>
  <div><span>Contact number</span>{{ $e->contact_phone ?: 'Not recorded' }}</div>
  <div><span>Covers expected</span>{{ number_format($expectedTotal ?? 0) }}</div>
  <div><span>Issued</span>{{ now()->format('d M Y H:i') }}</div>
</div>

<section class="menu-cards">
  @foreach($fns as $f)
    @php
      $pax = $f->billablePax();
      $cookFor = $pax > 0 ? (int) ceil($pax * 1.05) : 0;
      $pkg = $f->package;
      $dishes = $pkg ? $pkg->itemList() : [];
    @endphp
    <section class="fn menu-fn" aria-labelledby="menuFn{{ $f->id }}">
      <h3><span>{{ $f->name ?: 'Function' }} — {{ $f->hall->name }}</span><span>{{ $f->date->format('D d M Y') }} · {{ $f->timeRange() }}</span></h3>
      <div class="body">
        <div class="menu-covers">
          <div class="menu-cover"><span>Expected</span><b>{{ number_format((int) ($f->expected_pax ?? 0)) }}</b><em>guests booked</em></div>
          <div class="menu-cover"><span>Guaranteed</span><b>{{ $f->guaranteed_pax !== null ? number_format((int) $f->guaranteed_pax) : 'TBC' }}</b><em>{{ $f->guaranteed_pax !== null ? 'final count' : 'closes '.$f->guaranteeCutoff()->format('d M') }}</em></div>
          @if($f->actual_pax !== null)
            <div class="menu-cover"><span>Actual</span><b>{{ number_format((int) $f->actual_pax) }}</b><em>turned up</em></div>
          @else
            <div class="menu-cover"><span>Set-up</span><b class="menu-setup">{{ \App\Models\BanquetFunction::SETUPS[$f->setup_style] ?? $f->setup_style }}</b><em>{{ $f->hall->name }}</em></div>
          @endif
          <div class="menu-cover is-cook"><span>Cook for</span><b>{{ number_format($cookFor) }}</b><em>+5% overset</em></div>
        </div>

        <div class="menu-grid">
          <div class="menu-card">
            <span class="lbl">Menu</span>
            @if($pkg)
              <div class="menu-package"><b>{{ $pkg->name }}</b><span class="menu-type">{{ ucfirst($pkg->type ?: 'food') }}</span><span class="note">{{ number_format($cookFor) }} covers</span></div>
              @if($dishes)
                <ol class="menu-dishes">@foreach($dishes as $dish)<li>{{ $dish }}</li>@endforeach</ol>
              @else
                <p class="menu-empty">The package has no dish list recorded. The kitchen must not start from this sheet until it does.</p>
              @endif
            @elseif($f->rate_per_pax > 0)
              <div class="menu-package"><b>Custom menu</b><span class="menu-type">Custom</span></div>
              <p class="menu-empty">A per-guest rate is agreed with no package attached. The dish list lives in the notes below — confirm it with the client before service.</p>
            @else
              <p class="menu-empty">No food and beverage on this function — the kitchen has no covers to produce.</p>
            @endif
          </div>

          @if($f->barPackage)
            <div class="menu-card">
              <span class="lbl">Bar</span>
              <div class="menu-package"><b>{{ $f->barPackage->name }}</b><span class="menu-type">{{ ucfirst($f->barPackage->type ?: 'beverage') }}</span><span class="note">{{ number_format($cookFor) }} covers</span></div>
              @if(count($f->barPackage->itemList()))
                <ol class="menu-dishes">@foreach($f->barPackage->itemList() as $drink)<li>{{ $drink }}</li>@endforeach</ol>
              @else
                <p class="menu-empty">The drinks package has no list recorded — confirm the pour and the brands before service.</p>
              @endif
            </div>
          @endif

          <div class="menu-card">
            <span class="lbl">Dietary &amp; allergies</span>
            @if(count($f->dietaryLines()))
              <table class="menu-diet"><thead><tr><th>Diet</th><th class="r">Covers</th><th>Dish served</th></tr></thead><tbody>
                @foreach($f->dietaryLines() as $line)
                  <tr><td><b>{{ $line['label'] }}</b></td><td class="r"><b>{{ number_format($line['covers']) }}</b></td><td>{{ $line['dish'] ?: 'To be agreed' }}</td></tr>
                @endforeach
              </tbody></table>
              <p class="note">{{ number_format($f->dietaryCovers()) }} of the {{ number_format($f->billablePax()) }} covers need a separate plate. Counts taken with the client and held on the booking.</p>
            @else
              @if($e->requirements)
                <div class="pre muted">{{ $e->requirements }}</div>
              @else
                <p class="menu-empty">The booking carries no client requirements. Confirm dietary needs with the host before service.</p>
              @endif
              <table class="menu-diet"><thead><tr><th>Diet</th><th class="r">Covers</th><th>Dish served</th></tr></thead><tbody>
                @foreach(\App\Models\BanquetFunction::DIETS as $dietKey => $dietLabel)
                  <tr><td>{{ $dietLabel }}</td><td class="r"><span class="menu-fill"></span></td><td><span class="menu-fill menu-fill-wide"></span></td></tr>
                @endforeach
              </tbody></table>
              <p class="note">No dietary counts are held on this function. Take them from the client's requirements above, add them on the booking and write them in before service.</p>
            @endif
          </div>

          <div class="menu-card menu-wide">
            <span class="lbl">Kitchen &amp; service notes</span>
            <div class="pre">{{ $f->notes ?: 'No notes on this function.' }}</div>
          </div>
        </div>

        <div class="menu-received"><span>Received in kitchen by ______________________</span><span>Time received ________</span><span>{{ $f->name ?: 'Function' }} · {{ number_format($cookFor) }} covers</span></div>
      </div>
    </section>
  @endforeach
</section>

<div class="menu-foot">
  <span><strong>{{ $hotel->name }}</strong> · kitchen menu sheet · {{ $e->event_no }}</span>
  <span>No prices on this sheet — charges are on the event order</span>
  <span>Generated {{ now()->format('d M Y, H:i') }}</span>
</div>
</body></html>
