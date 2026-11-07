@extends('layouts.app')
@section('title', 'Group account')
@section('crumbs')<a href="{{ route('properties.index') }}">Properties</a> / {{ $group->name }}@endsection
@section('actions')
  <span class="badge badge-{{ $group->isSuspended() ? 'red' : 'green' }}">{{ $group->statusLabel() }}</span>
  <a class="btn" href="{{ route('group.people.index', ['group' => $group->id]) }}"><x-icon name="users" size="sm"/> Its people</a>
  <a class="btn" href="{{ route('properties.index', ['group' => $group->id]) }}"><x-icon name="building" size="sm"/> Its properties</a>
@endsection
@section('content')

@if($group->isSuspended())
  <div class="alert alert-error"><x-icon name="lock"/><div><b>This group is suspended{{ $group->suspended_at ? ' since '.$group->suspended_at->format('d M Y') : '' }}.</b> Nobody can sign in to any of its properties. The books are untouched; resuming the group puts its live properties back in reach.</div></div>
@endif

<div class="stats">
  <x-stat label="Properties" :value="$summary['properties']" icon="building" tone="blue" :sub="$summary['live'].' live · '.$summary['draft'].' draft · '.$summary['suspended'].' suspended'" />
  <x-stat label="Entitlement" :value="$group->max_properties ?? 'Unlimited'" icon="key" tone="purple" :sub="$group->remainingProperties() === null ? 'No cap on properties' : $group->remainingProperties().' left to open'" />
  <x-stat label="Rooms" :value="number_format($summary['rooms'])" icon="bed" tone="teal" :sub="$summary['occ'].'% occupied group-wide'" />
  <x-stat label="People" :value="number_format($summary['staff'])" icon="users" tone="green" :sub="$admins->count().' group administrator(s)'" />
  <x-stat label="Seats" :value="number_format($group->userCount()).($group->max_users ? ' / '.number_format($group->max_users) : '')" icon="key" :tone="$group->overSeats() ? 'red' : 'teal'" :sub="$group->max_users === null ? 'No seat cap' : ($group->overSeats() ? 'Over by '.$group->overSeats().' — new accounts are refused' : $group->remainingSeats().' seat(s) left')" />
</div>

<form method="post" action="{{ route('group.account.update', ['group' => $group->id]) }}">
@csrf
<div class="grid-2">
  <div>
    <div class="card"><div class="card-head"><h3>Identity</h3><span class="small muted">The tenant account</span></div>
      <div class="card-body form-grid">
        <x-field name="name" label="Group name" required :value="$group->name" />
        <x-field name="code" label="Group code" required :value="$group->code" hint="Short code used on reports and exports." />
        <x-field name="legal_name" label="Legal name" :value="$group->legal_name" hint="The entity that signs management and owner agreements." />
        <x-field name="brand" label="Brand" :value="$group->brand" />
        <x-field name="management_company" label="Management company" :value="$group->management_company" hint="Who operates these properties, if it is not the owning company." />
        <x-field name="description" label="Description" type="textarea" :value="$group->description" />
      </div></div>

    <div class="card mt-2"><div class="card-head"><h3>Group contact</h3></div>
      <div class="card-body form-grid">
        <x-field name="contact_name" label="Contact name" :value="$group->contact_name" />
        <x-field name="contact_email" label="Contact e-mail" type="email" :value="$group->contact_email" />
        <x-field name="contact_phone" label="Contact phone" :value="$group->contact_phone" />
      </div></div>
  </div>

  <div>
    <div class="card"><div class="card-head"><h3>Defaults new properties inherit</h3><span class="small muted">Never the current property's</span></div>
      <div class="card-body form-grid">
        <x-field name="country" label="Country" :value="$group->country" />
        <x-field name="currency" label="Currency code" :value="$group->currency" hint="e.g. GHS, NGN, USD." />
        <x-field name="timezone" label="Time zone" :value="$group->timezone" hint="e.g. Africa/Accra." />
        <x-field name="default_property_type" label="Default property type" type="select" :options="$types" :value="$group->default_property_type" />
        <x-field name="max_properties" label="Property entitlement" type="number" min="1" max="500" :value="$group->max_properties" hint="Blank means no cap. A new property is refused once the cap is reached." />
        <x-field name="max_users" label="Seat entitlement" type="number" min="1" max="5000" :value="$group->max_users" hint="Blank means no cap. Enforced on every screen that creates an account — this group's people and each property's users." />
        <x-field name="licence_ends_on" label="Licence renews on" type="date" :value="$group->licence_ends_on?->toDateString()" hint="Printed here for the account manager. Nothing switches itself off on this date." />
        <div class="field"><label for="f_seat_basis">Seats are counted per</label>
          <select id="f_seat_basis" name="seat_basis">
            <option value="active" @selected($seatBasis === 'active')>Active account — switching somebody off frees the seat</option>
            <option value="named" @selected($seatBasis === 'named')>Named user — every account holds a seat, switched off or not</option>
          </select>
          <span class="hint">A named-user licence is bought per person, so a switched-off account keeps its seat.</span>
          @if($seatsReleased > 0)
            <span class="hint"><b>{{ $seatsReleased }} seat(s) released:</b> {{ $darkProperties }} propert{{ $darkProperties === 1 ? 'y is' : 'ies are' }} licensed for no modules at all, so nobody can work there and its people are not holding a seat.</span>
          @endif
        </div>
        <x-field name="notes" label="Internal notes" type="textarea" :value="$group->notes" />
      </div>
      <div class="card-body">
        <button class="btn btn-primary btn-block"><x-icon name="check" size="sm"/> Save group settings</button>
        <p class="tiny muted mt-1">Changing a default never rewrites a property that already exists — it only applies to the next one opened.</p>
      </div></div>

