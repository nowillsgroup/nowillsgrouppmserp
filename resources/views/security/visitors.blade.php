@extends('layouts.app')
@section('title', 'Visitors')
@section('crumbs') Security / Visitors / {{ \App\Http\Controllers\SecurityController::TABS[$tab] }} @endsection
@section('actions')
  <form method="get" action="{{ route('security.visitors') }}" class="inline" style="display:inline-flex;gap:.3rem">
    <input type="hidden" name="tab" value="log"><input class="input" style="width:13rem" name="q" placeholder="Pass no. / name / phone / vehicle" id="visitorSearch" value="{{ $q ?? '' }}">
  </form>
  @if(can_do('security.manage'))<form method="post" action="{{ route('security.run') }}" class="inline">@csrf<button class="btn" id="secRunBtn" title="Overstay alerts, no-shows, overdue gate passes, retention"><x-icon name="refresh"/> Run checks</button></form>@endif
  @if(can_do('security.visitors'))<button class="btn btn-primary" data-modal="visitorModal" id="addVisitorBtn"><x-icon name="plus"/> Register visitor</button>@endif
@endsection
@section('content')
@if(session('badge_for') && ($bv = \App\Models\Visitor::find(session('badge_for'))))
  <div class="alert alert-success" id="badgeReady"><x-icon name="id"/><div>Pass <b>{{ $bv->pass_no }}</b> for {{ $bv->name }} is ready. <a href="{{ route('security.visitors.badge', $bv) }}" target="_blank" id="printBadgeLink"><b>Print visitor badge</b></a></div></div>
@endif

<div class="stats">
  <x-stat label="Visitors on site" :value="$snap['on_site']" icon="users" tone="blue" :sub="$snap['passes_open'].' open pass(es) · '.$snap['visitors_today'].' today'" :href="route('security.visitors')"/>
  <x-stat label="Waiting for the guest's OK" :value="$snap['awaiting']" icon="phone" :tone="$snap['awaiting'] ? 'amber' : 'green'" sub="call the room, then approve"/>
  <x-stat label="Passes expired – still inside" :value="$snap['overstays']" icon="clock" :tone="$snap['overstays'] ? 'red' : 'green'" :sub="'visiting hours end '.$cfg['visiting_until']"/>
  <x-stat label="Expected today" :value="$snap['expected_today']" icon="calendar" tone="purple" :href="route('security.visitors', ['tab' => 'expected'])" sub="pre-registered by guests"/>
</div>

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\SecurityController::TABS as $k => $l)
      @if(! in_array($k, ['watchlist', 'settings']) || can_do('security.manage'))<a href="{{ route('security.visitors', ['tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}@if($k === 'onsite' && $snap['awaiting']) <span class="badge badge-amber">{{ $snap['awaiting'] }}</span>@endif</a>@endif
    @endforeach
  </div></div>

