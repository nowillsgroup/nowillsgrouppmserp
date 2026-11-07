@extends('layouts.app')
@section('title', $title)
@section('crumbs') Front Office / {{ $title }} @endsection
@section('actions')
  @if(in_array($mode, ['arrivals', 'departures', 'inhouse'], true))
    @if($mode === 'inhouse')
      <a class="btn" href="{{ route('frontdesk.arrivals') }}"><x-icon name="login" size="sm"/> Arrivals</a>
      <a class="btn" href="{{ route('frontdesk.departures') }}"><x-icon name="logout" size="sm"/> Departures</a>
    @endif
    <a class="btn btn-soft" href="{{ route('frontdesk.rooms') }}"><x-icon name="bed" size="sm"/> Room view</a>
  @else
    <form class="filters" method="get"><div class="field"><label class="sr-only" for="list-date">Date</label><input id="list-date" type="date" name="date" value="{{ request('date', bdate()->toDateString()) }}" data-autosubmit></div></form>
    <input class="input" style="width:220px" placeholder="Filter list…" data-filter-table="#stayTable">
  @endif
  <button class="btn" type="button" onclick="window.print()"><x-icon name="print"/> Print</button>
@endsection
@section('content')
@if($mode === 'arrivals')
  @php
    $arrivalHousekeepingVisible = can_do('housekeeping.view') || can_do('housekeeping.manage') || can_do('housekeeping.attendant');
    $arrivalNights = fn ($stay) => max(1, $stay->arrival_date->diffInDays($stay->departure_date));
  @endphp
  <style>
    .arrival-page{--arrival-green:#17623b;--arrival-green-soft:#eff8f1;--arrival-amber:#9a5b00;--arrival-amber-soft:#fff7e8;--arrival-line:var(--c-border);color:var(--c-text)}
    .arrival-intro{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin:0 0 1rem;padding:1rem 1.15rem;background:linear-gradient(115deg,#f0f8f2 0%,var(--c-surface) 72%);border:1px solid #dcebe0;border-radius:14px}
    .arrival-intro-copy{min-width:0}.arrival-eyebrow{display:flex;align-items:center;gap:.45rem;color:var(--arrival-green);font-size:.72rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase}.arrival-eyebrow .i{width:15px;height:15px}
    .arrival-intro p{max-width:640px;margin:.4rem 0 0;color:var(--c-text-2);font-size:.9rem}
    .arrival-date-badge{display:flex;flex:0 0 auto;align-items:center;gap:.55rem;padding:.6rem .75rem;border:1px solid #dcebe0;border-radius:10px;background:#fff;color:#24583a;font-weight:700;font-variant-numeric:tabular-nums}
    .arrival-date-badge .i{color:var(--arrival-green)}
    .arrival-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem;margin-bottom:1rem}
    .arrival-stat{min-width:0;display:flex;align-items:center;gap:.75rem;padding:.85rem .95rem;border:1px solid var(--c-border);border-radius:12px;background:var(--c-surface);box-shadow:var(--shadow-sm)}
    .arrival-stat-icon{width:38px;height:38px;display:grid;place-items:center;flex:0 0 38px;border-radius:11px;background:#eff8f1;color:#17623b}.arrival-stat-icon .i{width:19px;height:19px}
    .arrival-stat:nth-child(2) .arrival-stat-icon{background:#eff6ff;color:#2563eb}.arrival-stat:nth-child(3) .arrival-stat-icon{background:#f5f3ff;color:#6d28d9}.arrival-stat:nth-child(4) .arrival-stat-icon{background:#fff7e8;color:#9a5b00}
    .arrival-stat-value{display:block;font-size:1.35rem;font-weight:780;line-height:1.1;font-variant-numeric:tabular-nums}.arrival-stat-label{display:block;margin-top:.22rem;color:var(--c-text-2);font-size:.76rem;line-height:1.3}
    .arrival-filter-card,.arrival-manifest{overflow:hidden;border:1px solid var(--c-border);border-radius:14px;background:var(--c-surface);box-shadow:var(--shadow-sm)}
    .arrival-filter-card{margin-bottom:1rem;padding:.9rem 1rem}
    .arrival-filter-form{display:flex;align-items:flex-end;gap:.7rem;flex-wrap:wrap}.arrival-filter-date{width:190px}.arrival-filter-date label{display:block;margin:0 0 .28rem;color:var(--c-text-2);font-size:.76rem;font-weight:700}.arrival-filter-date input{width:100%;min-height:39px;padding:.48rem .65rem;border:1px solid var(--c-border-strong);border-radius:8px;background:var(--c-surface);color:var(--c-text);font:inherit}
    .arrival-overdue{min-height:39px;display:flex;align-items:center;gap:.45rem;padding:0 .35rem;color:var(--c-text-2);font-size:.82rem;cursor:pointer}.arrival-overdue input{width:16px;height:16px;accent-color:#17623b}.arrival-filter-form .btn{min-height:39px}
    .arrival-filter-divider{width:1px;height:27px;margin:0 .15rem .38rem;background:var(--c-border)}.arrival-date-step{display:flex;align-items:center;gap:.2rem;margin:0 0 .15rem}.arrival-date-step a{width:34px;height:34px;display:grid;place-items:center;border:1px solid var(--c-border);border-radius:8px;color:var(--c-text-2);background:var(--c-surface)}.arrival-date-step a:hover{border-color:#9bc8aa;background:var(--arrival-green-soft);color:var(--arrival-green);text-decoration:none}.arrival-date-step .i{width:16px;height:16px}.arrival-date-step a:first-child .i{transform:rotate(180deg)}
    .arrival-manifest-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.95rem 1rem;border-bottom:1px solid var(--c-border)}.arrival-manifest-title h2{margin:0;font-size:1.02rem}.arrival-manifest-title p{margin:.18rem 0 0;color:var(--c-text-2);font-size:.78rem}.arrival-search{width:min(280px,100%);min-height:37px;padding:.45rem .7rem;border:1px solid var(--c-border-strong);border-radius:8px;background:var(--c-surface);color:var(--c-text);font:inherit;font-size:.84rem}
    .arrival-table-wrap{overflow-x:auto}.arrival-table{width:100%;min-width:790px;border-collapse:collapse;font-size:.84rem}.arrival-table th{padding:.62rem .85rem;background:#f7faf8;color:#52645a;border-bottom:1px solid var(--c-border);text-align:left;text-transform:uppercase;font-size:.69rem;letter-spacing:.06em;white-space:nowrap}.arrival-table td{padding:.82rem .85rem;border-bottom:1px solid var(--c-border);vertical-align:middle}.arrival-table tbody tr:last-child td{border-bottom:0}.arrival-table tbody tr:hover{background:#fbfdfb}
    .arrival-guest{display:flex;align-items:center;gap:.65rem;min-width:190px}.arrival-avatar{width:36px;height:36px;display:grid;place-items:center;flex:0 0 36px;border-radius:50%;background:#e8f3eb;color:#24583a;font-size:.78rem;font-weight:800;text-transform:uppercase}.arrival-guest-name{display:flex;align-items:center;gap:.36rem;flex-wrap:wrap;color:var(--c-text);font-weight:730}.arrival-guest-meta,.arrival-subline{display:block;margin-top:.18rem;color:var(--c-text-2);font-size:.75rem;line-height:1.35}.arrival-subline .i{width:12px;height:12px;margin-right:.12rem;vertical-align:-2px}
    .arrival-badge{display:inline-flex;align-items:center;gap:.27rem;padding:.19rem .48rem;border:1px solid transparent;border-radius:999px;font-size:.69rem;font-weight:720;line-height:1.2;white-space:nowrap}.arrival-badge .i{width:12px;height:12px}.arrival-badge-vip{background:#f5f0ff;color:#6941a5;border-color:#e2d7ff}.arrival-badge-ready{background:#edf8f0;color:#17623b;border-color:#cce7d3}.arrival-badge-pending{background:#f3f5f4;color:#53635a;border-color:#e0e7e2}.arrival-badge-overdue{background:#fff2e8;color:#a34417;border-color:#f5d5c1}.arrival-badge-room{background:#f3f7f4;color:#52645a;border-color:#e1e9e3}.arrival-badge-alert{background:#fff7e8;color:#8a5400;border-color:#f3ddb0}
    .arrival-room-number{display:block;color:var(--c-text);font-weight:750}.arrival-room-type{display:block;margin-top:.16rem;color:var(--c-text-2);font-size:.75rem}.arrival-booking a{font-weight:720}.arrival-action{white-space:nowrap}.arrival-action .btn{min-height:34px}.arrival-empty{padding:2.5rem 1rem;text-align:center}.arrival-empty-icon{width:48px;height:48px;display:grid;place-items:center;margin:0 auto .7rem;border-radius:15px;background:#eff8f1;color:#17623b}.arrival-empty-icon .i{width:24px;height:24px}.arrival-empty h3{margin:0 0 .3rem}.arrival-empty p{margin:0 auto .9rem;max-width:430px;color:var(--c-text-2);font-size:.85rem}.arrival-empty-actions{display:flex;justify-content:center;gap:.5rem;flex-wrap:wrap}
    .arrival-footnote{margin:.7rem .1rem 0;color:var(--c-text-2);font-size:.74rem}
    body.dark .arrival-intro{background:linear-gradient(115deg,#172c1f 0%,var(--c-surface) 75%);border-color:var(--c-border)}body.dark .arrival-date-badge{background:#172c1f;color:#c9efd4;border-color:var(--c-border)}body.dark .arrival-stat-icon,body.dark .arrival-avatar,body.dark .arrival-empty-icon{background:#1b3825;color:#9fe0b2}body.dark .arrival-stat:nth-child(2) .arrival-stat-icon{background:#1b2d46;color:#9ac0ff}body.dark .arrival-stat:nth-child(3) .arrival-stat-icon{background:#30234c;color:#c9b5ff}body.dark .arrival-stat:nth-child(4) .arrival-stat-icon{background:#3d301c;color:#ffcf7b}body.dark .arrival-table th{background:#18271e;color:var(--c-text-2)}body.dark .arrival-table tbody tr:hover{background:#18271e}body.dark .arrival-badge-vip{background:#30234c;color:#d2c2ff;border-color:#524076}body.dark .arrival-badge-ready{background:#193525;color:#a8e8b8;border-color:#315a3e}body.dark .arrival-badge-pending,body.dark .arrival-badge-room{background:#1c2b22;color:#c5d6c8;border-color:#344a3a}body.dark .arrival-badge-overdue{background:#3a281c;color:#ffc49e;border-color:#67402c}body.dark .arrival-badge-alert{background:#3b301d;color:#ffdc91;border-color:#66512b}
    @media(max-width:900px){.arrival-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:600px){.arrival-intro{display:block;padding:.85rem}.arrival-date-badge{display:inline-flex;margin-top:.7rem}.arrival-stats{gap:.5rem}.arrival-stat{gap:.55rem;padding:.72rem}.arrival-stat-icon{width:33px;height:33px;flex-basis:33px}.arrival-stat-value{font-size:1.18rem}.arrival-stat-label{font-size:.7rem}.arrival-filter-card{padding:.75rem}.arrival-filter-form{align-items:stretch}.arrival-filter-date{width:100%}.arrival-overdue{min-height:32px;padding:0}.arrival-filter-form .btn{flex:1}.arrival-filter-divider{display:none}.arrival-date-step{margin:.1rem auto 0}.arrival-manifest-head{align-items:stretch;flex-direction:column}.arrival-search{width:100%}.arrival-table{min-width:730px}}
    @media print{.arrival-intro,.arrival-filter-card,.arrival-search,.arrival-action,.arrival-footnote,.no-print{display:none!important}.arrival-stats{grid-template-columns:repeat(4,1fr)}.arrival-manifest,.arrival-stat{box-shadow:none;border-color:#aaa}.arrival-table-wrap{overflow:visible}.arrival-table{min-width:0;font-size:9pt}.arrival-table th,.arrival-table td{padding:6px}.arrival-page{color:#111}.arrival-table tr{break-inside:avoid}}
  </style>

  <div class="arrival-page">
    <section class="arrival-intro" aria-label="Arrival desk overview">
      <div class="arrival-intro-copy">
        <div class="arrival-eyebrow"><x-icon name="login"/> Front office · Check-in queue</div>
        <p>Review expected guests, confirm room assignments and move each arrival through check-in. Guests already checked in are kept off this pending list.</p>
      </div>
      <div class="arrival-date-badge"><x-icon name="calendar" size="sm"/><time datetime="{{ $arrivalDate->toDateString() }}">{{ $arrivalDate->format('l, j F Y') }}</time></div>
    </section>

    <section class="arrival-stats" aria-label="Arrival summary">
      <div class="arrival-stat"><span class="arrival-stat-icon"><x-icon name="login"/></span><span><b class="arrival-stat-value">{{ number_format($arrivalSummary['pending']) }}</b><span class="arrival-stat-label">Pending check-ins</span></span></div>
      <div class="arrival-stat"><span class="arrival-stat-icon"><x-icon name="users"/></span><span><b class="arrival-stat-value">{{ number_format($arrivalSummary['guests']) }}</b><span class="arrival-stat-label">Guests in this queue</span></span></div>
      <div class="arrival-stat"><span class="arrival-stat-icon"><x-icon name="check"/></span><span><b class="arrival-stat-value">{{ number_format($arrivalSummary['prechecked']) }}</b><span class="arrival-stat-label">Online pre-check-ins</span></span></div>
      <div class="arrival-stat"><span class="arrival-stat-icon"><x-icon name="bed"/></span><span><b class="arrival-stat-value">{{ number_format($arrivalSummary['unassigned']) }}</b><span class="arrival-stat-label">Rooms to assign</span></span></div>
    </section>

    <section class="arrival-filter-card" aria-label="Filter arrivals">
      <form class="arrival-filter-form" method="get" action="{{ route('frontdesk.arrivals') }}">
        <div class="arrival-filter-date"><label for="arrival-date-filter">Arrival date</label><input id="arrival-date-filter" type="date" name="date" value="{{ $arrivalDate->toDateString() }}"></div>
        <label class="arrival-overdue"><input type="checkbox" name="overdue" value="1" @checked($includeOverdue)> Include arrivals up to 30 days earlier</label>
        <button class="btn btn-primary" type="submit"><x-icon name="search" size="sm"/> Show arrivals</button>
        @if($arrivalDate->toDateString() !== bdate()->toDateString())<a class="btn btn-soft" href="{{ route('frontdesk.arrivals') }}">Today</a>@endif
        <span class="arrival-filter-divider" aria-hidden="true"></span>
        <nav class="arrival-date-step" aria-label="Change arrival date">
          <a href="{{ route('frontdesk.arrivals', ['date' => $arrivalDate->copy()->subDay()->toDateString(), 'overdue' => $includeOverdue ? 1 : null]) }}" aria-label="Previous day" title="Previous day"><x-icon name="chev"/></a>
          <a href="{{ route('frontdesk.arrivals', ['date' => $arrivalDate->copy()->addDay()->toDateString(), 'overdue' => $includeOverdue ? 1 : null]) }}" aria-label="Next day" title="Next day"><x-icon name="chev"/></a>
        </nav>
      </form>
    </section>

    <section class="arrival-manifest" aria-labelledby="arrival-manifest-title">
      <header class="arrival-manifest-head">
        <div class="arrival-manifest-title"><h2 id="arrival-manifest-title">Arrival manifest <span class="badge badge-green">{{ number_format($arrivalSummary['pending']) }}</span></h2><p>{{ $includeOverdue ? 'Pending stays due on this date or in the previous 30 days' : 'Pending stays due on the selected date' }} · Sorted by arrival date, then room</p></div>
        <label class="sr-only" for="arrival-manifest-search">Search this arrival list</label>
        <input class="arrival-search no-print" id="arrival-manifest-search" type="search" placeholder="Search guest, room, phone or booking…" data-filter-table="#stayTable" autocomplete="off">
      </header>
      <div class="arrival-table-wrap">
        <table class="arrival-table" id="stayTable">
          <thead><tr><th scope="col">Guest</th><th scope="col">Stay &amp; party</th><th scope="col">Room</th><th scope="col">Booking</th><th scope="col">Readiness</th><th scope="col" class="no-print">Action</th></tr></thead>
          <tbody>
          @forelse($stays as $s)
            @php
              $guest = $s->reservation?->guest;
              $guestName = $guest?->full_name ?: 'Guest details unavailable';
              $initials = collect(explode(' ', trim($guestName)))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('');
              $nights = $arrivalNights($s);
              $isPrechecked = (bool) $s->reservation?->prechecked_in_at;
              $isPastDue = $s->arrival_date->lt($arrivalDate);
            @endphp
            <tr>
              <td>
                <div class="arrival-guest"><span class="arrival-avatar" aria-hidden="true">{{ $initials ?: '?' }}</span><span><span class="arrival-guest-name">{{ $guestName }} @if($guest?->is_vip)<span class="arrival-badge arrival-badge-vip">VIP</span>@endif</span>
                  <span class="arrival-guest-meta">@if($guest?->phone)<x-icon name="phone" size="sm"/> {{ $guest->phone }}@else Phone not provided @endif</span>
                  @if($s->reservation?->company)<span class="arrival-subline">{{ $s->reservation->company->name }}</span>@endif
                </span></div>
              </td>
              <td>
                <time datetime="{{ $s->arrival_date->toDateString() }}">{{ $s->arrival_date->format('d M Y') }}</time><span class="arrival-subline">to <time datetime="{{ $s->departure_date->toDateString() }}">{{ $s->departure_date->format('d M Y') }}</time> · {{ $nights }} {{ \Illuminate\Support\Str::plural('night', $nights) }}</span>
                <span class="arrival-subline">{{ $s->adults }} {{ \Illuminate\Support\Str::plural('adult', $s->adults) }}@if($s->children > 0) · {{ $s->children }} {{ \Illuminate\Support\Str::plural('child', $s->children) }}@endif</span>
              </td>
              <td>
                @if($s->room)
                  <span class="arrival-room-number">Room {{ $s->room->room_no }}</span><span class="arrival-room-type">{{ $s->roomType?->name ?? $s->roomType?->code ?? 'Room type not set' }}</span>
                  @if($arrivalHousekeepingVisible && $s->room->hk_status)<span class="arrival-subline">Housekeeping: {{ ucwords(str_replace('_', ' ', $s->room->hk_status)) }}</span>@endif
                  @if(in_array($s->room->status, ['out_of_order', 'out_of_service'], true))<span class="arrival-badge arrival-badge-alert">{{ ucwords(str_replace('_', ' ', $s->room->status)) }}</span>@endif
                @else
                  <span class="arrival-badge arrival-badge-alert">Room unassigned</span><span class="arrival-room-type">{{ $s->roomType?->name ?? $s->roomType?->code ?? 'Room type not set' }}</span>
                @endif
              </td>
              <td class="arrival-booking">
                @if($s->reservation && can_do('reservations.view'))<a href="{{ route('reservations.show', $s->reservation) }}">{{ $s->reservation->reservation_no }}</a>@elseif($s->reservation){{ $s->reservation->reservation_no }}@else <span class="muted">—</span>@endif
                <span class="arrival-subline">{{ $s->reservation?->businessSource?->name ?? 'Direct booking' }}</span>
              </td>
              <td>
                @if($isPrechecked)<span class="arrival-badge arrival-badge-ready"><x-icon name="check"/> Online pre-checked</span><span class="arrival-subline">{{ $s->reservation->prechecked_in_at->format('d M, H:i') }}</span>
                @else<span class="arrival-badge arrival-badge-pending">Check-in pending</span>@endif
                @if($isPastDue)<span class="arrival-subline"><span class="arrival-badge arrival-badge-overdue">{{ $s->arrival_date->diffInDays($arrivalDate) }} days past due</span></span>@endif
              </td>
              <td class="arrival-action no-print">
                @if(can_do('frontdesk.checkin'))<a class="btn btn-success btn-sm" href="{{ route('frontdesk.checkin', $s) }}" aria-label="Check in {{ $guestName }} for reservation {{ $s->reservation?->reservation_no ?? 'unknown' }}"><x-icon name="login" size="sm"/> Check in</a>
                @elseif($s->reservation && can_do('reservations.view'))<a class="btn btn-sm" href="{{ route('reservations.show', $s->reservation) }}">View booking</a>@else<span class="muted">—</span>@endif
              </td>
            </tr>
          @empty
            <tr><td colspan="6"><div class="arrival-empty"><span class="arrival-empty-icon"><x-icon name="check"/></span><h3>No pending arrivals</h3><p>{{ $includeOverdue ? 'No reserved stays are due on this date or in the previous 30 days.' : 'There are no reserved stays scheduled for this date. Try another date or include overdue arrivals.' }}</p><div class="arrival-empty-actions">@if(!$includeOverdue)<a class="btn btn-soft" href="{{ route('frontdesk.arrivals', ['date' => $arrivalDate->toDateString(), 'overdue' => 1]) }}">Include overdue arrivals</a>@endif<a class="btn" href="{{ route('reservations.workspace', ['date' => $arrivalDate->toDateString()]) }}">Open reservation workspace</a></div></div></td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </section>
    <p class="arrival-footnote">Counts reflect pending room stays. For group bookings, each room stay is listed separately. Online pre-check-in is a preparation aid and does not complete the front-desk check-in.</p>
  </div>
@elseif($mode === 'departures')
  @php
    $departureHousekeepingVisible = can_do('housekeeping.view') || can_do('housekeeping.manage') || can_do('housekeeping.attendant');
    $departureNights = fn ($stay) => max(1, $stay->arrival_date->diffInDays($stay->departure_date));
  @endphp
  <style>
    .departure-page{--departure-green:#17623b;color:var(--c-text)}.departure-intro{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin:0 0 1rem;padding:1rem 1.15rem;background:linear-gradient(115deg,#f0f8f2 0%,var(--c-surface) 72%);border:1px solid #dcebe0;border-radius:14px}.departure-intro-copy{min-width:0}.departure-eyebrow{display:flex;align-items:center;gap:.45rem;color:var(--departure-green);font-size:.72rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase}.departure-eyebrow .i{width:15px;height:15px}.departure-intro p{max-width:660px;margin:.4rem 0 0;color:var(--c-text-2);font-size:.9rem}.departure-date-badge{display:flex;flex:0 0 auto;align-items:center;gap:.55rem;padding:.6rem .75rem;border:1px solid #dcebe0;border-radius:10px;background:#fff;color:#24583a;font-weight:700;font-variant-numeric:tabular-nums}.departure-date-badge .i{color:var(--departure-green)}
    .departure-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem;margin-bottom:1rem}.departure-stat{min-width:0;display:flex;align-items:center;gap:.75rem;padding:.85rem .95rem;border:1px solid var(--c-border);border-radius:12px;background:var(--c-surface);box-shadow:var(--shadow-sm)}.departure-stat-icon{width:38px;height:38px;display:grid;place-items:center;flex:0 0 38px;border-radius:11px;background:#eff8f1;color:#17623b}.departure-stat-icon .i{width:19px;height:19px}.departure-stat:nth-child(2) .departure-stat-icon{background:#eff6ff;color:#2563eb}.departure-stat:nth-child(3) .departure-stat-icon{background:#fff2e8;color:#a34417}.departure-stat:nth-child(4) .departure-stat-icon{background:#fff7e8;color:#9a5b00}.departure-stat-value{display:block;font-size:1.3rem;font-weight:780;line-height:1.1;font-variant-numeric:tabular-nums}.departure-stat-label{display:block;margin-top:.22rem;color:var(--c-text-2);font-size:.76rem;line-height:1.3}
    .departure-filter-card,.departure-manifest{overflow:hidden;border:1px solid var(--c-border);border-radius:14px;background:var(--c-surface);box-shadow:var(--shadow-sm)}.departure-filter-card{margin-bottom:1rem;padding:.9rem 1rem}.departure-filter-form{display:flex;align-items:flex-end;gap:.7rem;flex-wrap:wrap}.departure-filter-date{width:190px}.departure-filter-date label{display:block;margin:0 0 .28rem;color:var(--c-text-2);font-size:.76rem;font-weight:700}.departure-filter-date input{width:100%;min-height:39px;padding:.48rem .65rem;border:1px solid var(--c-border-strong);border-radius:8px;background:var(--c-surface);color:var(--c-text);font:inherit}.departure-filter-form .btn{min-height:39px}.departure-date-step{display:flex;align-items:center;gap:.2rem;margin:0 0 .15rem}.departure-date-step a{width:34px;height:34px;display:grid;place-items:center;border:1px solid var(--c-border);border-radius:8px;color:var(--c-text-2);background:var(--c-surface)}.departure-date-step a:hover{border-color:#9bc8aa;background:#eff8f1;color:var(--departure-green);text-decoration:none}.departure-date-step .i{width:16px;height:16px}.departure-date-step a:first-child .i{transform:rotate(180deg)}
    .departure-manifest-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.95rem 1rem;border-bottom:1px solid var(--c-border)}.departure-manifest-title h2{margin:0;font-size:1.02rem}.departure-manifest-title p{margin:.18rem 0 0;color:var(--c-text-2);font-size:.78rem}.departure-search{width:min(280px,100%);min-height:37px;padding:.45rem .7rem;border:1px solid var(--c-border-strong);border-radius:8px;background:var(--c-surface);color:var(--c-text);font:inherit;font-size:.84rem}.departure-table-wrap{overflow-x:auto}.departure-table{width:100%;min-width:760px;border-collapse:collapse;font-size:.84rem}.departure-table th{padding:.62rem .85rem;background:#f7faf8;color:#52645a;border-bottom:1px solid var(--c-border);text-align:left;text-transform:uppercase;font-size:.69rem;letter-spacing:.06em;white-space:nowrap}.departure-table td{padding:.82rem .85rem;border-bottom:1px solid var(--c-border);vertical-align:middle}.departure-table tbody tr:last-child td{border-bottom:0}.departure-table tbody tr:hover{background:#fbfdfb}
    .departure-guest{display:flex;align-items:center;gap:.65rem;min-width:190px}.departure-avatar{width:36px;height:36px;display:grid;place-items:center;flex:0 0 36px;border-radius:50%;background:#e8f3eb;color:#24583a;font-size:.78rem;font-weight:800;text-transform:uppercase}.departure-guest-name{display:flex;align-items:center;gap:.36rem;flex-wrap:wrap;color:var(--c-text);font-weight:730}.departure-meta,.departure-subline{display:block;margin-top:.18rem;color:var(--c-text-2);font-size:.75rem;line-height:1.35}.departure-badge{display:inline-flex;align-items:center;gap:.27rem;padding:.19rem .48rem;border:1px solid transparent;border-radius:999px;font-size:.69rem;font-weight:720;line-height:1.2;white-space:nowrap}.departure-badge .i{width:12px;height:12px}.departure-badge-vip{background:#f5f0ff;color:#6941a5;border-color:#e2d7ff}.departure-badge-due{background:#edf8f0;color:#17623b;border-color:#cce7d3}.departure-badge-overdue{background:#fff2e8;color:#a34417;border-color:#f5d5c1}.departure-badge-room{background:#f3f7f4;color:#52645a;border-color:#e1e9e3}.departure-badge-credit{background:#eff6ff;color:#1d4ed8;border-color:#c8dcff}.departure-badge-dayuse{background:#eef2ff;color:#3730a3;border-color:#cdd3ff}.departure-room-number{display:block;color:var(--c-text);font-weight:750}.departure-room-type{display:block;margin-top:.16rem;color:var(--c-text-2);font-size:.75rem}.departure-folio a{font-weight:700}.departure-folio-balance{display:block;font-weight:750;font-variant-numeric:tabular-nums}.departure-action{white-space:nowrap}.departure-action .btn{min-height:34px}.departure-empty{padding:2.5rem 1rem;text-align:center}.departure-empty-icon{width:48px;height:48px;display:grid;place-items:center;margin:0 auto .7rem;border-radius:15px;background:#eff8f1;color:#17623b}.departure-empty-icon .i{width:24px;height:24px}.departure-empty h3{margin:0 0 .3rem}.departure-empty p{margin:0 auto .9rem;max-width:440px;color:var(--c-text-2);font-size:.85rem}.departure-empty-actions{display:flex;justify-content:center;gap:.5rem;flex-wrap:wrap}.departure-footnote{margin:.7rem .1rem 0;color:var(--c-text-2);font-size:.74rem}
    body.dark .departure-intro{background:linear-gradient(115deg,#172c1f 0%,var(--c-surface) 75%);border-color:var(--c-border)}body.dark .departure-date-badge{background:#172c1f;color:#c9efd4;border-color:var(--c-border)}body.dark .departure-stat-icon,body.dark .departure-avatar,body.dark .departure-empty-icon{background:#1b3825;color:#9fe0b2}body.dark .departure-stat:nth-child(2) .departure-stat-icon{background:#1b2d46;color:#9ac0ff}body.dark .departure-stat:nth-child(3) .departure-stat-icon{background:#3a281c;color:#ffc49e}body.dark .departure-stat:nth-child(4) .departure-stat-icon{background:#3d301c;color:#ffcf7b}body.dark .departure-table th{background:#18271e;color:var(--c-text-2)}body.dark .departure-table tbody tr:hover{background:#18271e}body.dark .departure-badge-vip{background:#30234c;color:#d2c2ff;border-color:#524076}body.dark .departure-badge-due{background:#193525;color:#a8e8b8;border-color:#315a3e}body.dark .departure-badge-overdue{background:#3a281c;color:#ffc49e;border-color:#67402c}body.dark .departure-badge-room{background:#1c2b22;color:#c5d6c8;border-color:#344a3a}body.dark .departure-badge-credit{background:#1b2d46;color:#b4d1ff;border-color:#334d70}body.dark .departure-badge-dayuse{background:#242a52;color:#c7ccff;border-color:#3b4380}
    @media(max-width:900px){.departure-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.departure-intro{display:block;padding:.85rem}.departure-date-badge{display:inline-flex;margin-top:.7rem}.departure-stats{gap:.5rem}.departure-stat{gap:.55rem;padding:.72rem}.departure-stat-icon{width:33px;height:33px;flex-basis:33px}.departure-stat-value{font-size:1.15rem}.departure-stat-label{font-size:.7rem}.departure-filter-card{padding:.75rem}.departure-filter-form{align-items:stretch}.departure-filter-date{width:100%}.departure-filter-form .btn{flex:1}.departure-date-step{margin:.1rem auto 0}.departure-manifest-head{align-items:stretch;flex-direction:column}.departure-search{width:100%}.departure-table{min-width:700px}}
    @media print{.departure-filter-card,.departure-search,.departure-action,.departure-footnote,.no-print{display:none!important}.departure-stats{grid-template-columns:repeat(4,1fr)}.departure-manifest,.departure-stat{box-shadow:none;border-color:#aaa}.departure-table-wrap{overflow:visible}.departure-table{min-width:0;font-size:9pt}.departure-table th,.departure-table td{padding:6px}.departure-table tr{break-inside:avoid}}
  </style>

  <div class="departure-page">
    <section class="departure-intro" aria-label="Departure desk overview">
      <div class="departure-intro-copy"><div class="departure-eyebrow"><x-icon name="logout"/> Front office · Check-out queue</div><p>Review guests due to leave, check folio settlement where your role allows, then complete check-out to release each room for turnover.</p></div>
      <div class="departure-date-badge"><x-icon name="calendar" size="sm"/><time datetime="{{ $departureDate->toDateString() }}">{{ $departureDate->format('l, j F Y') }}</time></div>
    </section>

    <section class="departure-stats" aria-label="Departure summary">
      <div class="departure-stat"><span class="departure-stat-icon"><x-icon name="logout"/></span><span><b class="departure-stat-value">{{ number_format($departureSummary['due']) }}</b><span class="departure-stat-label">Stays due to check out</span></span></div>
      <div class="departure-stat"><span class="departure-stat-icon"><x-icon name="users"/></span><span><b class="departure-stat-value">{{ number_format($departureSummary['guests']) }}</b><span class="departure-stat-label">Guests across due-outs</span></span></div>
      <div class="departure-stat"><span class="departure-stat-icon"><x-icon name="clock"/></span><span><b class="departure-stat-value">{{ number_format($departureSummary['overdue']) }}</b><span class="departure-stat-label">Past departure date</span></span></div>
      @if($canViewFolio)
        <div class="departure-stat"><span class="departure-stat-icon"><x-icon name="receipt"/></span><span><b class="departure-stat-value">{{ money($departureSummary['balance']) }}</b><span class="departure-stat-label">Outstanding folio balance</span></span></div>
      @else
        <div class="departure-stat"><span class="departure-stat-icon"><x-icon name="bed"/></span><span><b class="departure-stat-value">{{ $stays->whereNotNull('room_id')->count() }}</b><span class="departure-stat-label">Rooms to release</span></span></div>
      @endif
    </section>

    <section class="departure-filter-card" aria-label="Filter departures">
      <form class="departure-filter-form" method="get" action="{{ route('frontdesk.departures') }}">
        <div class="departure-filter-date"><label for="departure-date-filter">Due by</label><input id="departure-date-filter" type="date" name="date" value="{{ $departureDate->toDateString() }}"></div>
        <button class="btn btn-primary" type="submit"><x-icon name="search" size="sm"/> Show due-outs</button>
        @if($departureDate->toDateString() !== bdate()->toDateString())<a class="btn btn-soft" href="{{ route('frontdesk.departures') }}">Today</a>@endif
        <nav class="departure-date-step" aria-label="Change departure date"><a href="{{ route('frontdesk.departures', ['date' => $departureDate->copy()->subDay()->toDateString()]) }}" aria-label="Previous day" title="Previous day"><x-icon name="chev"/></a><a href="{{ route('frontdesk.departures', ['date' => $departureDate->copy()->addDay()->toDateString()]) }}" aria-label="Next day" title="Next day"><x-icon name="chev"/></a></nav>
      </form>
    </section>

    <section class="departure-manifest" aria-labelledby="departure-manifest-title">
      <header class="departure-manifest-head"><div class="departure-manifest-title"><h2 id="departure-manifest-title">Departure manifest <span class="badge badge-green">{{ number_format($departureSummary['due']) }}</span></h2><p>Currently checked-in stays with a departure date on or before {{ $departureDate->format('j M Y') }} · Oldest due-outs first</p></div><label class="sr-only" for="departure-manifest-search">Search this departure list</label><input class="departure-search no-print" id="departure-manifest-search" type="search" placeholder="Search guest, room, phone or booking…" data-filter-table="#stayTable" autocomplete="off"></header>
      <div class="departure-table-wrap"><table class="departure-table" id="stayTable">
        <thead><tr><th scope="col">Guest</th><th scope="col">Stay &amp; departure</th><th scope="col">Room</th>@if($canViewFolio)<th scope="col">Folio</th>@endif<th scope="col" class="no-print">Action</th></tr></thead>
        <tbody>
        @forelse($stays as $s)
          @php
            $guest = $s->reservation?->guest;
            $guestName = $guest?->full_name ?: 'Guest details unavailable';
            $initials = collect(explode(' ', trim($guestName)))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('');
            $nights = $departureNights($s);
            $pastDue = $s->departure_date->lt($departureDate);
            $balance = (float) ($s->folio?->listing_balance ?? 0);
          @endphp
          <tr>
            <td><div class="departure-guest"><span class="departure-avatar" aria-hidden="true">{{ $initials ?: '?' }}</span><span><span class="departure-guest-name">{{ $guestName }} @if($guest?->is_vip)<span class="departure-badge departure-badge-vip">VIP</span>@endif @if($s->is_day_use)<span class="departure-badge departure-badge-dayuse">Day use</span>@endif</span><span class="departure-meta">@if($guest?->phone)<x-icon name="phone" size="sm"/> {{ $guest->phone }}@else Phone not provided @endif</span>@if($s->reservation?->company)<span class="departure-subline">{{ $s->reservation->company->name }}</span>@endif</span></div></td>
            <td><span>{{ $s->arrival_date->format('d M Y') }}</span><span class="departure-subline">@if($s->is_day_use)Day use {{ $s->dayUseUntilLabel() ?? 'today' }} · no night sold @else Arrived · {{ $nights }} {{ \Illuminate\Support\Str::plural('night', $nights) }}@endif</span><span class="departure-subline">Due <time datetime="{{ $s->departure_date->toDateString() }}">{{ $s->departure_date->format('d M Y') }}</time> · {{ $s->adults }} {{ \Illuminate\Support\Str::plural('adult', $s->adults) }}@if($s->children > 0), {{ $s->children }} {{ \Illuminate\Support\Str::plural('child', $s->children) }}@endif</span>@if($pastDue)<span class="departure-badge departure-badge-overdue">{{ $s->departure_date->diffInDays($departureDate) }} days past due</span>@else<span class="departure-badge departure-badge-due">Due on selected date</span>@endif</td>
            <td>@if($s->room)<span class="departure-room-number">Room {{ $s->room->room_no }}</span><span class="departure-room-type">{{ $s->roomType?->name ?? $s->roomType?->code ?? 'Room type not set' }}</span>@if($departureHousekeepingVisible && $s->room->hk_status)<span class="departure-subline">Housekeeping: {{ ucwords(str_replace('_', ' ', $s->room->hk_status)) }}</span>@endif @else<span class="departure-room-number">Room not assigned</span><span class="departure-room-type">{{ $s->roomType?->name ?? $s->roomType?->code ?? 'Room type not set' }}</span>@endif</td>
            @if($canViewFolio)<td class="departure-folio">@if($s->folio)<a href="{{ route('folios.show', $s->folio) }}">Folio {{ $s->folio->folio_no }}</a><span class="departure-folio-balance {{ $balance > 0 ? 'text-red' : 'text-green' }}">{{ money($balance) }} {{ $balance > 0 ? 'due' : ($balance < 0 ? 'credit' : 'settled') }}</span>@else<span class="muted">No folio linked</span>@endif</td>@endif
            <td class="departure-action no-print">@if(can_do('frontdesk.checkout'))<a class="btn btn-warning btn-sm" href="{{ route('frontdesk.checkout', $s) }}" aria-label="Check out {{ $guestName }} from room {{ $s->room?->room_no ?? 'unassigned' }}"><x-icon name="logout" size="sm"/> Check out</a>@elseif($s->reservation && can_do('reservations.view'))<a class="btn btn-sm" href="{{ route('reservations.show', $s->reservation) }}">View booking</a>@else<span class="muted">—</span>@endif</td>
          </tr>
        @empty
          <tr><td colspan="{{ $canViewFolio ? 5 : 4 }}"><div class="departure-empty"><span class="departure-empty-icon"><x-icon name="check"/></span><h3>No guests due out</h3><p>No checked-in stay has a departure date on or before this date. Try another day to review the turnover queue.</p><div class="departure-empty-actions"><a class="btn btn-soft" href="{{ route('frontdesk.inhouse') }}">View in-house guests</a><a class="btn" href="{{ route('reservations.workspace', ['date' => $departureDate->toDateString()]) }}">Open reservation workspace</a></div></div></td></tr>
        @endforelse
        </tbody>
      </table></div>
    </section>
    <p class="departure-footnote">Each row represents one checked-in room stay. Guests already checked out are excluded; complete check-out to mark the room ready for housekeeping turnover.</p>
  </div>
@elseif($mode === 'inhouse')
  @php
    $inHouseHousekeepingVisible = can_do('housekeeping.view') || can_do('housekeeping.manage') || can_do('housekeeping.attendant');
    $inHouseNightsLeft = fn ($stay) => max(0, $inHouseDate->diffInDays($stay->departure_date));
  @endphp
  <style>
    .inhouse-page{--inhouse-green:#17623b;color:var(--c-text)}.inhouse-intro{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;margin:0 0 1rem;padding:1rem 1.15rem;background:linear-gradient(115deg,#f0f8f2 0%,var(--c-surface) 72%);border:1px solid #dcebe0;border-radius:14px}.inhouse-intro-copy{min-width:0}.inhouse-eyebrow{display:flex;align-items:center;gap:.45rem;color:var(--inhouse-green);font-size:.72rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase}.inhouse-eyebrow .i{width:15px;height:15px}.inhouse-intro p{max-width:650px;margin:.4rem 0 0;color:var(--c-text-2);font-size:.9rem}.inhouse-date-badge{display:flex;flex:0 0 auto;align-items:center;gap:.55rem;padding:.6rem .75rem;border:1px solid #dcebe0;border-radius:10px;background:#fff;color:#24583a;font-weight:700;font-variant-numeric:tabular-nums}.inhouse-date-badge .i{color:var(--inhouse-green)}
    .inhouse-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.75rem;margin-bottom:1rem}.inhouse-stat{min-width:0;display:flex;align-items:center;gap:.75rem;padding:.85rem .95rem;border:1px solid var(--c-border);border-radius:12px;background:var(--c-surface);box-shadow:var(--shadow-sm)}.inhouse-stat-icon{width:38px;height:38px;display:grid;place-items:center;flex:0 0 38px;border-radius:11px;background:#eff8f1;color:#17623b}.inhouse-stat-icon .i{width:19px;height:19px}.inhouse-stat:nth-child(2) .inhouse-stat-icon{background:#eff6ff;color:#2563eb}.inhouse-stat:nth-child(3) .inhouse-stat-icon{background:#fff7e8;color:#9a5b00}.inhouse-stat:nth-child(4) .inhouse-stat-icon{background:#fff2e8;color:#a34417}.inhouse-stat-value{display:block;font-size:1.3rem;font-weight:780;line-height:1.1;font-variant-numeric:tabular-nums}.inhouse-stat-label{display:block;margin-top:.22rem;color:var(--c-text-2);font-size:.76rem;line-height:1.3}
    .inhouse-manifest{overflow:hidden;border:1px solid var(--c-border);border-radius:14px;background:var(--c-surface);box-shadow:var(--shadow-sm)}.inhouse-manifest-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.95rem 1rem;border-bottom:1px solid var(--c-border)}.inhouse-manifest-title h2{margin:0;font-size:1.02rem}.inhouse-manifest-title p{margin:.18rem 0 0;color:var(--c-text-2);font-size:.78rem}.inhouse-search{width:min(280px,100%);min-height:37px;padding:.45rem .7rem;border:1px solid var(--c-border-strong);border-radius:8px;background:var(--c-surface);color:var(--c-text);font:inherit;font-size:.84rem}.inhouse-table-wrap{overflow-x:auto}.inhouse-table{width:100%;min-width:830px;border-collapse:collapse;font-size:.84rem}.inhouse-table th{padding:.62rem .85rem;background:#f7faf8;color:#52645a;border-bottom:1px solid var(--c-border);text-align:left;text-transform:uppercase;font-size:.69rem;letter-spacing:.06em;white-space:nowrap}.inhouse-table td{padding:.82rem .85rem;border-bottom:1px solid var(--c-border);vertical-align:middle}.inhouse-table tbody tr:last-child td{border-bottom:0}.inhouse-table tbody tr:hover{background:#fbfdfb}
    .inhouse-guest{display:flex;align-items:center;gap:.65rem;min-width:190px}.inhouse-avatar{width:36px;height:36px;display:grid;place-items:center;flex:0 0 36px;border-radius:50%;background:#e8f3eb;color:#24583a;font-size:.78rem;font-weight:800;text-transform:uppercase}.inhouse-guest-name{display:flex;align-items:center;gap:.36rem;flex-wrap:wrap;color:var(--c-text);font-weight:730}.inhouse-meta,.inhouse-subline{display:block;margin-top:.18rem;color:var(--c-text-2);font-size:.75rem;line-height:1.35}.inhouse-badge{display:inline-flex;align-items:center;gap:.27rem;padding:.19rem .48rem;border:1px solid transparent;border-radius:999px;font-size:.69rem;font-weight:720;line-height:1.2;white-space:nowrap}.inhouse-badge .i{width:12px;height:12px}.inhouse-badge-vip{background:#f5f0ff;color:#6941a5;border-color:#e2d7ff}.inhouse-badge-current{background:#edf8f0;color:#17623b;border-color:#cce7d3}.inhouse-badge-due{background:#fff7e8;color:#8a5400;border-color:#f3ddb0}.inhouse-badge-overdue{background:#fff2e8;color:#a34417;border-color:#f5d5c1}.inhouse-badge-credit{background:#eff6ff;color:#1d4ed8;border-color:#c8dcff}.inhouse-badge-dayuse{background:#eef2ff;color:#3730a3;border-color:#cdd3ff}.inhouse-room-number{display:block;color:var(--c-text);font-weight:750}.inhouse-room-type{display:block;margin-top:.16rem;color:var(--c-text-2);font-size:.75rem}.inhouse-folio a{font-weight:700}.inhouse-folio-balance{display:block;font-weight:750;font-variant-numeric:tabular-nums}.inhouse-actions{display:flex;align-items:center;gap:.35rem;flex-wrap:wrap}.inhouse-actions .btn{min-height:33px}.inhouse-empty{padding:2.5rem 1rem;text-align:center}.inhouse-empty-icon{width:48px;height:48px;display:grid;place-items:center;margin:0 auto .7rem;border-radius:15px;background:#eff8f1;color:#17623b}.inhouse-empty-icon .i{width:24px;height:24px}.inhouse-empty h3{margin:0 0 .3rem}.inhouse-empty p{margin:0 auto .9rem;max-width:440px;color:var(--c-text-2);font-size:.85rem}.inhouse-empty-actions{display:flex;justify-content:center;gap:.5rem;flex-wrap:wrap}.inhouse-footnote{margin:.7rem .1rem 0;color:var(--c-text-2);font-size:.74rem}
    body.dark .inhouse-intro{background:linear-gradient(115deg,#172c1f 0%,var(--c-surface) 75%);border-color:var(--c-border)}body.dark .inhouse-date-badge{background:#172c1f;color:#c9efd4;border-color:var(--c-border)}body.dark .inhouse-stat-icon,body.dark .inhouse-avatar,body.dark .inhouse-empty-icon{background:#1b3825;color:#9fe0b2}body.dark .inhouse-stat:nth-child(2) .inhouse-stat-icon{background:#1b2d46;color:#9ac0ff}body.dark .inhouse-stat:nth-child(3) .inhouse-stat-icon{background:#3d301c;color:#ffcf7b}body.dark .inhouse-stat:nth-child(4) .inhouse-stat-icon{background:#3a281c;color:#ffc49e}body.dark .inhouse-table th{background:#18271e;color:var(--c-text-2)}body.dark .inhouse-table tbody tr:hover{background:#18271e}body.dark .inhouse-badge-vip{background:#30234c;color:#d2c2ff;border-color:#524076}body.dark .inhouse-badge-current{background:#193525;color:#a8e8b8;border-color:#315a3e}body.dark .inhouse-badge-due{background:#3b301d;color:#ffdc91;border-color:#66512b}body.dark .inhouse-badge-overdue{background:#3a281c;color:#ffc49e;border-color:#67402c}body.dark .inhouse-badge-credit{background:#1b2d46;color:#b4d1ff;border-color:#334d70}body.dark .inhouse-badge-dayuse{background:#242a52;color:#c7ccff;border-color:#3b4380}
    @media(max-width:900px){.inhouse-stats{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.inhouse-intro{display:block;padding:.85rem}.inhouse-date-badge{display:inline-flex;margin-top:.7rem}.inhouse-stats{gap:.5rem}.inhouse-stat{gap:.55rem;padding:.72rem}.inhouse-stat-icon{width:33px;height:33px;flex-basis:33px}.inhouse-stat-value{font-size:1.15rem}.inhouse-stat-label{font-size:.7rem}.inhouse-manifest-head{align-items:stretch;flex-direction:column}.inhouse-search{width:100%}.inhouse-table{min-width:760px}}
    @media print{.inhouse-search,.inhouse-actions,.no-print,.inhouse-footnote{display:none!important}.inhouse-stats{grid-template-columns:repeat(4,1fr)}.inhouse-manifest,.inhouse-stat{box-shadow:none;border-color:#aaa}.inhouse-table-wrap{overflow:visible}.inhouse-table{min-width:0;font-size:9pt}.inhouse-table th,.inhouse-table td{padding:6px}.inhouse-table tr{break-inside:avoid}}
  </style>

  <div class="inhouse-page">
    <section class="inhouse-intro" aria-label="In-house desk overview"><div class="inhouse-intro-copy"><div class="inhouse-eyebrow"><x-icon name="users"/> Front office · Live stay roster</div><p>See who is currently staying, where they are assigned, and which rooms are due to turn over. Folio details are shown only to roles with folio access.</p></div><div class="inhouse-date-badge"><x-icon name="calendar" size="sm"/><time datetime="{{ $inHouseDate->toDateString() }}">Business date · {{ $inHouseDate->format('j M Y') }}</time></div></section>
    <section class="inhouse-stats" aria-label="In-house summary">
      <div class="inhouse-stat"><span class="inhouse-stat-icon"><x-icon name="bed"/></span><span><b class="inhouse-stat-value">{{ number_format($inHouseSummary['stays']) }}</b><span class="inhouse-stat-label">Checked-in stays</span></span></div>
      <div class="inhouse-stat"><span class="inhouse-stat-icon"><x-icon name="users"/></span><span><b class="inhouse-stat-value">{{ number_format($inHouseSummary['guests']) }}</b><span class="inhouse-stat-label">Guests in-house</span></span></div>
      <div class="inhouse-stat"><span class="inhouse-stat-icon"><x-icon name="logout"/></span><span><b class="inhouse-stat-value">{{ number_format($inHouseSummary['dueToday']) }}</b><span class="inhouse-stat-label">Departing today</span></span></div>
      <div class="inhouse-stat"><span class="inhouse-stat-icon"><x-icon name="clock"/></span><span><b class="inhouse-stat-value">{{ number_format($inHouseSummary['pastDue']) }}</b><span class="inhouse-stat-label">Past departure date</span></span></div>
    </section>
    <section class="inhouse-manifest" aria-labelledby="inhouse-manifest-title">
      <header class="inhouse-manifest-head"><div class="inhouse-manifest-title"><h2 id="inhouse-manifest-title">In-house roster <span class="badge badge-green">{{ number_format($inHouseSummary['stays']) }}</span></h2><p>Live checked-in room stays · Sorted by room</p></div><label class="sr-only" for="inhouse-manifest-search">Search in-house guests</label><input class="inhouse-search no-print" id="inhouse-manifest-search" type="search" placeholder="Search guest, room, phone or booking…" data-filter-table="#stayTable" autocomplete="off"></header>
      <div class="inhouse-table-wrap"><table class="inhouse-table" id="stayTable">
        <thead><tr><th scope="col">Guest</th><th scope="col">Room</th><th scope="col">Stay &amp; departure</th><th scope="col">Reservation</th>@if($canViewFolio)<th scope="col">Folio</th>@endif<th scope="col" class="no-print">Actions</th></tr></thead>
        <tbody>
        @forelse($stays as $s)
          @php
            $guest = $s->reservation?->guest;
            $guestName = $guest?->full_name ?: 'Guest details unavailable';
            $initials = collect(explode(' ', trim($guestName)))->filter()->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('');
            $nightsLeft = $inHouseNightsLeft($s);
            $departingToday = $s->departure_date->isSameDay($inHouseDate);
            $pastDeparture = $s->departure_date->lt($inHouseDate);
            $balance = (float) ($s->folio?->listing_balance ?? 0);
          @endphp
          <tr>
            <td><div class="inhouse-guest"><span class="inhouse-avatar" aria-hidden="true">{{ $initials ?: '?' }}</span><span><span class="inhouse-guest-name">{{ $guestName }} @if($guest?->is_vip)<span class="inhouse-badge inhouse-badge-vip">VIP</span>@endif @if($s->is_day_use)<span class="inhouse-badge inhouse-badge-dayuse">Day use</span>@endif</span><span class="inhouse-meta">@if($guest?->phone)<x-icon name="phone" size="sm"/> {{ $guest->phone }}@else Phone not provided @endif</span>@if($s->reservation?->company)<span class="inhouse-subline">{{ $s->reservation->company->name }}</span>@endif</span></div></td>
            <td>@if($s->room)<span class="inhouse-room-number">Room {{ $s->room->room_no }}</span><span class="inhouse-room-type">{{ $s->roomType?->name ?? $s->roomType?->code ?? 'Room type not set' }}</span>@if($inHouseHousekeepingVisible && $s->room->hk_status)<span class="inhouse-subline">Housekeeping: {{ ucwords(str_replace('_', ' ', $s->room->hk_status)) }}</span>@endif @else<span class="inhouse-room-number">Room not assigned</span><span class="inhouse-room-type">{{ $s->roomType?->name ?? $s->roomType?->code ?? 'Room type not set' }}</span>@endif</td>
            <td><span>Arrived <time datetime="{{ $s->arrival_date->toDateString() }}">{{ $s->arrival_date->format('d M Y') }}</time></span><span class="inhouse-subline">Due <time datetime="{{ $s->departure_date->toDateString() }}">{{ $s->departure_date->format('d M Y') }}</time>@if($s->is_day_use) · day use {{ $s->dayUseUntilLabel() ?? 'today' }}, room free again tonight @endif</span><span class="inhouse-subline">{{ $s->adults }} {{ \Illuminate\Support\Str::plural('adult', $s->adults) }}@if($s->children > 0), {{ $s->children }} {{ \Illuminate\Support\Str::plural('child', $s->children) }}@endif · {{ $nightsLeft }} {{ \Illuminate\Support\Str::plural('night', $nightsLeft) }} left</span>@if($pastDeparture)<span class="inhouse-badge inhouse-badge-overdue">{{ $s->departure_date->diffInDays($inHouseDate) }} days past due</span>@elseif($departingToday)<span class="inhouse-badge inhouse-badge-due">Departing today</span>@else<span class="inhouse-badge inhouse-badge-current">In-house</span>@endif</td>
            <td>@if($s->reservation && can_do('reservations.view'))<a href="{{ route('reservations.show', $s->reservation) }}">{{ $s->reservation->reservation_no }}</a>@elseif($s->reservation){{ $s->reservation->reservation_no }}@else<span class="muted">—</span>@endif<span class="inhouse-subline">{{ $s->reservation?->businessSource?->name ?? 'Direct booking' }}</span></td>
            @if($canViewFolio)<td class="inhouse-folio">@if($s->folio)<a href="{{ route('folios.show', $s->folio) }}">Folio {{ $s->folio->folio_no }}</a><span class="inhouse-folio-balance {{ $balance > 0 ? 'text-red' : 'text-green' }}">{{ money($balance) }} {{ $balance > 0 ? 'due' : ($balance < 0 ? 'credit' : 'settled') }}</span>@else<span class="muted">No folio linked</span>@endif</td>@endif
            <td class="no-print"><div class="inhouse-actions">
              @if(can_do('frontdesk.roommove'))<a class="btn btn-sm" href="{{ route('frontdesk.move', $s) }}" aria-label="Move {{ $guestName }} to another room"><x-icon name="swap" size="sm"/> Move</a>@endif
              @if(can_do('frontdesk.checkout'))<a class="btn btn-warning btn-sm" href="{{ route('frontdesk.checkout', $s) }}" aria-label="Check out {{ $guestName }} from room {{ $s->room?->room_no ?? 'unassigned' }}"><x-icon name="logout" size="sm"/> Check out</a>@elseif($s->reservation && can_do('reservations.view'))<a class="btn btn-sm" href="{{ route('reservations.show', $s->reservation) }}">View booking</a>@endif
              @if(can_do('requests.manage'))<a class="btn btn-ghost btn-sm" href="{{ route('requests.portal', $s) }}" aria-label="Guest portal for {{ $guestName }}" title="Guest portal / QR"><x-icon name="qr" size="sm"/></a>@endif
            </div></td>
          </tr>
        @empty
          <tr><td colspan="{{ $canViewFolio ? 6 : 5 }}"><div class="inhouse-empty"><span class="inhouse-empty-icon"><x-icon name="bed"/></span><h3>No guests currently in-house</h3><p>No room stays are checked in for this property. Browse today's arrivals or the room view to continue desk operations.</p><div class="inhouse-empty-actions"><a class="btn btn-primary" href="{{ route('frontdesk.arrivals') }}">Open arrivals</a><a class="btn" href="{{ route('frontdesk.rooms') }}">Open room view</a></div></div></td></tr>
        @endforelse
        </tbody>
      </table></div>
    </section>
    <p class="inhouse-footnote">Departure status is compared with the hotel business date. Past-due stays remain in-house until front desk completes check-out.</p>
  </div>
@else
  <div class="card flush"><div class="card-body table-wrap">
  <table class="table" id="stayTable">
    <thead><tr><th>Room</th><th>Guest</th><th>Res. No</th><th>Arrival</th><th>Departure</th><th>Pax</th><th class="num">Rate</th>@if($canViewFolio)<th class="num">Balance</th>@endif<th class="right no-print">Action</th></tr></thead>
    <tbody>
    @forelse($stays as $s)
      <tr>
        <td class="bold">{{ $s->room?->room_no ?? '—' }}<div class="tiny muted">{{ $s->roomType->code }}</div></td>
        <td>{{ $s->reservation->guest->full_name }} @if($s->reservation->guest->is_vip)<span class="badge badge-purple">VIP</span>@endif @if($s->reservation->prechecked_in_at && $s->status === 'reserved')<span class="badge badge-green" title="Online check-in completed {{ $s->reservation->prechecked_in_at->format('d M H:i') }}">Pre-checked-in</span>@endif<div class="tiny muted">{{ $s->reservation->guest->phone }} {{ $s->reservation->company ? '· '.$s->reservation->company->name : '' }}</div></td>
        <td>@if(can_do('reservations.view'))<a href="{{ route('reservations.show', $s->reservation_id) }}">{{ $s->reservation->reservation_no }}</a>@else{{ $s->reservation->reservation_no }}@endif</td>
        <td class="nowrap">{{ $s->arrival_date->format('d M') }} @if($s->status==='reserved' && $s->arrival_date->lt(bdate()))<span class="badge badge-red">Overdue</span>@endif</td>
        <td class="nowrap">{{ $s->departure_date->format('d M') }}</td>
        <td>{{ $s->adults }}/{{ $s->children }}</td>
        <td class="num">{{ money($s->rate) }}</td>
        @if($canViewFolio)<td class="num {{ ($s->folio?->listing_balance ?? 0) > 0 ? 'text-red' : 'text-green' }}">{{ money($s->folio?->listing_balance ?? 0) }}</td>@endif
        <td class="right nowrap no-print">
          @if($mode === 'arrivals' && can_do('frontdesk.checkin'))<a class="btn btn-sm btn-success" href="{{ route('frontdesk.checkin', $s) }}"><x-icon name="login" size="sm"/> Check-in</a>@endif
          @if($mode !== 'arrivals')
            @if($canViewFolio && $s->folio)<a class="btn btn-sm" href="{{ route('folios.show', $s->folio) }}">Folio</a>@endif
            @if(can_do('requests.manage'))<a class="btn btn-sm btn-ghost" href="{{ route('requests.portal', $s) }}" title="Guest portal link / QR"><x-icon name="qr" size="sm"/></a>@endif
            @if(can_do('frontdesk.checkout'))<a class="btn btn-sm btn-warning" href="{{ route('frontdesk.checkout', $s) }}">Check-out</a>@endif
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="9"><x-empty :text="'No '.strtolower($title).' for this date.'" icon="bed"/></td></tr>
    @endforelse
    </tbody>
  </table>
  </div></div>
@endif
@endsection