</div>
</div>
</form>

<div class="card mt-2"><div class="card-head"><h3>Module licence</h3><span class="small muted">{{ $licenceRestricted ? 'This tenant is licensed for a subset' : 'Everything, including modules added later' }}</span></div>
<div class="card-body">
  @if(! $licenceEditable)
    <p class="small muted">The module licence is a commercial decision taken by the platform administrator. You can see what this tenant has bought here, not change it.</p>
    <ul class="small" style="columns:2">
      @foreach($licence as $module => $state)
        <li>{{ $module }} @if($state['licensed'])<span class="badge badge-green">on</span>@else<span class="badge badge-slate">not licensed</span>@endif</li>
      @endforeach
    </ul>
  @else
    <p class="small">Every module the application gates a route with is listed below. Untick one and its screens refuse to open <em>and</em> disappear from the sidebar; the data behind them is left exactly where it is, so re-licensing restores access rather than recovering anything.</p>
    <form method="post" action="{{ route('group.licence', ['group' => $group->id]) }}">@csrf
      <div class="flex flex-wrap gap-2" style="columns:2">
        @foreach($licence as $module => $state)
          <label class="check" style="display:block;break-inside:avoid"><input type="checkbox" name="modules[]" value="{{ $module }}" @checked($state['licensed'])> {{ $module }} <span class="tiny muted">{{ $state['permissions'] }} permission(s)</span></label>
        @endforeach
      </div>
      <p class="tiny muted mt-1">Ticking every module stores "everything", so a module shipped by a later release is licensed without anybody revisiting this page. Unticking any module stores an explicit licence.</p>
      <button class="btn btn-primary"><x-icon name="key" size="sm"/> Save the licence</button>
    </form>
  @endif

  <p class="small mt-2"><b>The guest surfaces follow the same licence.</b> A guest is not signed in, so there is no permission to check — the property itself is the only thing known when the page is refused. {{ $guestCoverage['fromUrl'] }} of the {{ $guestCoverage['total'] }} are decided from the URL before the controller runs, so a write never happens; the rest are listed above and refused when their own link is resolved.</p>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Module</th><th>Guest surface</th><th>With this licence</th></tr></thead>
    <tbody>
    @foreach($guestSurfaces as $module => $labels)
      @foreach($labels as $label)
        <tr><td class="small"><b>{{ $module }}</b></td><td class="small">{{ $label }}</td>
          <td>@if(($licence[$module]['licensed'] ?? true))<span class="badge badge-green">open</span>@else<span class="badge badge-slate">404</span>@endif</td></tr>
      @endforeach
    @endforeach
    </tbody>
  </table></div>
  <p class="tiny muted">A property may run fewer of these modules than its group — that is set on the property's own page, and it can only narrow this list, never widen it.</p>
  <p class="tiny muted">Where the property is in the URL (a hotel code, or a token that names its property) the answer is decided before the controller runs, so a write never happens. Where the link resolves its own stay, device or kiosk, a read is refused once it has resolved. The REST API asks the same question after the bearer key has named the hotel.</p>
</div></div>

@if($group->overSeats())
  <div class="alert alert-warn"><x-icon name="alert"/><div><b>{{ $group->overSeats() }} seat(s) over the entitlement.</b> {{ $group->userCount() }} active people against {{ $group->max_users }} bought. Existing accounts keep working; no new account can be created until the cap is raised or somebody is switched off.</div></div>
