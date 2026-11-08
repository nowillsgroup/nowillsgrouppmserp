@extends('layouts.app')
@section('title', $property->name)
@section('crumbs')<a href="{{ route('properties.index') }}">Properties</a> / {{ $property->code }} @endsection
@section('actions')
  @if($canManage)<a class="btn" href="{{ route('properties.edit', $property) }}"><x-icon name="edit" size="sm"/> Edit profile</a>@endif
  @if($property->isLive() && (int) $property->id !== (int) hotel()?->id)
    <form method="post" action="{{ route('switch.hotel') }}" style="display:inline">@csrf<input type="hidden" name="hotel_id" value="{{ $property->id }}"><button class="btn btn-primary"><x-icon name="login" size="sm"/> Work in this property</button></form>
  @endif
@endsection
@section('content')

<div class="flex items-center gap-1 mb-2" style="flex-wrap:wrap">
  <span class="badge badge-{{ $property->statusTone() }}">{{ $property->statusLabel() }}</span>
  <span class="small muted">{{ $property->code }} · {{ ucwords(str_replace('_', ' ', (string) $property->property_type)) }}</span>
  @if($property->isHeadOffice())<span class="badge badge-blue">Head office</span>@endif
  @if((int) $property->id === (int) hotel()?->id)<span class="badge badge-slate">You are working here</span>@endif
  @if($property->group)<a class="small" href="{{ route('group.account', ['group' => $property->group->id]) }}">{{ $property->group->name }}</a>@endif
</div>

@if($property->isDraft())
  <div class="alert alert-info" role="status"><x-icon name="info" size="sm"/> <div><b>This property is a draft.</b> Only its administrators can enter it. Check the business date and the access list, then take it live.</div></div>
@elseif($property->isSuspended())
  <div class="alert alert-warn" role="status"><x-icon name="alert" size="sm"/> <div><b>Suspended{{ $property->status_reason ? ': '.$property->status_reason : '' }}</b>. Nobody can sign in to it; its history is untouched. Resume it to let people back in.</div></div>
@elseif($property->isArchived())
  <div class="alert alert-error" role="status"><x-icon name="lock" size="sm"/> <div><b>Retired{{ $property->status_reason ? ': '.$property->status_reason : '' }}</b>. The books stay readable; nothing that was recorded here was deleted.</div></div>
@endif

<div class="stats">
  <x-stat label="Rooms" :value="$health['rooms']" icon="bed" tone="blue" :sub="$health['occupied'].' occupied now'" />
  <x-stat label="Occupancy" :value="$health['occ'].'%'" icon="chart" tone="green" :sub="$health['in_house'].' rooms in-house'" />
  <x-stat label="Business date" :value="$health['business_date'] ? $health['business_date']->format('d M Y') : 'Not set'" icon="calendar" :tone="$health['needs_night_audit'] ? 'amber' : 'teal'" :sub="$health['needs_night_audit'] ? $health['lag'].' day(s) behind — night audit not closed' : 'Rolled forward'" />
  <x-stat label="Access" :value="$access['total']" icon="users" tone="purple" :sub="$access['staff'].' staff · '.$access['granted'].' granted · '.$access['group_admins'].' group admins'" />
  <x-stat label="Open POS" :value="$health['open_pos']" icon="receipt" tone="slate" :sub="$health['open_pos'] ? 'Must be closed before retirement' : 'Nothing open'" />
</div>

