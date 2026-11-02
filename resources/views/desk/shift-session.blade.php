@extends('layouts.app')
@section('title', 'My Shift')
@section('crumbs') Desk Operations / My Shift @endsection
@section('actions')
  <a class="btn" href="{{ route('my-shift') }}"><x-icon name="clock"/> All shift departments</a>
  <a class="btn" href="{{ route('desk.handover') }}"><x-icon name="swap"/> Handover board</a>
  <a class="btn" href="{{ route('desk.logbook') }}"><x-icon name="book"/> Shift log book</a>
@endsection
@section('content')
@include('desk._workspace-nav')
@php
  $activeOpenEntries = $activeSession ? $activeSession->logEntries()->where('status', 'open')->count() : 0;
  $activeDurationMinutes = $activeSession ? (int) $activeSession->started_at->diffInMinutes(now()) : 0;
@endphp

<style>
  .shift-session-page{--ss-green:#14532d;--ss-line:var(--c-border);--ss-muted:var(--c-text-2)}
  .shift-session-hero{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(250px,.6fr);gap:1rem;margin-bottom:1rem}
  .shift-session-card{background:var(--c-surface);border:1px solid var(--ss-line);border-radius:14px;box-shadow:var(--shadow-sm);overflow:hidden}
  .shift-session-card-head{display:flex;align-items:center;justify-content:space-between;gap:.8rem;padding:1rem 1.1rem;border-bottom:1px solid var(--ss-line)}
  .shift-session-card-head h2,.shift-session-card-head h3{margin:0;font-size:.96rem;letter-spacing:-.015em}
  .shift-session-card-body{padding:1rem 1.1rem}
  .shift-session-active{position:relative;color:#fff;background:linear-gradient(120deg,#123c24 0%,#176239 64%,#257849 100%);border:0;box-shadow:0 12px 28px rgba(20,83,45,.17)}
  .shift-session-active:before{content:"";position:absolute;right:-34px;top:-70px;width:220px;height:220px;border:1px solid rgba(255,255,255,.12);border-radius:50%;box-shadow:0 0 0 28px rgba(255,255,255,.035),0 0 0 58px rgba(255,255,255,.025);pointer-events:none}
  .shift-session-active .shift-session-card-head{position:relative;border-color:rgba(255,255,255,.16)}
  .shift-session-active .shift-session-card-body{position:relative}
  .shift-session-active .muted,.shift-session-active .small{color:rgba(255,255,255,.75)}
  .shift-session-status{display:inline-flex;align-items:center;gap:.42rem;padding:.32rem .62rem;border:1px solid rgba(255,255,255,.24);border-radius:999px;background:rgba(255,255,255,.1);font-size:.68rem;font-weight:750;white-space:nowrap}
  .shift-session-status-dot{width:7px;height:7px;border-radius:50%;background:#8cf0a6;box-shadow:0 0 0 3px rgba(140,240,166,.16)}
  .shift-session-big{margin:.15rem 0 .4rem;font-size:clamp(1.35rem,2.5vw,2rem);font-weight:820;letter-spacing:-.045em;line-height:1.15}
  .shift-session-kpis{display:flex;flex-wrap:wrap;gap:.55rem;margin-top:1rem}
  .shift-session-kpi{min-width:130px;padding:.55rem .7rem;border:1px solid rgba(255,255,255,.18);border-radius:9px;background:rgba(255,255,255,.08)}
  .shift-session-kpi span{display:block;color:rgba(255,255,255,.7);font-size:.65rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
  .shift-session-kpi b{display:block;margin-top:.12rem;font-size:.9rem}
  .shift-session-steps{display:grid;gap:.6rem;margin:0;padding:0;list-style:none}
  .shift-session-steps li{display:flex;align-items:flex-start;gap:.55rem;color:var(--ss-muted);font-size:.77rem}
  .shift-session-steps .step-no{display:grid;place-items:center;flex:0 0 20px;width:20px;height:20px;border-radius:7px;background:#eaf5eb;color:#14532d;font-size:.67rem;font-weight:850}
  .shift-session-note{margin:.75rem 0 0;padding:.65rem .75rem;border-left:3px solid #8ee2a0;border-radius:0 8px 8px 0;background:rgba(255,255,255,.09);white-space:pre-line;font-size:.78rem}
  .shift-session-activity{margin-top:.85rem;padding-top:.75rem;border-top:1px solid var(--ss-line)}
  .shift-session-activity-head{display:flex;align-items:center;justify-content:space-between;gap:.5rem;margin-bottom:.45rem}
  .shift-session-activity-head h3{margin:0;font-size:.72rem;font-weight:800}
  .shift-session-activity-head span{color:var(--ss-muted);font-size:.63rem}
  .shift-session-activity-list{display:grid;gap:.35rem;max-height:240px;overflow:auto}
  .shift-session-activity-row{display:grid;grid-template-columns:3.3rem minmax(0,1fr);gap:.55rem;padding:.42rem .5rem;border:1px solid var(--ss-line);border-radius:8px;background:var(--c-bg)}
  .shift-session-activity-row time{padding-top:.03rem;color:var(--ss-muted);font-family:var(--mono);font-size:.67rem;font-weight:750}
  .shift-session-activity-row b{display:block;font-size:.69rem;line-height:1.35}
  .shift-session-activity-row p{margin:.12rem 0 0;color:var(--ss-muted);font-size:.69rem;line-height:1.4}
  .shift-session-activity-foot{margin:.4rem 0 0;color:var(--ss-muted);font-size:.62rem}
  .shift-session-active .shift-session-activity{border-color:rgba(255,255,255,.2)}
  .shift-session-active .shift-session-activity-head span,.shift-session-active .shift-session-activity-row time,.shift-session-active .shift-session-activity-row p,.shift-session-active .shift-session-activity-foot{color:rgba(255,255,255,.72)}
  .shift-session-active .shift-session-activity-row{border-color:rgba(255,255,255,.18);background:rgba(255,255,255,.07)}
  .shift-session-form{display:grid;gap:.75rem}
  .shift-session-form label{display:block;font-size:.75rem;font-weight:750;margin-bottom:.3rem}
  .shift-session-form select,.shift-session-form textarea,.shift-session-form input{width:100%;min-height:39px;border:1px solid var(--c-border-strong);border-radius:8px;background:var(--c-surface);color:var(--c-text);font:inherit;padding:.55rem .65rem}
  .shift-session-form textarea{min-height:105px;resize:vertical}
  .shift-session-form select:focus,.shift-session-form textarea:focus,.shift-session-form input:focus{outline:3px solid var(--c-primary-100);border-color:var(--c-primary)}
  .shift-session-handover{border-left:4px solid #d97706}
  .shift-session-pending{display:grid;gap:.75rem}
  .shift-session-pending-item{padding:.9rem 1rem;border:1px solid #f1d6a7;border-radius:11px;background:#fffaf0}
  .shift-session-row{display:flex;align-items:flex-start;justify-content:space-between;gap:.9rem;flex-wrap:wrap}
  .shift-session-meta{display:flex;gap:.45rem;flex-wrap:wrap;align-items:center;color:var(--ss-muted);font-size:.72rem}
  .shift-session-summary{margin:.65rem 0 0;padding:.7rem .8rem;border-radius:8px;background:rgba(255,255,255,.55);white-space:pre-line;font-size:.83rem}
  .shift-session-snapshot{margin-top:.7rem;padding:.7rem .8rem;border:1px solid #ead6b4;border-radius:9px;background:rgba(255,255,255,.6)}
  .shift-session-snapshot-title{display:flex;justify-content:space-between;gap:.6rem;color:#765b27;font-size:.65rem;font-weight:850;letter-spacing:.06em;text-transform:uppercase}
  .shift-session-snapshot-title span{font-weight:600;letter-spacing:0;text-transform:none}
  .shift-session-snapshot-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.5rem;margin-top:.55rem}
  .shift-session-snapshot-grid>div{padding:.45rem .55rem;border:1px solid rgba(120,90,35,.12);border-radius:7px;background:rgba(255,255,255,.55)}
  .shift-session-snapshot-grid span{display:block;color:#75654a;font-size:.62rem;line-height:1.25}
  .shift-session-snapshot-grid b{display:block;margin-top:.12rem;color:#283b2d;font-size:.88rem}
  .shift-session-ack{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;margin-top:.8rem;padding-top:.75rem;border-top:1px solid #ead6b4}
  .shift-session-ack label{display:flex;align-items:flex-start;gap:.45rem;color:#57451f;font-size:.75rem;line-height:1.4}
  .shift-session-ack input[type=checkbox]{width:16px;height:16px;margin:.05rem 0 0;flex:0 0 auto;accent-color:#14532d}
  body.dark .shift-session-summary{background:rgba(255,255,255,.04)}body.dark .shift-session-ack{border-color:#6a5024}body.dark .shift-session-ack label{color:#ead5a8}
  @media(max-width:520px){.shift-session-ack{align-items:stretch}.shift-session-ack .btn{width:100%;justify-content:center}}
  .shift-session-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem}
  .shift-session-person{padding:.8rem .9rem;border:1px solid var(--ss-line);border-radius:10px;background:var(--c-surface)}
  .shift-session-person strong{display:block;font-size:.82rem}
  .shift-session-person span{display:block;margin-top:.22rem;color:var(--ss-muted);font-size:.71rem}
  .shift-session-table-wrap{overflow:auto}
  .shift-session-table{width:100%;border-collapse:collapse;min-width:720px}
  .shift-session-table th,.shift-session-table td{padding:.65rem .75rem;border-bottom:1px solid var(--ss-line);text-align:left;vertical-align:top}
  .shift-session-table th{color:var(--ss-muted);font-size:.66rem;letter-spacing:.06em;text-transform:uppercase}
  .shift-session-table td{font-size:.76rem}
  .shift-session-policy{margin-top:1rem;padding:.7rem .8rem;border:1px solid var(--ss-line);border-radius:9px;background:var(--c-bg);color:var(--ss-muted);font-size:.72rem}
  body.dark .shift-session-pending-item{background:#2d281b;border-color:#6a5024}
  body.dark .shift-session-snapshot{background:#211d15;border-color:#6a5024}body.dark .shift-session-snapshot-title{color:#e5c98c}body.dark .shift-session-snapshot-grid>div{background:#272319;border-color:#594827}body.dark .shift-session-snapshot-grid span{color:#cbb98e}body.dark .shift-session-snapshot-grid b{color:#f1e9d4}
  body.dark .shift-session-steps .step-no{background:#21402a;color:#b9e8c2}
  @media(max-width:760px){.shift-session-hero{grid-template-columns:1fr}.shift-session-card-head,.shift-session-card-body{padding:.8rem}.shift-session-kpi{min-width:calc(50% - .3rem);flex:1}.shift-session-snapshot-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<div class="shift-session-page">
  <div class="shift-session-hero">
    @if($activeSession)
      <section class="shift-session-card shift-session-active" aria-labelledby="activeShiftTitle">
        <div class="shift-session-card-head">
          <h2 id="activeShiftTitle">Your shift is in progress</h2>
          <span class="shift-session-status"><span class="shift-session-status-dot" aria-hidden="true"></span>Active</span>
        </div>
        <div class="shift-session-card-body">
          <div class="shift-session-big">{{ \App\Models\LogEntry::SHIFTS[$activeSession->shift] ?? ucfirst($activeSession->shift) }} shift</div>
          <div class="small">Started {{ $activeSession->started_at->format('D, d M Y · H:i') }} · {{ $activeSession->started_at->diffForHumans() }}</div>
          @if($activeSession->start_note)<div class="shift-session-note"><b>Opening note</b><br>{{ $activeSession->start_note }}</div>@endif
          <div class="shift-session-kpis">
            <div class="shift-session-kpi"><span>Business date</span><b>{{ $activeSession->business_date->format('d M Y') }}</b></div>
            <div class="shift-session-kpi"><span>Elapsed</span><b>{{ intdiv($activeDurationMinutes, 60) }}h {{ $activeDurationMinutes % 60 }}m</b></div>
            <div class="shift-session-kpi"><span>Linked log entries</span><b>{{ $activeSession->logEntries()->count() }}</b></div>
            <div class="shift-session-kpi"><span>Still open</span><b>{{ $activeOpenEntries }}</b></div>
            <div class="shift-session-kpi"><span>Session ref</span><b>#{{ $activeSession->id }}</b></div>
          </div>
          @include('desk._session-activity', ['activities' => $sessionActivities[$activeSession->id] ?? collect()])
        </div>
      </section>
    @else
      <section class="shift-session-card" aria-labelledby="startShiftTitle">
        <div class="shift-session-card-head"><h2 id="startShiftTitle">Start your front-office shift</h2><span class="badge badge-blue">{{ $shiftLabel }} shift</span></div>
        <div class="shift-session-card-body">
          @if($pendingHandovers->isNotEmpty())
            <div class="alert alert-warn" style="margin:0"><x-icon name="alert"/><div>A hand-over is waiting for you. Review and acknowledge it below before starting your next shift session. <a href="#pendingHandoversTitle">Go to pending hand-over(s)</a>.</div></div>
          @elseif(can_do('logbook.write'))
            <p class="small muted" style="margin:.1rem 0 .85rem">Open a named work session to record when you take responsibility for the desk. The shift type is set from the configured shift schedule.</p>
            <form method="post" action="{{ route('desk.shift.start') }}" class="shift-session-form">@csrf
              <div><label for="start_note">Opening note <span class="muted" style="font-weight:500">(optional)</span></label><textarea id="start_note" name="start_note" maxlength="1000" placeholder="Anything the next shift should know about how you are taking over?">{{ old('start_note') }}</textarea>@error('start_note')<span class="err">{{ $message }}</span>@enderror</div>
              <button class="btn btn-primary" id="startShiftBtn"><x-icon name="clock"/> Start my shift</button>
            </form>
          @else
            <div class="alert alert-info" style="margin:0"><x-icon name="lock"/><div>You can view shift sessions, but your role does not have permission to start or close one.</div></div>
          @endif
        </div>
      </section>
    @endif

    <section class="shift-session-card" aria-labelledby="shiftStepsTitle">
      <div class="shift-session-card-head"><h2 id="shiftStepsTitle">The shift hand-over flow</h2></div>
      <div class="shift-session-card-body">
        <ol class="shift-session-steps">
          <li><span class="step-no">1</span><span><b>Start</b> your session when you take responsibility for the front desk.</span></li>
          <li><span class="step-no">2</span><span><b>Record</b> log-book items during the session; they are linked to your shift.</span></li>
          <li><span class="step-no">3</span><span><b>Close &amp; hand over</b> to the named incoming staff member with a summary.</span></li>
          <li><span class="step-no">4</span><span><b>Receive</b> the saved desk snapshot and summary, review linked items, then acknowledge in your own account.</span></li>
        </ol>
      </div>
    </section>
  </div>

  @if($activeSession)
    <section class="shift-session-card shift-session-handover" style="margin-bottom:1rem" aria-labelledby="closeShiftTitle">
      <div class="shift-session-card-head"><div><h2 id="closeShiftTitle">Close shift &amp; hand over</h2><div class="small muted" style="margin-top:.2rem">Your session stays open until you submit this hand-over. The recipient must acknowledge it.</div></div></div>
      <div class="shift-session-card-body">
        @if($activeOpenEntries)<div class="alert alert-warn"><x-icon name="alert"/><div><b>{{ $activeOpenEntries }}</b> log-book item(s) linked to this session are still open. They remain visible to the next shift; include any priorities in your hand-over summary.</div></div>@endif
        @if(can_do('logbook.write'))
          @if(count($recipients))
            <form method="post" action="{{ route('desk.shift.close', $activeSession) }}" class="shift-session-form" data-confirm="Close your shift and send this hand-over to the selected staff member?">@csrf
              <div><label for="incoming_user_id">Incoming staff member <span class="req">*</span></label>
                <select id="incoming_user_id" name="incoming_user_id" required><option value="">Choose who receives the hand-over</option>@foreach($recipients as $id => $name)<option value="{{ $id }}" @selected(old('incoming_user_id') == $id)>{{ $name }}</option>@endforeach</select>
                @error('incoming_user_id')<span class="err">{{ $message }}</span>@enderror
              </div>
              <div><label for="handover_note">Shift summary for the next person <span class="req">*</span></label>
                <textarea id="handover_note" name="handover_note" maxlength="4000" required placeholder="Summarise what happened, what remains outstanding, and anything the incoming staff member needs to do next.">{{ old('handover_note') }}</textarea>
                @error('handover_note')<span class="err">{{ $message }}</span>@enderror
              </div>
              <div class="flex" style="gap:.55rem;flex-wrap:wrap;align-items:center"><button class="btn btn-primary" id="closeShiftBtn"><x-icon name="swap"/> Close shift &amp; send hand-over</button><a class="btn btn-ghost" href="{{ route('desk.handover') }}">Review operations hand-over board</a></div>
            </form>
          @else
            <div class="alert alert-warn" style="margin:0"><x-icon name="alert"/><div>No active Desk Operations staff account with Shift Log Book read and write access is available to receive a hand-over. Ask an administrator to activate or update the next staff member’s access.</div></div>
          @endif
        @else
          <div class="alert alert-info" style="margin:0"><x-icon name="lock"/><div>Only staff with log-book write permission can close this session.</div></div>
        @endif
      </div>
    </section>
  @endif

  @if($pendingHandovers->isNotEmpty())
    <section class="shift-session-card" style="margin-bottom:1rem" aria-labelledby="pendingHandoversTitle">
      <div class="shift-session-card-head"><div><h2 id="pendingHandoversTitle">Hand-overs waiting for you</h2><div class="small muted" style="margin-top:.2rem">Review each summary, then acknowledge it to complete the hand-over record.</div></div><span class="badge badge-amber">{{ $pendingHandovers->count() }} pending</span></div>
      <div class="shift-session-card-body"><div class="shift-session-pending">
        @foreach($pendingHandovers as $handover)
          <article class="shift-session-pending-item">
            <div class="shift-session-row">
              <div><strong>{{ $handover->starter?->name ?? 'Former staff member' }} handed over the {{ \App\Models\LogEntry::SHIFTS[$handover->shift] ?? ucfirst($handover->shift) }} shift</strong>
                <div class="shift-session-meta"><span>Session #{{ $handover->id }}</span><span>·</span><span>Closed {{ $handover->closed_at?->format('D, d M · H:i') }}</span><span>·</span><span>{{ $handover->log_entries_count }} linked log entries</span></div>
              </div>

            </div>
            <div class="shift-session-summary"><b>Outgoing summary</b><br>{{ $handover->handover_note }}</div>
            @if(is_array($handover->handover_snapshot))
              @php $sessionSnapshot = $handover->handover_snapshot; @endphp
              <div class="shift-session-snapshot" aria-label="Desk status captured when the outgoing shift closed">
                <div class="shift-session-snapshot-title">Desk status at close <span>captured {{ $handover->closed_at?->format('H:i') }}</span></div>
                <div class="shift-session-snapshot-grid">
                  <div><span>In house</span><b>{{ $sessionSnapshot['inhouse'] ?? 0 }}</b></div>
                  <div><span>Arrivals / departures</span><b>{{ $sessionSnapshot['arrivals_pending'] ?? 0 }} / {{ $sessionSnapshot['departures_pending'] ?? 0 }}</b></div>
                  <div><span>Wake-ups due / late</span><b>{{ $sessionSnapshot['wakeups_due'] ?? 0 }} / {{ $sessionSnapshot['wakeups_late'] ?? 0 }}</b></div>
                  <div><span>Messages / parcels</span><b>{{ $sessionSnapshot['messages'] ?? 0 }} / {{ $sessionSnapshot['parcels'] ?? 0 }}</b></div>
                  <div><span>Waiting long / departed parcels</span><b>{{ $sessionSnapshot['messages_old'] ?? 0 }} / {{ $sessionSnapshot['departed_parcels'] ?? 0 }}</b></div>
                  <div><span>Open log items</span><b>{{ $sessionSnapshot['log_open'] ?? 0 }}</b></div>
                  <div><span>Traces due</span><b>{{ $sessionSnapshot['traces_today'] ?? 0 }}</b></div>
                  <div><span>Lost &amp; found due / reports</span><b>{{ $sessionSnapshot['lf_due'] ?? 0 }} / {{ $sessionSnapshot['lf_reports'] ?? 0 }}</b></div>
                </div>
              </div>
            @endif
            @include('desk._session-activity', ['activities' => $sessionActivities[$handover->id] ?? collect()])
            <div class="flex" style="gap:.8rem;flex-wrap:wrap;margin-top:.65rem">
              @if($handover->log_entries_count)<a class="small" href="{{ route('desk.logbook', ['tab' => 'all', 'session' => $handover->id]) }}">Review this session’s log-book entries →</a>@endif
              <a class="small" href="{{ route('desk.handover') }}">Review the live desk hand-over board →</a>
            </div>
            <form method="post" action="{{ route('desk.shift.accept', $handover) }}" class="shift-session-ack" data-confirm="Acknowledge that you have received this shift hand-over?">@csrf
              <label for="confirm_receipt_{{ $handover->id }}"><input id="confirm_receipt_{{ $handover->id }}" type="checkbox" name="confirm_receipt" value="1" required> I have reviewed the summary and linked shift items.</label>
              <button class="btn btn-sm btn-primary"><x-icon name="check"/> Acknowledge hand-over</button>
            </form>
          </article>
        @endforeach
      </div></div>
    </section>
  @endif

  <section class="shift-session-card" style="margin-bottom:1rem" aria-labelledby="activeTeamTitle">
    <div class="shift-session-card-head"><div><h2 id="activeTeamTitle">Front-office staff currently on shift</h2><div class="small muted" style="margin-top:.2rem">Operational desk sessions currently open at this property.</div></div><span class="badge badge-green">{{ $teamActiveSessions->count() }} active</span></div>
    <div class="shift-session-card-body">
      @if($teamActiveSessions->isNotEmpty())
        <div class="shift-session-grid">
          @foreach($teamActiveSessions as $teamSession)
            <div class="shift-session-person"><strong>{{ $teamSession->starter?->name ?? 'Staff account' }}@if((int) $teamSession->started_by === (int) auth()->id()) <span class="badge badge-green" style="margin-left:.3rem">You</span>@endif</strong>
              <span>{{ \App\Models\LogEntry::SHIFTS[$teamSession->shift] ?? ucfirst($teamSession->shift) }} shift · started {{ $teamSession->started_at->format('H:i') }} · {{ $teamSession->started_at->diffForHumans() }}</span>
              <span>{{ $teamSession->log_entries_count }} log-book entr{{ $teamSession->log_entries_count === 1 ? 'y' : 'ies' }} linked</span>
            </div>
          @endforeach
        </div>
      @else
        <div class="small muted">No front-office shift sessions are currently open.</div>
      @endif
    </div>
  </section>

  <section class="shift-session-card" style="margin-bottom:1rem" aria-labelledby="frontOfficeDayTitle">
    <div class="shift-session-card-head"><div><h2 id="frontOfficeDayTitle">Front Office day close-out report</h2><div class="small muted" style="margin-top:.2rem">Individual staff sessions for the selected business date; desk snapshots stay attached to each person’s close-out.</div></div></div>
    <div class="shift-session-card-body">
      <form method="get" action="{{ route('desk.shift') }}" class="flex" style="align-items:end;gap:.55rem;flex-wrap:wrap;margin-bottom:.75rem">
        <div class="field"><label for="frontOfficeReportDate">Business date</label><input id="frontOfficeReportDate" type="date" name="date" value="{{ $day }}"></div>
        <button class="btn btn-sm">View day</button>
        @if($day !== bdate()->toDateString())<a class="btn btn-sm btn-ghost" href="{{ route('desk.shift') }}">Today</a>@endif
      </form>
      @if($daySummary->isNotEmpty())
        <div class="shift-session-grid" style="margin-bottom:.8rem">
          @foreach($daySummary as $dayShift => $totals)
            <div class="shift-session-person"><strong>{{ ucfirst($dayShift) }} shift · {{ $totals['people'] }} staff member{{ $totals['people'] === 1 ? '' : 's' }}</strong>
              <span>{{ $totals['sessions'] }} session(s) · {{ $totals['open'] }} open · {{ $totals['pending'] }} awaiting receipt · {{ $totals['closed'] }} acknowledged</span>
              <span>{{ $totals['log_entries'] }} linked log-book entr{{ $totals['log_entries'] === 1 ? 'y' : 'ies' }}</span>
            </div>
          @endforeach
        </div>
        <div class="shift-session-table-wrap"><table class="shift-session-table"><thead><tr><th>Staff / shift</th><th>Started / closed</th><th>Linked log items</th><th>Desk snapshot at close</th><th>Status</th></tr></thead><tbody>
          @foreach($daySessions as $daySession)
            <tr><td><b>{{ $daySession->starter?->name ?? 'Former staff member' }}</b>@if((int) $daySession->started_by === (int) auth()->id()) <span class="badge badge-green">You</span>@endif<div class="small muted">{{ ucfirst($daySession->shift) }} · session #{{ $daySession->id }}</div></td>
              <td>{{ $daySession->started_at->format('H:i') }} / {{ $daySession->closed_at?->format('H:i') ?? '—' }}</td><td>{{ $daySession->log_entries_count }}</td>
              <td>@if(is_array($daySession->handover_snapshot))<span class="small">{{ $daySession->handover_snapshot['log_open'] ?? 0 }} open log items · {{ $daySession->handover_snapshot['arrivals_pending'] ?? 0 }} arrivals · {{ $daySession->handover_snapshot['departures_pending'] ?? 0 }} departures</span>@else<span class="muted">Not closed yet</span>@endif</td>
              <td><span class="badge badge-{{ $daySession->statusTone() }}">{{ $daySession->statusLabel() }}</span></td></tr>
          @endforeach
        </tbody></table></div>
      @else
        <div class="small muted">No front-office sessions were recorded for {{ \Carbon\Carbon::parse($day)->format('d M Y') }}.</div>
      @endif
    </div>
  </section>

  <section class="shift-session-card" aria-labelledby="recentShiftTitle">
    <div class="shift-session-card-head"><div><h2 id="recentShiftTitle">Recent shift sessions</h2><div class="small muted" style="margin-top:.2rem">The latest 30 staff sessions at this property.</div></div></div>
    <div class="shift-session-table-wrap">
      <table class="shift-session-table"><thead><tr><th>Staff / shift</th><th>Started</th><th>Closed</th><th>Duration</th><th>Handed to / received by</th><th>Status</th><th>Summary</th></tr></thead><tbody>
        @forelse($recentSessions as $item)
          <tr>
            <td><b>{{ $item->starter?->name ?? 'Former staff member' }}</b><div class="small muted">{{ \App\Models\LogEntry::SHIFTS[$item->shift] ?? ucfirst($item->shift) }} · {{ $item->business_date->format('d M Y') }}</div></td>
            <td class="nowrap">{{ $item->started_at->format('d M · H:i') }}</td>
            <td class="nowrap">{{ $item->closed_at?->format('d M · H:i') ?? '—' }}</td>
            <td class="nowrap">@php $rowDuration = (int) $item->started_at->diffInMinutes($item->closed_at ?? now()); @endphp {{ intdiv($rowDuration, 60) }}h {{ $rowDuration % 60 }}m</td>
            <td>{{ $item->incomingUser?->name ?? '—' }}@if($item->received_at)<div class="small muted">Acknowledged {{ $item->received_at->format('d M · H:i') }}</div>@endif</td>
            <td><span class="badge badge-{{ $item->statusTone() }}">{{ $item->statusLabel() }}</span></td>
            <td style="max-width:280px">@if($item->handover_note)<span title="{{ $item->handover_note }}">{{ \Illuminate\Support\Str::limit($item->handover_note, 100) }}</span>@elseif($item->start_note)<span class="muted" title="{{ $item->start_note }}">{{ \Illuminate\Support\Str::limit($item->start_note, 100) }}</span>@else<span class="muted">—</span>@endif</td>
          </tr>
        @empty
          <tr><td colspan="7" class="muted">No shift sessions recorded yet.</td></tr>
        @endforelse
      </tbody></table>
    </div>
  </section>

  <div class="shift-session-policy">This is an operational front-desk hand-over record. It does not replace employee attendance or the Workforce time clock.</div>
</div>
@endsection