@endif

<div class="card mt-2"><div class="card-head"><h3>Tenant status</h3></div>
      <div class="card-body">
        @if(! $canSuspendGroup)
          <p class="small muted">Suspending the whole group is reserved for the platform administrator. You can suspend an individual property instead.</p>
        @elseif($group->isSuspended())
          <form method="post" action="{{ route('group.resume', ['group' => $group->id]) }}">@csrf
            <p class="small">Resuming puts every <b>live</b> property of this group back in reach. Suspended and retired properties stay as they are.</p>
            <button class="btn btn-primary btn-block"><x-icon name="refresh" size="sm"/> Resume the group</button>
          </form>
        @else
          <form method="post" action="{{ route('group.suspend', ['group' => $group->id]) }}" onsubmit="return confirm('Suspend {{ $group->name }}? Every one of its properties becomes unreachable.')">@csrf
            <p class="small">Suspending the group stops <b>every</b> property at once. Use it for a lapsed contract or a compliance hold, never for one property's refurbishment.</p>
            <x-field name="reason" label="Reason" required hint="Kept in the group's audit trail" />
            <button class="btn btn-danger btn-block"><x-icon name="lock" size="sm"/> Suspend the group</button>
          </form>
        @endif
      </div></div>
  

<div class="card mt-2"><div class="card-head"><h3>Who administers this tenant</h3><span class="small muted">Group administrators may enter every property of the group</span></div>
<div class="card-body table-wrap">
  @if($admins->isEmpty())
    <p class="small muted">Nobody holds group administration. Only a platform administrator can create one.</p>
  @else
  <table class="table">
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Reach</th></tr></thead>
    <tbody>
    @foreach($admins as $a)
      <tr><td><b>{{ $a->name }}</b></td><td class="small">{{ $a->username }}</td><td class="small">{{ $a->role?->name ?? '—' }}</td>
        <td class="small">Every property in the group, including drafts and suspended ones</td></tr>
    @endforeach
    </tbody>
  </table>
  @endif
</div></div>

<div class="card flush mt-2"><div class="card-head"><h3>Its properties</h3><span class="small muted">Open one to change its state or its access list</span></div>
<div class="card-body table-wrap">
  @if(! $rows)
    <x-empty icon="building" text="This group has no properties yet." />
  @else
  <table class="table">
    <thead><tr><th>Property</th><th>State</th><th>Business date</th><th class="center">Rooms</th><th class="num">Staff</th><th>Clock</th><th></th></tr></thead>
    <tbody>
    @foreach($rows as $r)
      @php $p = $r['hotel']; @endphp
      <tr><td><b>{{ $p->name }}</b><div class="tiny muted">{{ $p->code }} · {{ $p->city ?: '—' }}</div></td>
        <td><span class="badge badge-{{ $p->statusTone() }}">{{ $p->statusLabel() }}</span></td>
        <td class="small">{{ $r['business_date'] ? $r['business_date']->format('d M Y') : '—' }}@if($r['needs_night_audit']) <span class="badge badge-amber">{{ $r['lag'] }}d</span>@endif</td>
        <td class="center">{{ $r['rooms'] }}</td>
        <td class="num">{{ $r['staff'] }}</td>
        <td class="small">{{ $p->currency }} · {{ $p->timezone }}</td>
        <td class="right"><a class="btn btn-sm" href="{{ route('properties.show', $p) }}">Open</a></td></tr>
    @endforeach
    </tbody>
  </table>
  @endif
</div></div>

<div class="card mt-2"><div class="card-head"><h3>Group audit trail</h3><span class="small muted">Lifecycle and access decisions across the portfolio</span></div>
<div class="card-body">
  @if($events->isEmpty())
    <p class="small muted">Nothing recorded yet.</p>
  @else
  <ul class="small" style="list-style:none;padding:0;margin:0">
    @foreach($events as $e)
      <li style="padding:.35rem 0;border-bottom:1px solid var(--line,#eee)">
        <span class="badge badge-{{ $e->tone() }}">{{ $e->actionLabel() }}</span> {{ $e->summary }}
        <div class="tiny muted">{{ $e->created_at?->format('d M Y H:i') }} · {{ $e->user?->name ?? 'system' }}{{ $e->hotel ? ' · '.$e->hotel->name : '' }}</div>
      </li>
    @endforeach
  </ul>
  @endif
</div></div>
@endsection