<div class="grid-2">
  <div>
    <div class="card"><div class="card-head"><h3>Identity</h3><span class="small muted">Legal name is what prints on invoices</span></div>
      <div class="card-body small">
        <table class="table">
          <tr><th>Property name</th><td>{{ $property->name }}</td></tr>
          <tr><th>Legal name</th><td>{{ $property->legal_name ?: '—' }}</td></tr>
          <tr><th>Hotel ID (login)</th><td class="bold">{{ $property->code }}</td></tr>
          <tr><th>Type / brand</th><td>{{ ucwords(str_replace('_', ' ', (string) $property->property_type)) }}{{ $property->brand ? ' · '.$property->brand : '' }}{{ $property->star_rating ? ' · '.$property->star_rating.'★' : '' }}</td></tr>
          <tr><th>Address</th><td>{{ $addressLine ?: '—' }}</td></tr>
          <tr><th>Contact</th><td>{{ $contactLine ?: '—' }}</td></tr>
          <tr><th>Tax number</th><td>{{ $property->tax_number ?: '—' }} @if($property->vat_registered)<span class="badge badge-green">VAT registered</span>@else<span class="badge badge-slate">Not VAT registered</span>@endif</td></tr>
        </table>
      </div></div>

    <div class="card mt-2"><div class="card-head"><h3>Operations &amp; money</h3></div>
      <div class="card-body small">
        <table class="table">
          <tr><th>Currency</th><td>{{ $property->currency }} ({{ $property->currency_symbol ?: $property->currency }})</td></tr>
          <tr><th>Clock</th><td>{{ $property->timezone }}</td></tr>
          <tr><th>Check-in / out</th><td>{{ $property->checkin_time ?: '—' }} / {{ $property->checkout_time ?: '—' }}</td></tr>
          <tr><th>Trading since</th><td>{{ $property->opened_on ? $property->opened_on->format('d M Y') : '—' }}{{ $property->closed_on ? ' · retired '.$property->closed_on->format('d M Y') : '' }}</td></tr>
          <tr><th>Group</th><td>{{ $property->group?->name ?? 'Standalone' }}{{ $property->group?->management_company ? ' · operated by '.$property->group->management_company : '' }}</td></tr>
        </table>
        @if($property->invoice_footer)<p class="tiny muted mt-1">Invoice footer: {{ $property->invoice_footer }}</p>@endif
      </div></div>

    <div class="card mt-2"><div class="card-head"><h3>Who may enter</h3><span class="small muted">Staff belong to the property; grants are extra</span></div>
      <div class="card-body">
        <p class="small"><b>{{ $ownStaff->count() }}</b> staff account(s) belong to this property @if($access['group_admins']) and <b>{{ $access['group_admins'] }}</b> group administrator(s) may enter it @endif.</p>
        @if($members->isEmpty())
          <p class="small muted">Nobody has been granted cross-property access yet.</p>
        @else
        <table class="table">
          <thead><tr><th>Person</th><th>Role here</th><th>Default</th><th>Last entered</th><th></th></tr></thead>
          <tbody>
          @foreach($members as $m)
            <tr>
              <td><b>{{ $m['user']->name }}</b><div class="tiny muted">{{ $m['user']->username }}</div></td>
              <td class="small">{{ $m['role'] ?? 'Own role' }}</td>
              <td class="center">@if($m['is_default'])<span class="badge badge-blue">Default</span>@else<span class="muted">—</span>@endif</td>
              <td class="small">{{ $m['last_used'] ? $m['last_used']->diffForHumans() : 'never' }}</td>
              <td class="right">@if($canManage)
                <form method="post" action="{{ route('properties.access.revoke', [$property, $m['user']]) }}" onsubmit="return confirm('Remove {{ $m['user']->username }}\'s access to this property?')">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost"><x-icon name="x" size="sm"/> Revoke</button></form>
              @endif</td>
            </tr>
          @endforeach
          </tbody>
        </table>
        @endif
        @if($canManage && ! $property->isArchived())
          <details class="mt-2"><summary class="small bold">Grant somebody access to this property</summary>
            <form method="post" action="{{ route('properties.access.grant', $property) }}" class="form-grid mt-1">@csrf
              <div class="field"><label for="gu">Person</label>
                <select id="gu" name="user_id" required>
                  <option value="">— Select —</option>
                  @foreach($candidates as $c)<option value="{{ $c->id }}">{{ $c->name }} ({{ $c->username }}){{ $c->hotel_id ? ' · property #'.$c->hotel_id : '' }}</option>@endforeach
                </select>
              </div>
              <div class="field"><label for="gr">Role in this property</label>
                <select id="gr" name="role_id"><option value="">Their own role</option>@foreach($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select>
                <span class="hint">A role chosen here applies in this property only.</span>
              </div>
              <label class="check"><input type="checkbox" name="is_default" value="1"> Land them here by default</label>
              <div><button class="btn btn-primary">Grant access</button></div>
            </form>
          </details>
        @endif
      </div></div>
  </div>

  <div>
    <div class="card"><div class="card-head"><h3>Lifecycle</h3></div>
      <div class="card-body">
        <ol class="timeline small">
          <li class="{{ $property->isDraft() ? 'now' : 'done' }}"><b>Draft</b> — being configured, admins only</li>
          <li class="{{ $property->isLive() ? 'now' : ($property->isSuspended() || $property->isArchived() ? 'done' : '') }}"><b>Live</b> — trading, staff can sign in</li>
          <li class="{{ $property->isSuspended() ? 'now' : ($property->isArchived() ? 'done' : '') }}"><b>Suspended</b> — set aside, history kept</li>
          <li class="{{ $property->isArchived() ? 'now' : '' }}"><b>Retired</b> — closed for good, books readable</li>
        </ol>
        @if(! $canManage)
          <p class="tiny muted mt-1">Only a group administrator can change this property's state.</p>
        @elseif($property->isArchived())
          <p class="small muted mt-1">Retired properties cannot be resumed. Open a new property to trade again.</p>
        @elseif($property->isDraft())
          <form method="post" action="{{ route('properties.activate', $property) }}" class="mt-1">@csrf
            <x-field name="business_date" label="Business (audit) date" type="date" :value="$property->business_date?->toDateString()" required hint="The date the first night will post to" />
            <button class="btn btn-primary btn-block"><x-icon name="check" size="sm"/> Take the property live</button>
          </form>
        @elseif($property->isSuspended())
          <form method="post" action="{{ route('properties.resume', $property) }}" class="mt-1">@csrf
            <x-field name="reason" label="Why is it coming back?" hint="Kept in the property's audit trail" />
            <button class="btn btn-primary btn-block"><x-icon name="refresh" size="sm"/> Resume the property</button>
          </form>
        @else
          <form method="post" action="{{ route('properties.suspend', $property) }}" class="mt-1">@csrf
            <x-field name="reason" label="Suspend — reason" required hint="e.g. refurbishment, licence renewal, handover to a new operator" />
            <button class="btn btn-block">Suspend the property</button>
          </form>
        @endif
      </div></div>

    <div class="card mt-2"><div class="card-head"><h3>Retire</h3><span class="small muted">Permanent, and reversible only by opening a new property</span></div>
      <div class="card-body small">
        @if($property->isArchived())
          <p class="muted">This property is retired{{ $property->archived_at ? ' since '.$property->archived_at->format('d M Y') : '' }}.</p>
        @elseif(! $canManage)
          <p class="muted">A group administrator retires a property.</p>
        @else
          @if($blockers)
            <p class="bold">Not yet — still open:</p>
            <ul class="small">@foreach($blockers as $b)<li>{{ $b }}</li>@endforeach</ul>
          @else
            <p>Nothing is in-house and no POS order is open, so this property can be retired.</p>
          @endif
          <form method="post" action="{{ route('properties.archive', $property) }}" onsubmit="return confirm('Retire {{ $property->name }}? Its books stay readable but nobody can work in it again.')">@csrf
            <x-field name="reason" label="Retire — reason" required hint="e.g. lease ended, sold, management contract terminated" />
            <button class="btn btn-danger btn-block" @disabled($blockers)><x-icon name="lock" size="sm"/> Retire this property</button>
          </form>
          @if((int) $property->id === (int) hotel()?->id)<p class="tiny muted mt-1">You are working here — switch to another property before retiring it.</p>@endif
        @endif
      </div></div>

    @if($canTransfer)
    <div class="card mt-2"><div class="card-head"><h3>Hand this property to another group</h3><span class="small muted">Platform administrator only</span></div>
      <div class="card-body small">
        <p>Use this when a management contract changes hands, a lease is reassigned or the portfolio is restructured. The property keeps its books, its rooms, its folios and its audit trail — what changes is who owns the account it sits in.</p>
        <ul class="small">
          <li><b>Staff follow the property.</b> Everyone whose home is this property moves to the receiving group.</li>
          <li><b>A group administrator does not.</b> Any of them whose home is this property is demoted, so a handover cannot hand one tenant's administrator the keys to another tenant.</li>
          <li><b>Cross-property grants are revoked</b> for anybody outside the receiving group — they were trust inside the old tenant, not a right that travels.</li>
          <li><b>The entitlement is checked</b>: the receiving group must have room for one more property.</li>
        </ul>
        @if($transferGroups->isEmpty())
          <p class="muted">There is no other group to hand it to.</p>
        @else
        <form method="post" action="{{ route('properties.transfer', $property) }}" onsubmit="return confirm('Hand {{ $property->name }} to another group? Its staff move with it and outside grants are revoked.')">@csrf
          <x-field name="group_id" label="Receiving group" type="select" required :placeholder="false" :options="$transferGroups->pluck('name', 'id')->all()" />
          <x-field name="reason" label="Reason" required hint="Kept in the property's audit trail, both groups see it" />
          <button class="btn btn-danger btn-block"><x-icon name="swap" size="sm"/> Hand over the property</button>
        </form>
        @endif
      </div></div>
    @endif

    <div class="card mt-2"><div class="card-head"><h3>Module licence at this property</h3><span class="small muted">{{ $licenceNarrowed ? 'Narrower than the group' : 'Follows the group' }}</span></div>
      <div class="card-body">
        <p class="small">
          @if($licenceNarrowed)
            This property is licensed for <b>{{ $licenceCounts['keep'] }}</b> of the group's <b>{{ $licenceCounts['group'] }}</b> module(s). The rest refuse at this property and nowhere else — the group keeps them.
          @else
            Nothing is switched off here, so this property moves with the group: a module the group buys later arrives without anybody visiting this page.
          @endif
        </p>
        @if(! $licenceEditable)
          <p class="small muted">Narrowing one property's licence is a group decision. You can see what this property runs, not change it.</p>
          <ul class="small" style="columns:2">
            @foreach($licence as $module => $state)
              @if($state['group'])
                <li>{{ $module }} @if($state['licensed'])<span class="badge badge-green">on</span>@else<span class="badge badge-slate">off here</span>@endif</li>
              @endif
            @endforeach
          </ul>
        @else
          <form method="post" action="{{ route('properties.licence', $property) }}">@csrf
            <p class="tiny muted">Only the modules the group is licensed for are listed. Untick one to switch it off at this property alone; tick every one and the property follows the group again.</p>
            <div class="flex flex-wrap gap-2" style="columns:2">
              @foreach($licence as $module => $state)
                @if($state['group'])
                  <label class="check" style="display:block;break-inside:avoid"><input type="checkbox" name="modules[]" value="{{ $module }}" @checked($state['licensed'])> {{ $module }}</label>
                @endif
              @endforeach
            </div>
            <button class="btn btn-primary"><x-icon name="key" size="sm"/> Save this property's licence</button>
          </form>
          @if($licenceNarrowed)
            <form method="post" action="{{ route('properties.licence', $property) }}" class="mt-1">@csrf
              <input type="hidden" name="follow" value="1">
              <button class="btn btn-ghost"><x-icon name="refresh" size="sm"/> Follow the group licence again</button>
            </form>
          @endif
        @endif
        @if($licenceGroupRestricted)
          <p class="tiny muted mt-1">The group's own licence is a subset, so this list is short by design: a property can choose from what the group bought, never add to it.</p>
        @endif
      </div></div>

    <div class="card mt-2"><div class="card-head"><h3>Audit trail</h3><span class="small muted">Last 25 decisions</span></div>
      <div class="card-body">
        @if($events->isEmpty())
          <p class="small muted">No property events recorded yet.</p>
        @else
        <ul class="small" style="list-style:none;padding:0;margin:0">
          @foreach($events as $e)
            <li style="padding:.35rem 0;border-bottom:1px solid var(--line,#eee)">
              <span class="badge badge-{{ $e->tone() }}">{{ $e->actionLabel() }}</span>
              {{ $e->summary }}
              <div class="tiny muted">{{ $e->created_at?->format('d M Y H:i') }} · {{ $e->user?->name ?? 'system' }}</div>
            </li>
          @endforeach
        </ul>
        @endif
      </div></div>
  </div>
</div>
@endsection
