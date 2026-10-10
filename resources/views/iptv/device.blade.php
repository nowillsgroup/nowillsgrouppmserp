@extends('layouts.app')
@section('title', $d->name)
@section('crumbs') IPTV &amp; Guest Welcome / <a href="{{ route('iptv.devices') }}">TVs &amp; Screens</a> / {{ $d->name }} @endsection
@php $u = auth()->user(); $canMg = \App\Services\IptvService::canManage($u); $D = \App\Models\TvDevice::class; $C = \App\Models\TvCommand::class; $E = \App\Models\TvEvent::EVENTS; $g = $stay ? ($stay->guest ?? $stay->reservation?->guest) : null; @endphp
@section('actions')
  @if($canMg)<button class="btn" data-modal="tvEditModal" id="tvEditBtn"><x-icon name="edit"/> Edit</button>@endif
@endsection
@section('content')
<div class="stats">
  <x-stat label="State" :value="$d->stateLabel()" icon="wifi" :tone="$d->stateTone()" :sub="$d->last_seen_at ? 'last heartbeat '.$d->last_seen_at->diffForHumans() : 'never connected'"/>
  <x-stat label="Where" :value="$d->place()" icon="bed" tone="blue" :sub="$D::KINDS[$d->kind] ?? $d->kind"/>
  <x-stat label="Guest" :value="$g ? trim($g->first_name.' '.$g->last_name) : ($d->kind === 'room' ? 'Vacant' : '—')" icon="user" tone="purple" :sub="$stay ? 'until '.$stay->departure_date->format('d M Y') : ''" :href="$stay ? route('reservations.show', $stay->reservation_id) : null"/>
  <x-stat label="Platform" :value="$D::PLATFORMS[$d->platform] ?? $d->platform" icon="grid" tone="slate" :sub="trim(($d->model ?? '').' '.($d->serial ? '· S/N '.$d->serial : ''))"/>
</div>
<div class="g-2-1">
  <div>
    @if($canMg)
    <div class="card" id="tvStartUrl"><div class="card-head"><h3>Start URL</h3>
      <form method="post" action="{{ route('iptv.devices.rotate', $d) }}" onsubmit="return confirm('Issue a new URL? The TV stops working until the new URL is entered on it.')">@csrf<button class="btn btn-sm">Issue new URL</button></form></div><div class="card-body">
      <code class="tv-url">{{ $d->url() }}</code>
      <p class="tiny muted m-0">Enter this as the TV’s hotel-mode start page / URL launcher address. It identifies the set – treat it like a key. {{ $d->paired_at ? 'Paired '.$d->paired_at->format('d M Y H:i').($d->pairedBy ? ' by '.$d->pairedBy->name : '').'.' : '' }} {{ $d->user_agent ? 'Browser: '.\Illuminate\Support\Str::limit($d->user_agent, 90) : '' }}</p>
    </div></div>
    @endif
    <div class="card flush"><div class="card-head"><h3>Guest sessions</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="tvSessions"><thead><tr><th>Guest</th><th>Started</th><th>Welcome</th><th>Language</th><th>Ended</th></tr></thead><tbody>
    @forelse($sessions as $s)@php $sg = $s->stay?->guest ?? $s->stay?->reservation?->guest; @endphp<tr><td class="small">{{ $sg ? trim($sg->first_name.' '.$sg->last_name) : '—' }}</td><td class="small nowrap">{{ $s->started_at->format('d M H:i') }}</td>
      <td class="small">{{ $s->template?->name ?? '—' }}{!! $s->welcomed_at ? ' <span class="tiny muted">seen '.$s->welcomed_at->format('H:i').'</span>' : '' !!}</td><td class="small">{{ \App\Support\TvLang::LANGS[$s->language] ?? '—' }}</td>
      <td class="small nowrap">{{ $s->ended_at ? $s->ended_at->format('d M H:i').' · '.str_replace('_', ' ', $s->end_reason) : 'active' }}</td></tr>
    @empty<tr><td colspan="5" class="muted">No guest sessions yet.</td></tr>@endforelse
    </tbody></table></div></div>
    <div class="card flush"><div class="card-head"><h3>Recent activity</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="tvDevEvents"><tbody>
    @forelse($events as $e)<tr><td class="small nowrap">{{ $e->created_at->format('d M H:i') }}</td><td class="small">{{ $E[$e->event] ?? $e->event }}</td><td class="small muted">{{ $e->target }}</td></tr>
    @empty<tr><td class="muted">Nothing yet.</td></tr>@endforelse
    </tbody></table></div></div>
  </div>
  <div>
    @if($canMg && $d->status === 'active')
    <div class="card"><div class="card-head"><h3>Remote command</h3></div><div class="card-body">
      <form method="post" action="{{ route('iptv.devices.command', $d) }}" class="form-grid" id="tvCommandForm">@csrf
        <div class="field span-all"><label>Command</label><select name="command" required>@foreach($C::COMMANDS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
        <div class="field"><label>Volume (volume)</label><input type="number" name="level" min="0" max="100" placeholder="0–100"></div>
        <div class="field"><label>Channel (tune)</label><select name="channel"><option value="">—</option>@foreach($channels as $c)<option value="{{ $c->number }}">{{ $c->number }} {{ $c->name }}</option>@endforeach</select></div>
        <div class="field span-all"><label>Text (test message)</label><input name="text" maxlength="120"></div>
        <div class="span-all"><button class="btn btn-primary">Send</button> <span class="tiny muted">Runs on the TV’s next heartbeat.</span></div>
      </form>
    </div></div>
    @endif
    <div class="card flush"><div class="card-head"><h3>Command log</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="tvCommandLog"><tbody>
    @forelse($commands as $c)<tr><td class="small">{{ $C::COMMANDS[$c->command] ?? $c->command }}<div class="tiny muted">{{ $c->created_at->format('d M H:i') }} · {{ $c->issuer?->name ?? 'system' }}{{ $c->result ? ' · '.$c->result : '' }}</div></td>
      <td><span class="badge badge-{{ ['done' => 'green', 'failed' => 'red', 'expired' => 'slate', 'sent' => 'blue'][$c->status] ?? 'amber' }}">{{ $c->status }}</span></td></tr>
    @empty<tr><td class="muted">No commands sent.</td></tr>@endforelse
    </tbody></table></div></div>
    @if($canMg)
    <div class="card"><div class="card-head"><h3>Status</h3></div><div class="card-body flex" style="gap:.5rem;flex-wrap:wrap">
      @if($d->status === 'active')<form method="post" action="{{ route('iptv.devices.status', $d) }}">@csrf<input type="hidden" name="status" value="disabled"><button class="btn btn-sm">Disable</button></form>
      @elseif($d->status === 'disabled')<form method="post" action="{{ route('iptv.devices.status', $d) }}">@csrf<input type="hidden" name="status" value="active"><button class="btn btn-sm btn-primary">Enable</button></form>@endif
      <form method="post" action="{{ route('iptv.devices.delete', $d) }}" onsubmit="return confirm('Remove this TV? Its URL stops working.')">@csrf @method('DELETE')<button class="btn btn-sm btn-danger">Remove</button></form>
    </div></div>
    @endif
  </div>
</div>
@if($canMg)
<div class="modal" id="tvEditModal"><div class="modal-box lg"><form method="post" action="{{ route('iptv.devices.update', $d) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>Edit {{ $d->name }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">@include('iptv._device_fields', ['x' => $d])</div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
@endif
@endsection
