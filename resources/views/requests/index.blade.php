@extends('layouts.app')
@section('title', 'Guest Requests')
@section('crumbs') Front Office / Guest Requests @endsection
@section('actions')
  <a class="btn grq-offline" href="{{ route('offline.guest_requests') }}"><x-icon name="wifi"/> Offline capture</a>
  @if(can_do('requests.manage'))<button class="btn btn-primary" type="button" data-modal="newReq"><x-icon name="plus"/> Log request</button>@endif
@endsection

@push('head')
<style>
.grq-page{display:grid;gap:1rem;max-width:1500px;margin:0 auto}
.grq-intro{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:.9rem 1.1rem;border:1px solid #cfe3d5;border-radius:14px;background:linear-gradient(105deg,#f0fdf4,#f8fbf8 58%,#fff);color:#163923}
.grq-intro-copy{min-width:0}.grq-eyebrow{display:flex;align-items:center;gap:.45rem;margin:0 0 .2rem;color:#166534;font-size:.68rem;font-weight:800;letter-spacing:.09em;text-transform:uppercase}
.grq-eyebrow-dot,.grq-live-dot{width:8px;height:8px;flex:none;border-radius:50%;background:#16a34a;box-shadow:0 0 0 3px rgba(22,163,74,.12)}
.grq-intro-copy p{margin:0;color:#526b5a;font-size:.82rem}.grq-live{display:inline-flex;align-items:center;gap:.5rem;white-space:nowrap;color:#326342;font-size:.75rem;font-weight:650}
.grq-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.8rem}.grq-summary .stat{min-width:0;margin:0;border-radius:12px;box-shadow:0 3px 12px rgba(15,23,42,.035)}
.grq-panel{overflow:hidden;border:1px solid var(--c-border);border-radius:14px;background:var(--c-surface);box-shadow:0 5px 18px rgba(15,23,42,.035)}
.grq-panel-head{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1rem 1.1rem .8rem}.grq-panel-head h2{margin:0;font-size:1.05rem;letter-spacing:-.015em}.grq-panel-head p{margin:.15rem 0 0;color:var(--c-muted);font-size:.78rem}
.grq-result-count{flex:none;padding:.35rem .65rem;border:1px solid var(--c-border);border-radius:999px;background:var(--c-bg);color:var(--c-text-2);font-size:.75rem;font-weight:700;white-space:nowrap}
.grq-tabs{display:flex;gap:.28rem;overflow-x:auto;padding:0 1rem;border-bottom:1px solid var(--c-border);scrollbar-width:thin}
.grq-tabs a{display:inline-flex;align-items:center;gap:.4rem;flex:none;padding:.65rem .72rem .58rem;border-bottom:2px solid transparent;color:var(--c-text-2);font-size:.8rem;font-weight:650;text-decoration:none;white-space:nowrap;transition:background .15s,color .15s,border-color .15s}
.grq-tabs a:hover{background:var(--c-primary-50);color:var(--c-primary-600)}.grq-tabs a.active{border-bottom-color:var(--c-primary);color:var(--c-primary-600)}
.grq-tab-count{display:inline-grid;min-width:1.25rem;height:1.25rem;place-items:center;padding:0 .3rem;border-radius:999px;background:var(--c-bg);color:var(--c-muted);font-size:.66rem;font-weight:750}.grq-tabs a.active .grq-tab-count{background:#dcfce7;color:#14532d}
.grq-filters{display:grid;grid-template-columns:minmax(210px,1.2fr) minmax(150px,.8fr) minmax(120px,.55fr) auto auto;align-items:end;gap:.7rem;padding:.85rem 1rem;border-bottom:1px solid var(--c-border);background:var(--c-bg)}
.grq-filters .field{min-width:0;margin:0}.grq-filters .field label{display:block;margin:0 0 .25rem;color:var(--c-muted);font-size:.68rem;font-weight:750;letter-spacing:.045em;text-transform:uppercase}.grq-filters .input{min-height:38px}.grq-search{position:relative}.grq-search .input{padding-left:2.15rem}.grq-search-icon{position:absolute;top:50%;left:.72rem;display:grid;place-items:center;color:var(--c-muted);transform:translateY(-50%);pointer-events:none}.grq-search-icon .i{width:15px;height:15px}
.grq-mine{display:flex;align-items:center;gap:.45rem;min-height:38px;padding:0 .2rem;color:var(--c-text-2);font-size:.78rem;white-space:nowrap}.grq-mine input{width:16px;height:16px;accent-color:var(--c-primary)}
.grq-filter-actions{display:flex;align-items:center;gap:.45rem}.grq-filter-actions .btn{min-height:38px}.grq-clear{font-size:.75rem;font-weight:650;white-space:nowrap}
.grq-list{display:grid;gap:.7rem;padding:.85rem}.grq-card{position:relative;display:grid;gap:.72rem;padding:.9rem 1rem;border:1px solid var(--c-border);border-radius:12px;background:var(--c-surface);transition:border-color .15s,box-shadow .15s,transform .15s}
.grq-card:hover{border-color:#b8d5c0;box-shadow:0 5px 16px rgba(15,23,42,.055);transform:translateY(-1px)}.grq-card.is-overdue{border-color:#fecaca;box-shadow:inset 4px 0 0 #dc2626,0 3px 12px rgba(127,29,29,.04)}
.grq-card-top{display:flex;align-items:center;justify-content:space-between;gap:.8rem}.grq-request-id{display:flex;align-items:center;flex-wrap:wrap;gap:.55rem}.grq-request-id strong{color:var(--c-text);font-size:.83rem;letter-spacing:.015em}.grq-source{display:inline-flex;align-items:center;gap:.3rem;color:var(--c-muted);font-size:.72rem}.grq-source .i{width:14px;height:14px}
.grq-badges{display:flex;align-items:center;flex-wrap:wrap;justify-content:flex-end;gap:.35rem}.grq-badges .badge{white-space:nowrap}
.grq-card-main{display:grid;grid-template-columns:minmax(0,1fr) minmax(180px,.35fr);gap:1rem;align-items:center}.grq-request-copy{min-width:0}.grq-category{display:inline-flex;align-items:center;gap:.35rem;margin-bottom:.28rem;color:#17633a;font-size:.7rem;font-weight:750}.grq-category .i{width:15px;height:15px}
.grq-item-title{margin:0 0 .2rem;color:var(--c-text);font-size:.97rem;font-weight:750;line-height:1.35;overflow-wrap:anywhere}.grq-copy{margin:.22rem 0 0;color:var(--c-text-2);font-size:.78rem;line-height:1.45;overflow-wrap:anywhere}.grq-copy.staff{color:#1d4ed8}.grq-linked{display:inline-flex;align-items:center;gap:.3rem;margin-top:.35rem;font-size:.75rem;font-weight:700}
.grq-stay{display:flex;align-items:center;gap:.7rem;min-width:0;padding:.6rem .7rem;border:1px solid var(--c-border);border-radius:10px;background:var(--c-bg)}.grq-room-number{display:grid;width:42px;height:42px;flex:none;place-items:center;border-radius:9px;background:#e8f5eb;color:#14532d;font-size:.8rem;font-weight:850}.grq-stay-copy{min-width:0}.grq-stay-label{display:block;margin-bottom:.08rem;color:var(--c-muted);font-size:.64rem;font-weight:750;letter-spacing:.05em;text-transform:uppercase}.grq-guest-name{display:block;overflow:hidden;color:var(--c-text);font-size:.8rem;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.grq-preferred{display:inline-flex;align-items:center;gap:.3rem;margin-top:.35rem;color:var(--c-text-2);font-size:.72rem}.grq-preferred .i{width:14px;height:14px}
.grq-card-bottom{display:flex;align-items:center;justify-content:space-between;gap:.8rem;padding-top:.65rem;border-top:1px solid var(--c-border)}.grq-operations{display:flex;align-items:center;flex-wrap:wrap;gap:.65rem;min-width:0}.grq-wait{display:inline-flex;align-items:center;gap:.35rem;color:var(--c-text-2);font-size:.72rem;font-weight:650;white-space:nowrap}.grq-wait .i{width:14px;height:14px}.grq-wait.is-overdue{color:#b42318}.grq-wait-detail{color:var(--c-muted);font-weight:500}.grq-assignment{display:flex;align-items:center;gap:.4rem}.grq-assignment .input{width:160px;min-height:34px;padding-top:.3rem;padding-bottom:.3rem;font-size:.75rem}
.grq-actions{display:flex;align-items:center;justify-content:flex-end;flex-wrap:wrap;gap:.35rem}.grq-actions form{display:inline-flex}.grq-actions .btn{min-height:32px}.grq-actions .grq-decline{color:#b42318}.grq-portal-link{display:inline-grid;width:32px;height:32px;place-items:center;border:1px solid var(--c-border);border-radius:8px;color:var(--c-text-2)}.grq-portal-link:hover{background:var(--c-bg);color:var(--c-primary)}
.grq-empty{display:grid;justify-items:center;gap:.4rem;padding:3rem 1.25rem;text-align:center}.grq-empty-icon{display:grid;width:52px;height:52px;place-items:center;border-radius:15px;background:#eaf6ed;color:#166534}.grq-empty-icon .i{width:24px;height:24px}.grq-empty h3{margin:.3rem 0 0;font-size:1rem}.grq-empty p{max-width:430px;margin:0;color:var(--c-muted);font-size:.82rem}.grq-empty .btn{margin-top:.55rem}.grq-pagination{padding:0 1rem 1rem}
.grq-modal .modal-box{max-width:720px}.grq-modal .modal-head{background:var(--c-bg)}.grq-modal .modal-body{padding:1rem}.grq-modal .modal-foot{padding:.8rem 1rem;border-top:1px solid var(--c-border)}
body.dark .grq-intro{border-color:#31533a;background:linear-gradient(105deg,#142a1d,#17271e 58%,#182a20);color:#e8f1e9}body.dark .grq-intro-copy p{color:#c5d6c8}body.dark .grq-eyebrow,body.dark .grq-live{color:#a8e4b5}body.dark .grq-room-number{background:#244b31;color:#bbf7d0}body.dark .grq-category{color:#a7f3c0}body.dark .grq-stay{background:#101d15}body.dark .grq-tab-count{background:#1c2b22;color:#abc1b0}body.dark .grq-tabs a.active .grq-tab-count{background:#244b31;color:#bbf7d0}
@media(max-width:1050px){.grq-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.grq-filters{grid-template-columns:minmax(200px,1fr) minmax(140px,.7fr) auto auto}.grq-filters .grq-room-field{grid-column:2}.grq-mine{grid-column:1}.grq-filter-actions{grid-column:3 / -1;justify-self:end}}
@media(max-width:700px){.grq-intro{align-items:flex-start;flex-direction:column;gap:.55rem;padding:.8rem .9rem}.grq-live{font-size:.7rem}.grq-panel-head{padding:.85rem .85rem .7rem}.grq-tabs{padding:0 .55rem}.grq-tabs a{padding:.62rem .58rem;font-size:.75rem}.grq-filters{grid-template-columns:minmax(0,1fr) minmax(120px,.7fr);gap:.55rem;padding:.7rem}.grq-filters .grq-room-field{grid-column:auto}.grq-mine{grid-column:1 / -1;min-height:28px}.grq-filter-actions{grid-column:1 / -1;justify-self:stretch}.grq-filter-actions .btn{flex:1;justify-content:center}.grq-list{gap:.6rem;padding:.6rem}.grq-card{gap:.6rem;padding:.78rem}.grq-card-top{align-items:flex-start;flex-direction:column;gap:.35rem}.grq-badges{justify-content:flex-start}.grq-card-main{grid-template-columns:1fr;gap:.55rem}.grq-stay{max-width:none}.grq-card-bottom{align-items:stretch;flex-direction:column;gap:.65rem}.grq-operations{justify-content:space-between;gap:.45rem}.grq-actions{justify-content:flex-start}.grq-assignment{flex:1}.grq-assignment .input{width:100%;max-width:220px}}
@media(max-width:420px){.grq-summary{grid-template-columns:1fr 1fr;gap:.5rem}.grq-summary .stat{padding:.7rem}.grq-summary .stat .v{font-size:1.1rem}.grq-result-count{font-size:.68rem;padding:.3rem .45rem}.grq-panel-head h2{font-size:.98rem}.grq-request-id{gap:.35rem}.grq-copy{font-size:.75rem}}
</style>
@endpush

@section('content')
@php
  $sla = max(1, (int) setting('portal_sla_minutes', 15));
  $cats = \App\Models\GuestRequest::CATEGORIES;
  $manage = can_do('requests.manage');
  $tabQuery = array_filter(['q' => $search, 'category' => $category, 'room' => $room, 'mine' => request()->filled('mine') ? '1' : null], static fn ($value) => $value !== '' && $value !== null);
  $tabs = ['open' => 'Open', 'new' => 'New', 'overdue' => 'Overdue', 'working' => 'Working', 'today' => 'Received today', 'done' => 'Done', 'declined' => 'Declined', 'cancelled' => 'Cancelled', 'all' => 'All'];
  $tabCounts = [
    'open' => $queueCounts->only(\App\Models\GuestRequest::OPEN)->sum(),
    'new' => (int) $queueCounts->get('new', 0),
    'overdue' => (int) $stats['overdue'],
    'working' => (int) $stats['working'],
    'today' => (int) $todayCount,
    'done' => (int) $queueCounts->get('done', 0),
    'declined' => (int) $queueCounts->get('declined', 0),
    'cancelled' => (int) $queueCounts->get('cancelled', 0),
    'all' => $queueCounts->sum(),
  ];
  $hasFilters = $search !== '' || $category !== '' || $room !== '' || request()->filled('mine');
@endphp
<div class="grq-page">
  <section class="grq-intro" aria-label="Guest request queue overview">
    <div class="grq-intro-copy">
      <p class="grq-eyebrow"><span class="grq-eyebrow-dot" aria-hidden="true"></span> Front-office service desk</p>
      <p>Keep guest requests moving from first response to completion. New portal and desk requests are checked against a {{ $sla }}-minute response target.</p>
    </div>
    <div class="grq-live" id="liveNote" role="status"><span class="grq-live-dot" aria-hidden="true"></span> Live queue · checks every 30 sec</div>
  </section>

  <section class="grq-summary" aria-label="Guest request service metrics">
    <x-stat label="Awaiting response" :value="$stats['new']" icon="bell" tone="amber" :href="route('requests.index', ['status' => 'new'])" sub="New requests not yet acknowledged"/>
    <x-stat label="Past response target" :value="$stats['overdue']" icon="alert" :tone="$stats['overdue'] ? 'red' : 'green'" :href="route('requests.index', ['status' => 'overdue'])" :sub="$sla.'-minute response target'"/>
    <x-stat label="Being handled" :value="$stats['working']" icon="clock" tone="blue" :href="route('requests.index', ['status' => 'working'])" sub="Acknowledged or in progress"/>
    <x-stat label="Completed today" :value="$stats['done']" icon="check" tone="green" :sub="($stats['avg_response'] !== null ? 'Avg. first response '.round($stats['avg_response']).' min' : 'No responses recorded today').($stats['rating'] ? ' · Guest rating '.number_format($stats['rating'], 1).' / 5' : '')"/>
  </section>

  <section class="grq-panel" aria-label="Guest request queue">
    <div class="grq-panel-head">
      <div><h2>Request queue</h2><p>Prioritised by status, urgency and time received.</p></div>
      <div class="grq-result-count" aria-live="polite">{{ $requests->total() }} {{ \Illuminate\Support\Str::plural('request', $requests->total()) }}</div>
    </div>

    <nav class="grq-tabs" aria-label="Filter requests by status">
      @foreach($tabs as $key => $label)
        <a href="{{ route('requests.index', array_merge($tabQuery, ['status' => $key])) }}" class="{{ $status === $key ? 'active' : '' }}" @if($status === $key) aria-current="page" @endif>
          {{ $label }}
          @if(array_key_exists($key, $tabCounts))<span class="grq-tab-count">{{ $tabCounts[$key] }}</span>@endif
        </a>
      @endforeach
    </nav>

    <form class="grq-filters" method="get" action="{{ route('requests.index') }}" role="search" aria-label="Search and filter guest requests">
      <input type="hidden" name="status" value="{{ $status }}">
      <div class="field">
        <label for="grqSearch">Search queue</label>
        <div class="grq-search"><span class="grq-search-icon" aria-hidden="true"><x-icon name="search" size="sm"/></span><input id="grqSearch" name="q" value="{{ $search }}" maxlength="100" placeholder="Request, guest, item or note" class="input input-sm" type="search" autocomplete="off"></div>
      </div>
      <div class="field">
        <label for="grqCategory">Request type</label>
        <select id="grqCategory" name="category" class="input input-sm"><option value="">All request types</option>@foreach($cats as $key => $category)<option value="{{ $key }}" @selected($category === $key)>{{ $category[0] }}</option>@endforeach</select>
      </div>
      <div class="field grq-room-field">
        <label for="grqRoom">Room</label>
        <input id="grqRoom" name="room" value="{{ $room }}" placeholder="Room no." class="input input-sm" maxlength="20" autocomplete="off">
      </div>
      <label class="grq-mine"><input type="checkbox" name="mine" value="1" @checked(request()->filled('mine'))> Assigned to me</label>
      <div class="grq-filter-actions">
        <button class="btn btn-primary btn-sm" type="submit"><x-icon name="search" size="sm"/> Apply filters</button>
        @if($hasFilters)<a class="grq-clear" href="{{ route('requests.index', ['status' => $status]) }}">Clear</a>@endif
      </div>
    </form>

    <div class="grq-list">
      @forelse($requests as $r)
        @php
          $g = $r->stay?->guest ?? $r->stay?->reservation?->guest;
          $over = $r->isOverdue();
          $cat = $cats[$r->category] ?? [$r->categoryLabel(), 'bell', []];
          $source = match ($r->source) {
            'portal' => 'Guest portal',
            'tv' => 'In-room TV',
            'chat' => 'Guest message',
            default => $r->handler?->name ? 'Logged by '.$r->handler->name : 'Front desk',
          };
          $linked = $r->linked_type ? $r->linked() : null;
        @endphp
        <article class="grq-card {{ $over ? 'is-overdue' : '' }}" aria-label="{{ $r->request_no }} — {{ $r->item }}">
          <div class="grq-card-top">
            <div class="grq-request-id"><strong>{{ $r->request_no }}</strong><span class="grq-source"><x-icon name="{{ $r->source === 'portal' ? 'globe' : ($r->source === 'tv' ? 'bed' : ($r->source === 'chat' ? 'message' : 'edit')) }}" size="sm"/> {{ $source }} · {{ $r->created_at->format('d M, H:i') }}</span></div>
            <div class="grq-badges">{!! status_badge($r->status) !!}@if($r->priority === 'high')<span class="badge badge-red">High priority</span>@endif @if($over)<span class="badge badge-red">Past target</span>@endif</div>
          </div>

          <div class="grq-card-main">
            <div class="grq-request-copy">
              <span class="grq-category"><x-icon :name="$cat[1]" size="sm"/> {{ $cat[0] }}</span>
              <h3 class="grq-item-title">{{ $r->quantity > 1 ? $r->quantity.' × ' : '' }}{{ $r->item ?: $r->categoryLabel() }}</h3>
              @if($r->notes)<p class="grq-copy">{{ \Illuminate\Support\Str::limit($r->notes, 180) }}</p>@endif
              @if($r->staff_note)<p class="grq-copy staff"><b>Team update:</b> {{ $r->staff_note }}</p>@endif
              @if($linked_type = $r->linked_type)
                <a class="grq-linked" href="{{ $linked_type === 'MaintenanceRequest' ? route('maintenance.index') : route('housekeeping.tasks') }}">
                  <x-icon :name="$linked_type === 'MaintenanceRequest' ? 'tool' : 'broom'" size="sm"/>
                  {{ $linked_type === 'MaintenanceRequest' ? 'Maintenance ticket '.($linked?->ticket_no ?? '#'.$r->linked_id) : 'Housekeeping task #'.$r->linked_id }}
                </a>
              @endif
            </div>
            <div>
              <div class="grq-stay">
                <span class="grq-room-number" aria-label="Room {{ $r->room?->room_no ?? 'not assigned' }}">{{ $r->room?->room_no ?? '—' }}</span>
                <span class="grq-stay-copy"><span class="grq-stay-label">Room / guest</span><span class="grq-guest-name">{{ $g?->full_name ?? 'Guest not linked' }}</span></span>
              </div>
              @if($r->preferred_at)<span class="grq-preferred"><x-icon name="clock" size="sm"/> Requested for {{ $r->preferred_at->format('D, d M · H:i') }}</span>@endif
            </div>
          </div>

          <div class="grq-card-bottom">
            <div class="grq-operations">
              <span class="grq-wait {{ $over ? 'is-overdue' : '' }}"><x-icon name="clock" size="sm"/>
                @if($r->isOpen()){{ $r->waitingMinutes() }} min <span class="grq-wait-detail">{{ $r->acknowledged_at ? 'open' : 'awaiting response' }}</span>
                @elseif($r->completed_at){{ $r->created_at->diffInMinutes($r->completed_at) }} min total <span class="grq-wait-detail">· completed {{ $r->completed_at->format('H:i') }}</span>
                @else<span class="grq-wait-detail">No active timer</span>@endif
              </span>
              @if($r->rating)<span class="grq-wait" aria-label="Guest rating {{ $r->rating }} out of 5" style="color:#b7791f">{{ str_repeat('★', $r->rating) }}<span class="grq-wait-detail">{{ $r->rating }}/5</span></span>@endif
              @if($manage && $r->isOpen())
                <form class="grq-assignment" method="post" action="{{ route('requests.assign', $r) }}">@csrf
                  <label class="sr-only" for="grqAssignee{{ $r->id }}">Assign {{ $r->request_no }} to a team member</label>
                  <select id="grqAssignee{{ $r->id }}" name="assigned_to" class="input input-sm" onchange="this.form.submit()"><option value="">Unassigned</option>@foreach($staff as $id => $name)<option value="{{ $id }}" @selected($r->assigned_to === $id)>{{ $name }}</option>@endforeach</select>
                </form>
              @elseif($r->assignee)<span class="grq-wait"><x-icon name="users" size="sm"/> {{ $r->assignee->name }}</span>@endif
            </div>

            <div class="grq-actions" aria-label="Actions for {{ $r->request_no }}">
              @if($r->category === 'laundry' && $r->isOpen() && can_do('laundry.orders'))
                @if($laundryOrder = \App\Models\LaundryOrder::where('guest_request_id', $r->id)->first())<a class="btn btn-sm" href="{{ route('laundry.show', $laundryOrder) }}"><x-icon name="shirt" size="sm"/> {{ $laundryOrder->order_no }}</a>@else<a class="btn btn-sm" href="{{ route('laundry.create', ['request' => $r->id]) }}"><x-icon name="shirt" size="sm"/> Collect laundry</a>@endif
              @endif
              @if($manage && $r->isOpen())
                @if($r->status === 'new')<form method="post" action="{{ route('requests.action', [$r, 'acknowledge']) }}">@csrf<button class="btn btn-sm" type="submit"><x-icon name="check" size="sm"/> Acknowledge</button></form>@endif
                @if($r->status !== 'in_progress')<form method="post" action="{{ route('requests.action', [$r, 'start']) }}">@csrf<button class="btn btn-sm" type="submit"><x-icon name="chev" size="sm"/> Start</button></form>@endif
                <button class="btn btn-sm btn-success" type="button" data-modal="doneModal" data-action="{{ route('requests.action', [$r, 'done']) }}" data-ftitle="{{ $r->request_no }}"><x-icon name="check" size="sm"/> Complete</button>
                <button class="btn btn-sm btn-ghost grq-decline" type="button" data-modal="declineModal" data-action="{{ route('requests.action', [$r, 'decline']) }}"><x-icon name="x" size="sm"/> Decline</button>
              @endif
              @if($manage && $r->stay)<a class="grq-portal-link" href="{{ route('requests.portal', $r->stay) }}" aria-label="Open guest portal for {{ $r->request_no }}" title="Open guest portal"><x-icon name="qr" size="sm"/></a>@endif
            </div>
          </div>
        </article>
      @empty
        <div class="grq-empty" role="status">
          <span class="grq-empty-icon"><x-icon name="bell"/></span>
          <h3>{{ $hasFilters ? 'No matching guest requests' : ($status === 'open' ? 'You’re all caught up' : 'No requests in this view') }}</h3>
          <p>{{ $hasFilters ? 'Try a different search or remove one or more filters.' : ($status === 'open' ? 'New requests from the guest portal, in-room TV and front desk will appear here.' : 'Choose another queue status to review guest requests.') }}</p>
          @if($hasFilters)<a class="btn btn-sm" href="{{ route('requests.index', ['status' => $status]) }}">Clear filters</a>@elseif($manage)<button class="btn btn-primary btn-sm" type="button" data-modal="newReq"><x-icon name="plus" size="sm"/> Log a request</button>@endif
        </div>
      @endforelse
    </div>
    @if($requests->hasPages())<div class="grq-pagination">{{ $requests->links() }}</div>@endif
  </section>
</div>

@if($manage)
<div class="modal grq-modal" id="newReq"><div class="modal-box"><form method="post" action="{{ route('requests.store') }}">@csrf
  <div class="modal-head"><h3><x-icon name="bell"/> Log a guest request</h3><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close request form"><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <div class="field span-all"><label for="nrStay">In-house guest *</label><select id="nrStay" name="reservation_room_id" required class="input"><option value="">Select room and guest</option>@foreach($stays as $s)<option value="{{ $s->id }}" @selected(old('reservation_room_id') == $s->id)>Room {{ $s->room?->room_no }} · {{ ($s->guest ?? $s->reservation?->guest)?->full_name }}</option>@endforeach</select></div>
    <div class="field"><label for="nrCat">Request type *</label><select name="category" id="nrCat" required class="input">@foreach($cats as $key => $category)<option value="{{ $key }}" @selected(old('category') === $key)>{{ $category[0] }}</option>@endforeach</select></div>
    <div class="field"><label for="nrItem">Request item</label><select name="item" id="nrItem" class="input"></select></div>
    <div class="field"><label for="nrQuantity">Quantity</label><input id="nrQuantity" type="number" name="quantity" min="1" max="10" value="{{ old('quantity', 1) }}" class="input"></div>
    <div class="field"><label for="nrPreferred">For (date &amp; time)</label><input id="nrPreferred" type="datetime-local" name="preferred_at" value="{{ old('preferred_at') }}" class="input"><span class="hint">Set a time for wake-up calls and transport.</span></div>
    <div class="field"><label for="nrTime">Late check-out time</label><input id="nrTime" type="time" name="time" value="{{ old('time', '14:00') }}" class="input"></div>
    <div class="field span-all"><label for="nrNotes">Notes for the team</label><textarea id="nrNotes" name="notes" rows="3" maxlength="500" class="input" placeholder="Add useful context for the team">{{ old('notes') }}</textarea></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" type="submit"><x-icon name="plus"/> Log request</button></div>
</form></div></div>
<div class="modal grq-modal" id="doneModal"><div class="modal-box"><form method="post" action="">@csrf
  <div class="modal-head"><h3><x-icon name="check"/> Complete request <span data-request-title></span></h3><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close completion dialog"><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label for="doneNote">Note for the guest <span class="muted">(optional)</span></label><input id="doneNote" name="note" maxlength="255" class="input" placeholder="e.g. Fresh towels delivered"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-success" type="submit"><x-icon name="check"/> Mark complete</button></div>
</form></div></div>
<div class="modal grq-modal" id="declineModal"><div class="modal-box"><form method="post" action="">@csrf
  <div class="modal-head"><h3><x-icon name="x"/> Decline request</h3><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close decline dialog"><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label for="declineNote">Reason shown to the guest *</label><input id="declineNote" name="note" maxlength="255" required class="input" placeholder="Explain why this request cannot be fulfilled"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger" type="submit"><x-icon name="x"/> Decline request</button></div>
</form></div></div>
@endif
@endsection

@push('scripts')
<script>
(() => {
  const items = @json(collect($cats)->map(fn ($category, $key) => \App\Services\GuestPortalService::items($key)));
  const category = document.getElementById('nrCat');
  const item = document.getElementById('nrItem');
  const fillItems = () => {
    if (!category || !item) return;
    item.replaceChildren();
    for (const value of items[category.value] || []) {
      const option = document.createElement('option');
      option.value = value;
      option.textContent = value;
      item.append(option);
    }
  };
  category?.addEventListener('change', fillItems);
  fillItems();

  let latest = {{ (int) \App\Models\GuestRequest::max('id') }};
  const liveNote = document.getElementById('liveNote');
  window.setInterval(() => {
    if (document.querySelector('.modal.open, .modal.show, .modal[aria-hidden="false"]')) return;
    fetch(@json(route('requests.count')), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
      .then(response => response.ok ? response.json() : Promise.reject())
      .then(data => {
        if (data.latest > latest && liveNote) {
          liveNote.textContent = 'New request received · refreshing queue…';
          window.setTimeout(() => window.location.reload(), 800);
        }
        latest = Math.max(latest, Number(data.latest) || 0);
      }).catch(() => {});
  }, 30000);

  document.querySelectorAll('[data-ftitle]').forEach(button => button.addEventListener('click', () => {
    const title = document.querySelector('[data-request-title]');
    if (title) title.textContent = button.dataset.ftitle || '';
  }));
})();
</script>
@endpush
