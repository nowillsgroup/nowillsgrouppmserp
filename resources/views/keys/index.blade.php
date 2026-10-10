@extends('layouts.app')
@section('title', 'Key Cards')
@section('crumbs') Interfaces / Key Cards @endsection
@section('actions')
  @if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'key_card_audit') }}"><x-icon name="chart"/> Key audit</a>@endif
  @if(can_do('keys.master'))<button class="btn" data-modal="keyModal" data-title="New master key" data-action="{{ route('keys.master.store') }}" id="newKeyBtn"><x-icon name="key"/> Master key</button>@endif
@endsection
@section('content')
@php $c = $counts; $noCards = $inHouse->filter(fn ($s) => ! isset($cardCounts[$s->id]))->count(); @endphp
@if($cfg['lock_enabled'] !== '1')<div class="alert alert-info"><x-icon name="alert"/><div>The door-lock interface is switched off – cards cannot be encoded until it is switched on in the Interface Hub.</div></div>@endif
@if($c['keys_overdue'])<div class="alert alert-error" id="overdueKeys"><x-icon name="key"/><div><a href="{{ route('keys.index', ['tab' => 'register', 'status' => 'overdue']) }}"><b>{{ $c['keys_overdue'] }}</b> master key(s) overdue</a> – not returned by the end of the sign-out period. Chase the holder or report the key lost.</div></div>@endif
@if($read)<div class="alert alert-{{ $read->isActive() ? 'success' : 'warn' }}" id="readResult"><x-icon name="key"/><div><b>{{ $read->card_no }}</b> · serial {{ $read->serial }} · room <b>{{ $read->room?->room_no }}</b> · {{ $read->holder }} ·
  <span class="badge badge-{{ $read->statusTone() }}">{{ $read->statusLabel() }}</span> · valid {{ $read->valid_from->format('d M H:i') }} → {{ $read->valid_until->format('d M H:i') }}{{ $read->areas ? ' · '.implode(', ', $read->areas) : '' }}</div></div>@endif
<div class="stats">
  <x-stat label="Working guest cards" :value="$c['cards_active']" icon="key" tone="blue" :sub="$inHouse->count().' rooms in house'"/>
  <x-stat label="In-house without a card" :value="$noCards" icon="alert" :tone="$noCards ? 'amber' : 'green'" sub="encode at the desk"/>
  <x-stat label="Master keys out" :value="$c['keys_out']" icon="lock" tone="purple" :href="route('keys.index', ['tab' => 'register', 'status' => 'out'])" sub="signed out to staff"/>
  <x-stat label="Overdue master keys" :value="$c['keys_overdue']" icon="clock" :tone="$c['keys_overdue'] ? 'red' : 'green'" sub="past due time"/>
</div>