@if($tab === 'onsite')
  <div class="card-body pad">
    <div>
      <div class="table-wrap"><table class="table" id="onsiteTable"><thead><tr><th>Pass</th><th>Visitor</th><th>Visiting</th><th>In</th><th>Valid until</th><th class="right"></th></tr></thead><tbody>
      @forelse($list as $v)
        <tr data-pass="{{ $v->pass_no }}" class="{{ $v->isOverstay() ? 'row-overstay' : '' }}">
          <td class="nowrap"><b class="mono">{{ $v->pass_no }}</b><div><span class="badge badge-{{ $v->statusTone() }}">{{ $v->statusLabel() }}</span></div></td>
          <td>{{ $v->name }}@if($v->persons > 1) <span class="tiny muted">+{{ $v->persons - 1 }}</span>@endif
            @if($v->watch)<span class="badge badge-red" title="{{ $v->watch->reason }}">Watch list</span>@endif
            <div class="tiny muted">{{ $v->phone }}{{ $v->company ? ' · '.$v->company : '' }}{{ $v->maskedId() ? ' · '.$v->maskedId() : '' }}{{ $v->vehicle_no ? ' · '.$v->vehicle_no : '' }}</div>
            @if($v->items_declared)<div class="tiny">Carrying: {{ $v->items_declared }}</div>@endif</td>
          <td>{{ $v->hostLabel() }}<div class="tiny muted">{{ \App\Models\Visitor::PURPOSES[$v->purpose] ?? $v->purpose }}@if($v->stay && $v->stay->visitor_policy !== 'allow') · <span class="text-amber">{{ \App\Services\SecurityService::POLICIES[$v->stay->visitor_policy] }}</span>@endif</div></td>
          <td class="nowrap">{{ $v->checked_in_at?->format('H:i') ?? '–' }}</td>
          <td class="nowrap">@if($v->status === 'in')<span class="{{ $v->isOverstay() ? 'text-red' : '' }}">{{ $v->valid_until?->format('H:i') }}</span>@if($v->isOverstay())<div class="tiny text-red">overstay {{ $v->valid_until->diffForHumans(null, true) }}</div>@endif @else<span class="muted">–</span>@endif</td>
          <td class="right nowrap">
            @if(can_do('security.visitors'))
              @if($v->status === 'awaiting')
                <form method="post" action="{{ route('security.visitors.approve', $v) }}" class="inline">@csrf<button class="btn btn-sm btn-success approve-btn"><x-icon name="check" size="sm"/> Guest says yes</button></form>
                <button class="btn btn-sm deny-btn" data-modal="denyModal" data-action="{{ route('security.visitors.deny', $v) }}" data-title="Turn away {{ $v->name }} ({{ $v->pass_no }})">Turn away</button>
              @else
                <a class="btn btn-sm btn-ghost btn-icon" href="{{ route('security.visitors.badge', $v) }}" target="_blank" title="Print badge"><x-icon name="print"/></a>
                <button class="btn btn-sm btn-ghost btn-icon extend-btn" data-modal="extendModal" data-action="{{ route('security.visitors.extend', $v) }}" data-title="Extend pass {{ $v->pass_no }} – {{ $v->name }}" title="Extend pass"><x-icon name="clock"/></button>
                <form method="post" action="{{ route('security.visitors.out', $v) }}" class="inline">@csrf<button class="btn btn-sm out-btn"><x-icon name="logout" size="sm"/> Check out</button></form>
              @endif
            @endif
          </td>
        </tr>
      @empty<tr><td colspan="6"><x-empty icon="users" text="No visitors on site right now."/></td></tr>@endforelse
      </tbody></table></div>
      <p class="tiny muted mt-1">@if($cfg['approval'] === 'always')Every visit to a guest room is confirmed with the guest by phone first.@else Visits are confirmed with the guest only when they asked for a call first.@endif Visitors to guest rooms must leave by {{ $cfg['visiting_until'] }}; passes run {{ $cfg['pass_hours'] }} h.</p>
    </div>
    <div class="sec-side" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:1.2rem;margin-top:1rem;padding-top:1rem;border-top:1px solid var(--c-border)">
    <div>
      <h4 class="mb-1"><x-icon name="calendar"/> Arriving soon (pre-registered)</h4>
      @forelse($arriving as $v)
        <div class="sec-mini" data-expected="{{ $v->pass_no }}"><div><b>{{ $v->name }}</b> <span class="tiny muted">{{ $v->pass_no }}</span><div class="tiny muted">{{ $v->expected_at->format('H:i') }} · {{ $v->hostLabel() }} · {{ $v->persons }} pers.</div></div>
          @if(can_do('security.visitors'))<button class="btn btn-sm arrive-btn" data-modal="arriveModal" data-action="{{ route('security.visitors.arrive', $v) }}" data-title="{{ $v->name }} has arrived ({{ $v->pass_no }})" data-f-phone="{{ $v->phone }}">Arrived</button>@endif</div>
      @empty<p class="small muted">Nobody expected in the next two hours.</p>@endforelse
    </div>
    <div>
      <h4 class="mb-1"><x-icon name="lock"/> Guest privacy</h4>
      @forelse($private as $s)
        <div class="sec-mini" data-private="{{ $s->room?->room_no }}"><div><b>Room {{ $s->room?->room_no }}</b> · {{ ($s->guest ?? $s->reservation?->guest)?->full_name }}<div class="tiny {{ $s->visitor_policy === 'none' ? 'text-red' : 'text-amber' }}">{{ \App\Services\SecurityService::POLICIES[$s->visitor_policy] }}</div></div></div>
      @empty<p class="small muted">No guest has asked for privacy.</p>@endforelse
      @if(can_do('security.visitors') && ($stays ?? collect())->isNotEmpty())
        <form method="post" action="" id="policyForm" class="mt-1" onsubmit="this.action='{{ url('security/stay') }}/'+this.stay.value+'/visitor-policy'">@csrf
          <div class="flex gap-1 wrap">
            <select name="stay" class="input" style="width:auto" required><option value="">Room…</option>@foreach($stays as $s)<option value="{{ $s->id }}">{{ $s->room?->room_no }} · {{ ($s->guest ?? $s->reservation?->guest)?->full_name }}</option>@endforeach</select>
            <select name="policy" class="input" style="width:auto">@foreach(\App\Services\SecurityService::POLICIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
            <button class="btn btn-sm" type="submit">Set</button>
          </div>
          <p class="tiny muted m-0 mt-1">Guests can also set this themselves in their stay portal.</p>
        </form>
      @endif
    </div>
    </div>
  </div>

@elseif($tab === 'expected')
  <div class="table-wrap"><table class="table" id="expectedTable"><thead><tr><th>Pass</th><th>Visitor</th><th>Visiting</th><th>Expected</th><th>Registered</th><th class="right"></th></tr></thead><tbody>
  @forelse($list as $v)
    <tr data-pass="{{ $v->pass_no }}"><td class="mono">{{ $v->pass_no }}</td><td>{{ $v->name }} @if($v->persons > 1)<span class="tiny muted">+{{ $v->persons - 1 }}</span>@endif<div class="tiny muted">{{ $v->phone }}{{ $v->notes ? ' · '.$v->notes : '' }}</div></td>
      <td>{{ $v->hostLabel() }}</td><td class="nowrap">{{ $v->expected_at?->format('D d M H:i') }}</td>
      <td><span class="badge badge-blue">{{ $v->source === 'portal' ? 'By the guest (portal)' : 'Desk' }}</span></td>
      <td class="right nowrap">@if(can_do('security.visitors'))
        <button class="btn btn-sm arrive-btn" data-modal="arriveModal" data-action="{{ route('security.visitors.arrive', $v) }}" data-title="{{ $v->name }} has arrived ({{ $v->pass_no }})" data-f-phone="{{ $v->phone }}">Arrived</button>
        <form method="post" action="{{ route('security.visitors.cancel', $v) }}" class="inline" data-confirm="Cancel this expected visitor?">@csrf<button class="btn btn-sm btn-ghost btn-icon" title="Cancel"><x-icon name="x"/></button></form>@endif</td></tr>
  @empty<tr><td colspan="6"><x-empty icon="calendar" text="No expected visitors. Guests can pre-register visitors from their stay portal."/></td></tr>@endforelse
  </tbody></table></div>

@elseif($tab === 'log')
  <div class="card-body pad" style="padding-bottom:0">
    <form method="get" class="flex gap-1 wrap items-end" id="logFilter"><input type="hidden" name="tab" value="log">
      <div class="field" style="margin:0"><label>From</label><input type="date" name="from" class="input" value="{{ $from->toDateString() }}"></div>
      <div class="field" style="margin:0"><label>To</label><input type="date" name="to" class="input" value="{{ $to->toDateString() }}"></div>
      <div class="field" style="margin:0"><label>Status</label><select name="status" class="input" style="width:auto"><option value="">Any</option>@foreach(\App\Models\Visitor::STATUSES as $k => [$l])<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="field" style="margin:0"><label>Search</label><input name="q" class="input" value="{{ $q }}" placeholder="name, phone, pass, vehicle, host"></div>
      <button class="btn">Filter</button>
    </form>
  </div>
  <div class="table-wrap"><table class="table" id="logTable"><thead><tr><th>Pass</th><th>Visitor</th><th>ID</th><th>Visiting</th><th>In</th><th>Out</th><th>Status</th></tr></thead><tbody>
  @forelse($list as $v)
    <tr data-pass="{{ $v->pass_no }}"><td class="mono">{{ $v->pass_no }}</td>
      <td>{{ $v->name }}@if($v->watch) <span class="badge badge-red">Watch list</span>@endif<div class="tiny muted">{{ $v->phone }}{{ $v->vehicle_no ? ' · '.$v->vehicle_no : '' }}</div></td>
      <td class="small">{{ $v->maskedId() ?? '–' }}</td><td class="small">{{ $v->hostLabel() }}</td>
      <td class="nowrap small">{{ $v->checked_in_at?->format('d M H:i') ?? '–' }}<div class="tiny muted">{{ $v->inBy?->name }}</div></td>
      <td class="nowrap small">{{ $v->checked_out_at?->format('d M H:i') ?? '–' }}<div class="tiny muted">{{ $v->outBy?->name }}</div></td>
      <td><span class="badge badge-{{ $v->statusTone() }}">{{ $v->statusLabel() }}</span>@if($v->override_reason)<div class="tiny text-red" title="{{ $v->override_reason }}">override</div>@endif @if($v->deny_reason)<div class="tiny muted">{{ $v->deny_reason }}</div>@endif</td></tr>
  @empty<tr><td colspan="7"><x-empty icon="search" text="No visitors match."/></td></tr>@endforelse
  </tbody></table></div>
  <div class="card-body pad">{{ $list->links() }}<p class="tiny muted m-0">Visitor names, phone numbers and ID details are anonymised {{ $cfg['retention_days'] }} days after the visit. ID numbers are never stored – only the type and last 4 characters.</p></div>

@elseif($tab === 'watchlist')
  <div class="card-body pad" style="padding-bottom:0"><button class="btn btn-primary" data-modal="watchModal" data-action="{{ route('security.watch.store') }}" data-title="Add to the watch list" id="addWatchBtn"><x-icon name="plus"/> Add person</button></div>
  <div class="table-wrap"><table class="table" id="watchTable"><thead><tr><th>Name</th><th>Matches on</th><th>Level</th><th>Reason</th><th>Until</th><th>Hits (90 d)</th><th class="right"></th></tr></thead><tbody>
  @forelse($list as $w)
    <tr data-watch="{{ $w->name }}" class="{{ $w->isCurrent() ? '' : 'muted' }}"><td><b>{{ $w->name }}</b><div class="tiny muted">added {{ $w->created_at->format('d M Y') }}{{ $w->creator ? ' by '.$w->creator->name : '' }}</div></td>
      <td class="small">{{ $w->phone ?: '' }}{{ $w->phone && $w->id_last4 ? ' · ' : '' }}{{ $w->id_last4 ? (\App\Models\Visitor::ID_TYPES[$w->id_type] ?? 'ID').' ••••'.$w->id_last4 : '' }}@if(! $w->phone && ! $w->id_last4)<span class="muted">name only</span>@endif</td>
      <td><span class="badge badge-{{ \App\Models\SecurityWatch::LEVELS[$w->level][1] }}">{{ $w->level === 'block' ? 'Barred' : 'Alert' }}</span>@if(! $w->is_active)<span class="badge badge-slate">Lifted</span>@endif</td>
      <td class="small">{{ $w->reason }}</td><td class="nowrap small">{{ $w->valid_until?->format('d M Y') ?? 'Indefinite' }}</td><td class="right">{{ $hits[$w->id] ?? 0 }}</td>
      <td class="right nowrap">
        <button class="btn btn-sm btn-ghost btn-icon" title="Edit" data-modal="watchModal" data-action="{{ route('security.watch.update', $w) }}" data-title="Edit {{ $w->name }}"
          data-f-name="{{ $w->name }}" data-f-phone="{{ $w->phone }}" data-f-level="{{ $w->level }}" data-f-reason="{{ $w->reason }}" data-f-valid_until="{{ $w->valid_until?->toDateString() }}" data-f-id_type="{{ $w->id_type }}"><x-icon name="edit"/></button>
        <form method="post" action="{{ route('security.watch.toggle', $w) }}" class="inline">@csrf<button class="btn btn-sm toggle-watch">{{ $w->is_active ? 'Lift' : 'Re-activate' }}</button></form></td></tr>
  @empty<tr><td colspan="7"><x-empty icon="shield" text="Nobody on the watch list."/></td></tr>@endforelse
  </tbody></table></div>
  <div class="card-body pad small muted">Barred persons are refused at registration unless a security manager overrides with a reason. A match on the name alone is only an alert (possible match). ID numbers are stored as a keyed hash – enter the number again to change it.</div>

@elseif($tab === 'settings')
  <div class="card-body pad">
    <form method="post" action="{{ route('security.settings') }}" id="securitySettings" class="form-grid" style="max-width:760px">@csrf
      <x-field name="visiting_until" label="Visitors to guest rooms must leave by" type="time" required :value="$cfg['visiting_until']"/>
      <x-field name="pass_hours" label="Visitor pass valid for (hours)" type="number" min="1" max="24" required :value="$cfg['pass_hours']"/>
      <x-field name="approval" label="Confirm visits with the guest" type="select" :placeholder="false" :value="$cfg['approval']" :options="['always' => 'Always – call the room for every visitor', 'policy' => 'Only when the guest asked for a call first']"/>
      <x-field name="retention_days" label="Anonymise visitor details after (days)" type="number" min="30" max="3650" required :value="$cfg['retention_days']"/>
      <x-field name="incident_alert" label="Alert the log book / duty manager for incidents from" type="select" :placeholder="false" :value="$cfg['incident_alert']" :options="['low' => 'Low (every incident)', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical only']"/>
      <x-field name="alert_phone" label="Duty manager mobile (SMS alerts)" :value="$cfg['alert_phone']" placeholder="+91 98xxxxxxx" maxlength="30"/>
      <label class="check span-2"><input type="hidden" name="require_id" value="0"><input type="checkbox" name="require_id" value="1" @checked($cfg['require_id'])> Every visitor must show ID (type + number recorded, only the last 4 kept)</label>
      <label class="check span-2"><input type="hidden" name="gp_approval" value="0"><input type="checkbox" name="gp_approval" value="1" @checked($cfg['gp_approval'])> Gate passes need a manager's approval before goods can leave</label>
      <div class="span-2"><button class="btn btn-primary" type="submit">Save settings</button></div>
    </form>
  </div>
@endif
</div>

@if(can_do('security.visitors'))
<div class="modal" id="visitorModal"><div class="modal-box" style="max-width:820px"><form method="post" action="{{ route('security.visitors.store') }}" id="visitorForm">@csrf
  <div class="modal-head"><h3>Register visitor</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    @if(session('error') && old('host_type'))<div class="alert alert-error span-2" id="visitorError"><x-icon name="alert"/><div>{{ session('error') }}</div></div>@endif
    <div class="span-2 flex gap-1 wrap" id="hostTypes">@foreach(\App\Models\Visitor::HOSTS as $k => $l)<label class="check"><input type="radio" name="host_type" value="{{ $k }}" @checked(old('host_type', 'guest') === $k)> {{ $l }}</label>@endforeach</div>
    <div class="field span-2 host-pane" data-host="guest"><label for="v_stay">Guest / room being visited</label>
      <select name="reservation_room_id" id="v_stay"><option value="">— Select the room —</option>@foreach($stays as $s)<option value="{{ $s->id }}" data-policy="{{ $s->visitor_policy }}" @selected(old('reservation_room_id') == $s->id)>{{ $s->room?->room_no }} · {{ ($s->guest ?? $s->reservation?->guest)?->full_name }}{{ $s->visitor_policy === 'none' ? '  ⛔ NO VISITORS' : ($s->visitor_policy === 'call' ? '  ☎ call first' : '') }}</option>@endforeach</select>
      <span class="hint" id="policyHint"></span></div>
    <div class="field span-2 host-pane" data-host="staff" hidden><label for="v_staff">Staff member</label><select name="host_user_id" id="v_staff"><option value="">— Select —</option>@foreach($staff as $id => $n)<option value="{{ $id }}" @selected(old('host_user_id') == $id)>{{ $n }}</option>@endforeach</select></div>
    <div class="field span-2 host-pane" data-host="event" hidden><label for="v_event">Function</label><select name="banquet_event_id" id="v_event"><option value="">— Select —</option>@foreach($events as $e)<option value="{{ $e->id }}" @selected(old('banquet_event_id') == $e->id)>{{ $e->event_date->format('d M') }} {{ substr($e->start_time, 0, 5) }} · {{ $e->event_no }} {{ $e->title ?: $e->contact_name }}</option>@endforeach</select>@if($events->isEmpty())<span class="hint">No functions today.</span>@endif</div>
    <x-field name="name" label="Visitor name" required maxlength="120"/>
    <x-field name="phone" label="Mobile" maxlength="30"/>
    <x-field name="id_type" label="ID shown" type="select" :options="\App\Models\Visitor::ID_TYPES" :required="$cfg['require_id']"/>
    <x-field name="id_number" label="ID number" maxlength="40" autocomplete="off" hint="Only the last 4 characters are kept" :required="$cfg['require_id']"/>
    <x-field name="purpose" label="Purpose" type="select" :placeholder="false" :options="\App\Models\Visitor::PURPOSES"/>
    <x-field name="persons" label="Persons in the group" type="number" min="1" max="50" value="1" required/>
    <x-field name="company" label="Company (optional)" maxlength="120"/>
    <x-field name="vehicle_no" label="Vehicle no. (optional)" maxlength="20"/>
    <x-field name="items_declared" label="Items declared (laptop, tools…)" maxlength="255" class="span-2"/>
    <label class="check span-2 host-pane" data-host="guest"><input type="checkbox" name="guest_confirmed" value="1" @checked(old('guest_confirmed'))> I have called the guest and they are expecting this visitor</label>
    @if(can_do('security.manage'))<x-field name="override_reason" label="Manager override reason (barred person / no-visitor room / after hours)" maxlength="255" class="span-2" placeholder="Leave blank unless overriding"/>@endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Register</button></div>
</form></div></div>

<div class="modal" id="denyModal"><div class="modal-box" style="max-width:480px"><form method="post" action="">@csrf
  <div class="modal-head"><h3 data-title>Turn away</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required maxlength="255" placeholder="Guest did not answer / not expecting anyone"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-danger">Turn away</button></div>
</form></div></div>

<div class="modal" id="extendModal"><div class="modal-box" style="max-width:420px"><form method="post" action="">@csrf
  <div class="modal-head"><h3 data-title>Extend pass</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="hours" label="Extend by" type="select" :placeholder="false" :options="[1 => '1 hour', 2 => '2 hours', 3 => '3 hours', 4 => '4 hours', 8 => '8 hours']"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Extend</button></div>
</form></div></div>

<div class="modal" id="arriveModal"><div class="modal-box" style="max-width:560px"><form method="post" action="">@csrf
  <div class="modal-head"><h3 data-title>Visitor arrived</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="id_type" label="ID shown" type="select" :options="\App\Models\Visitor::ID_TYPES" :required="$cfg['require_id']"/>
    <x-field name="id_number" label="ID number" maxlength="40" autocomplete="off" :required="$cfg['require_id']"/>
    <x-field name="phone" label="Mobile" maxlength="30"/>
    <x-field name="vehicle_no" label="Vehicle no." maxlength="20"/>
    @if(can_do('security.manage'))<x-field name="override_reason" label="Manager override reason (only if needed)" maxlength="255" class="span-2"/>@endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Check in</button></div>
</form></div></div>
@push('scripts')
<script>
(() => {
  const f = document.getElementById('visitorForm'); if (!f) return;
  const sync = () => { const t = (f.querySelector('[name=host_type]:checked') || {}).value || 'guest';
    f.querySelectorAll('.host-pane').forEach(p => { p.hidden = p.dataset.host !== t; }); };
  f.querySelectorAll('[name=host_type]').forEach(r => r.addEventListener('change', sync)); sync();
  const st = document.getElementById('v_stay'), hint = document.getElementById('policyHint');
  const ph = () => { const o = st.selectedOptions[0]; const p = o ? o.dataset.policy : '';
    hint.textContent = p === 'none' ? 'This guest asked for NO visitors – do not confirm they are staying here. Take a message.' : (p === 'call' ? 'The guest asked to be called before any visitor goes up.' : '');
    hint.className = 'hint ' + (p === 'none' ? 'text-red' : (p === 'call' ? 'text-amber' : '')); };
  st && st.addEventListener('change', ph); st && ph();
  @if(($errors->any() || session('error')) && old('host_type'))openModal('visitorModal');@endif
})();
</script>
@endpush
@endif

@if(can_do('security.manage') && $tab === 'watchlist')
<div class="modal" id="watchModal"><div class="modal-box" style="max-width:640px"><form method="post" action="{{ route('security.watch.store') }}" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Add to the watch list</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="name" label="Full name" required maxlength="120"/>
    <x-field name="phone" label="Mobile" maxlength="30"/>
    <x-field name="id_type" label="ID type" type="select" :options="\App\Models\Visitor::ID_TYPES"/>
    <x-field name="id_number" label="ID number" maxlength="40" autocomplete="off" hint="Stored only as a hash + last 4"/>
    <x-field name="level" label="Level" type="select" :placeholder="false" :options="['block' => 'Barred – refuse entry', 'alert' => 'Alert – allow, inform security']"/>
    <x-field name="valid_until" label="Until (blank = indefinite)" type="date"/>
    <x-field name="reason" label="Reason" required maxlength="255" class="span-2" placeholder="Theft from room 204 on 12 Aug – police complaint filed"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button type="submit" class="btn btn-primary">Save</button></div>
</form></div></div>
@endif
@endsection