@if(can_do('keys.issue'))
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3><x-icon name="key" size="sm"/> Encode guest cards</h3><span class="small muted">{{ $cfg['lock_system'] }}</span></div><div class="card-body">
    <form method="post" action="{{ route('keys.issue') }}" id="encodeForm">@csrf<div class="form-grid">
      <div class="field span-2"><label for="encStay">In-house room</label><select name="stay_id" id="encStay" required><option value="">— Select —</option>
        @foreach($inHouse as $s)<option value="{{ $s->id }}" @selected(old('stay_id') == $s->id)>{{ $s->room?->room_no }} · {{ ($s->guest ?? $s->reservation?->guest)?->full_name }} → {{ $s->departure_date->format('d M') }} ({{ $cardCounts[$s->id] ?? 0 }} card{{ ($cardCounts[$s->id] ?? 0) == 1 ? '' : 's' }})</option>@endforeach</select></div>
      <x-field name="mode" label="Encode" type="select" :options="['new' => 'New key (cancels earlier cards)', 'duplicate' => 'Duplicate (keeps earlier cards)']" value="new" :placeholder="false"/>
      <x-field name="count" label="Cards" type="number" min="1" :max="$cfg['lock_max_cards']" value="2" required/>
      <x-field name="holder" label="Name on card" placeholder="guest name"/>
      <div class="field span-2"><label>Common-area access</label><div class="flex" style="gap:.8rem;flex-wrap:wrap">@foreach($cfg['areas'] as $a)<label class="flex small" style="gap:.3rem;align-items:center"><input type="checkbox" name="areas[]" value="{{ $a }}" @checked(in_array($a, $cfg['default_areas']))> {{ $a }}</label>@endforeach</div></div>
    </div><div class="flex" style="justify-content:space-between;align-items:center;margin-top:.6rem"><span class="small muted">Valid until {{ $cfg['lock_checkout_time'] }} on the departure date + {{ $cfg['lock_grace_minutes'] }} min · max {{ $cfg['lock_max_cards'] }} per room</span><button class="btn btn-primary" id="encodeBtn"><x-icon name="key"/> Encode</button></div></form>
  </div></div>
  <div class="card"><div class="card-head"><h3><x-icon name="search" size="sm"/> Read a card</h3></div><div class="card-body">
    <form method="post" action="{{ route('keys.read') }}" id="readForm" class="flex" style="gap:.5rem">@csrf<input class="input" name="serial" id="readSerial" placeholder="Place the card on the encoder or type its serial" required><button class="btn" id="readBtn">Read</button></form>
    <div class="small muted" style="margin-top:.5rem">Shows which room and guest a found or returned card belongs to and whether it still opens the door.</div>
    <h4 class="small" style="margin:.9rem 0 .3rem">Last encoder messages</h4>
    <table class="table table-compact small" id="lockLog"><tbody>@forelse($lastCommands as $cmd)<tr><td class="nowrap">{{ $cmd->created_at->format('d M H:i') }}</td><td>{{ $cmd->command }}</td><td>{{ $cmd->payload['room'] ?? '' }}</td><td><span class="badge badge-{{ $cmd->status === 'done' ? 'green' : ($cmd->status === 'failed' ? 'red' : 'amber') }}">{{ $cmd->status }}</span></td></tr>@empty<tr><td class="muted">No encoder activity yet.</td></tr>@endforelse</tbody></table>
  </div></div>
</div>
@endif

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\KeyCardController::TABS as $k => $l)<a href="{{ route('keys.index', ['tab' => $k]) }}" class="{{ $tab === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}@if($k === 'register' && $c['keys_overdue']) <span class="badge badge-red">{{ $c['keys_overdue'] }}</span>@endif</a>@endforeach
  </div>
  @if(in_array($tab, ['active', 'history']))
  <form method="get" class="flex" style="gap:.4rem"><input type="hidden" name="tab" value="{{ $tab }}"><input class="input" name="q" value="{{ request('q') }}" placeholder="Room, card, serial, name" style="max-width:12rem">
    @if($tab === 'history')<select class="input" name="status" style="max-width:9rem"><option value="">All</option>@foreach(\App\Models\KeyCard::STATUSES as $k => $s)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $s[0] }}</option>@endforeach</select>@endif
    <button class="btn btn-sm">Filter</button></form>
  @elseif($tab === 'register')
  <form method="get" class="flex" style="gap:.4rem"><input type="hidden" name="tab" value="register"><select class="input" name="status" style="max-width:9rem"><option value="">All</option>@foreach(['out' => 'Out', 'overdue' => 'Overdue', 'returned' => 'Returned', 'lost' => 'Lost'] as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select><button class="btn btn-sm">Filter</button></form>
  @endif
  </div>
  <div class="card-body table-wrap">
  @if(in_array($tab, ['active', 'history']))
    <table class="table table-compact" id="cardsTable"><thead><tr><th>Card</th><th>Room</th><th>Holder</th><th>Type</th><th>Valid</th><th>Access</th><th>Status</th>@if($tab === 'active' && can_do('keys.issue'))<th></th>@endif</tr></thead><tbody>
    @forelse($cards as $k)
      <tr data-card="{{ $k->id }}" data-room="{{ $k->room?->room_no }}"><td class="nowrap"><b>{{ $k->card_no }}</b><div class="small muted mono">{{ $k->serial }}</div></td>
        <td><b>{{ $k->room?->room_no }}</b>@if($k->stay?->reservation)<div class="small muted">{{ $k->stay->reservation->reservation_no }}</div>@endif</td>
        <td>{{ $k->holder }}</td><td class="small">{{ \App\Models\KeyCard::TYPES[$k->type] ?? $k->type }}</td>
        <td class="small nowrap">{{ $k->valid_from->format('d M H:i') }}<div class="muted">→ {{ $k->valid_until->format('d M H:i') }}</div></td>
        <td class="small">{{ $k->areas ? implode(', ', $k->areas) : 'Room only' }}</td>
        <td><span class="badge badge-{{ $k->statusTone() }}">{{ $k->statusLabel() }}</span>@if($k->status_note)<div class="small muted">{{ $k->status_note }}</div>@endif<div class="small muted">by {{ $k->issuer?->name ?? 'system' }}</div></td>
        @if($tab === 'active' && can_do('keys.issue'))<td class="nowrap">
          <form method="post" action="{{ route('keys.return', $k) }}" style="display:inline">@csrf<button class="btn btn-sm" data-return="{{ $k->id }}">Returned</button></form>
          <button class="btn btn-sm btn-danger" data-modal="lostModal" data-action="{{ route('keys.lost', $k) }}" data-title="Card {{ $k->card_no }} (room {{ $k->room?->room_no }}) lost" data-lost="{{ $k->id }}">Lost</button></td>@endif
      </tr>
    @empty<tr><td colspan="8"><x-empty icon="key" :text="$tab === 'active' ? 'No working guest cards.' : 'No cards found.'"/></td></tr>@endforelse
    </tbody></table>
  @elseif($tab === 'master')
    <table class="table table-compact" id="masterTable"><thead><tr><th>Key</th><th>Level</th><th>Opens</th><th>Status</th><th>With</th><th></th></tr></thead><tbody>
    @forelse($keys as $k)
      @php $loan = $k->openLoan; @endphp
      <tr data-mkey="{{ $k->key_no }}" style="{{ $loan?->isOverdue() ? 'background:#fef2f2' : '' }}"><td><b>{{ $k->key_no }}</b><div class="small muted">{{ $k->name }}</div></td>
        <td class="small">{{ \App\Models\MasterKey::LEVELS[$k->level] ?? $k->level }}</td><td class="small">{{ $k->scope ?? '—' }}</td>
        <td><span class="badge badge-{{ ['available' => 'green', 'out' => 'blue', 'lost' => 'red', 'retired' => 'slate'][$k->status] ?? 'slate' }}">{{ ucfirst($k->status) }}</span>@if($loan?->isOverdue()) <span class="badge badge-red">Overdue</span>@endif</td>
        <td class="small">@if($loan)<b>{{ $loan->holder }}</b><div class="muted">since {{ $loan->out_at->format('d M H:i') }} · due {{ $loan->due_at->format('H:i') }}</div>@if($loan->purpose)<div class="muted">{{ $loan->purpose }}</div>@endif @else — @endif</td>
        <td class="nowrap">@if(can_do('keys.master'))
          @if($k->status === 'available')<button class="btn btn-sm btn-primary" data-modal="outModal" data-action="{{ route('keys.master.out', $k) }}" data-title="Sign out {{ $k->key_no }} – {{ $k->name }}" data-out="{{ $k->key_no }}">Sign out</button>@endif
          @if($loan)<form method="post" action="{{ route('keys.master.in', $loan) }}" style="display:inline">@csrf<button class="btn btn-sm btn-primary" data-in="{{ $k->key_no }}">Sign in</button></form>
            <button class="btn btn-sm btn-danger" data-modal="keyLostModal" data-action="{{ route('keys.master.lost', $loan) }}" data-title="{{ $k->key_no }} lost by {{ $loan->holder }}">Lost</button>@endif
          <button class="btn btn-sm" data-modal="keyModal" data-title="Edit {{ $k->key_no }}" data-action="{{ route('keys.master.update', $k) }}" data-fkey_no="{{ $k->key_no }}" data-fname="{{ $k->name }}" data-flevel="{{ $k->level }}" data-fscope="{{ $k->scope }}" data-fdepartment="{{ $k->department }}"><x-icon name="edit" size="sm"/></button>
        @endif</td></tr>
    @empty<tr><td colspan="6"><x-empty icon="lock" text="No master keys registered."/></td></tr>@endforelse
    </tbody></table>
  @else
    <table class="table table-compact" id="loansTable"><thead><tr><th>Key</th><th>Taken by</th><th>Purpose</th><th>Out</th><th>Due</th><th>Back</th><th>Status</th></tr></thead><tbody>
    @forelse($loans as $l)
      <tr data-loan="{{ $l->id }}" style="{{ $l->isOverdue() ? 'background:#fef2f2' : '' }}"><td><b>{{ $l->key?->key_no }}</b><div class="small muted">{{ $l->key?->name }}</div></td><td>{{ $l->holder }}<div class="small muted">issued by {{ $l->issuer?->name ?? '—' }}</div></td>
        <td class="small">{{ $l->purpose ?? '—' }}</td><td class="small nowrap">{{ $l->out_at->format('d M H:i') }}</td><td class="small nowrap">{{ $l->due_at->format('d M H:i') }}</td>
        <td class="small nowrap">{{ $l->in_at?->format('d M H:i') ?? '—' }}@if($l->receiver)<div class="muted">{{ $l->receiver->name }}</div>@endif</td>
        <td>@if($l->isOverdue())<span class="badge badge-red">Overdue</span>@else<span class="badge badge-{{ ['out' => 'blue', 'returned' => 'green', 'lost' => 'red'][$l->status] ?? 'slate' }}">{{ ucfirst($l->status) }}</span>@endif @if($l->note)<div class="small muted">{{ $l->note }}</div>@endif</td></tr>
    @empty<tr><td colspan="7"><x-empty icon="book" text="The key register is empty."/></td></tr>@endforelse
    </tbody></table>
  @endif
  </div>
  @php $pg = $cards ?? $loans; @endphp
  @if($pg && $pg->hasPages())<div class="card-foot">{{ $pg->links() }}</div>@endif
</div>

@if(can_do('keys.issue'))
<div class="modal" id="lostModal"><div class="modal-box" style="max-width:520px"><form method="post" action="#" id="lostForm">@csrf
  <div class="modal-head"><h3 data-title>Card lost</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small muted">The lost card is cancelled. Because a lock only forgets old cards when a <b>new key</b> is used, the guest's other cards are re-encoded as replacements.</p>
    <label class="flex" style="gap:.4rem;align-items:center"><input type="checkbox" name="reissue" value="1" checked> Encode replacement cards now</label>
    <label class="flex" style="gap:.4rem;align-items:center;margin-top:.4rem"><input type="checkbox" name="charge_fee" value="1" id="chargeFee" @checked($cfg['lock_lost_fee'] > 0)> Charge a lost-card fee to the folio</label>
    <x-field name="fee" label="Fee" type="number" step="0.01" min="0" :value="$cfg['lock_lost_fee']"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger" id="confirmLost">Cancel card</button></div>
</form></div></div>
@endif
@if(can_do('keys.master'))
<div class="modal" id="outModal"><div class="modal-box" style="max-width:540px"><form method="post" action="#" id="outForm" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Sign out key</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="user_id" label="Staff member" type="select" :options="$staff" placeholder="— or type a name —"/>
    <x-field name="holder" label="Name (contractor / other)"/>
    <x-field name="purpose" label="Purpose" class="span-2" placeholder="e.g. morning room cleaning floor 1"/>
    <x-field name="hours" label="Hours" type="number" min="1" max="24" :value="$cfg['lock_master_hours']"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="confirmOut">Sign out</button></div>
</form></div></div>
<div class="modal" id="keyLostModal"><div class="modal-box" style="max-width:520px"><form method="post" action="#" id="keyLostForm">@csrf
  <div class="modal-head"><h3 data-title>Master key lost</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="alert alert-error"><x-icon name="alert"/><div>A lost master key is a security incident: it is logged as urgent in the shift log book and the lock system is told to cancel it.</div></div>
    <x-field name="note" label="What happened" type="textarea" rows="2" required/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-danger" id="confirmKeyLost">Report lost</button></div>
</form></div></div>
<div class="modal" id="keyModal"><div class="modal-box" style="max-width:560px"><form method="post" action="{{ route('keys.master.store') }}" id="keyForm" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Master key</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="key_no" label="Key no." required placeholder="e.g. MK-F1"/>
    <x-field name="name" label="Name" required placeholder="e.g. Floor 1 master"/>
    <x-field name="level" label="Level" type="select" :options="\App\Models\MasterKey::LEVELS" value="section" :placeholder="false"/>
    <x-field name="department" label="Department"/>
    <x-field name="scope" label="Opens" class="span-2" placeholder="e.g. rooms 101–110, linen room 1"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveKey">Save</button></div>
</form></div></div>
@endif
@endsection
