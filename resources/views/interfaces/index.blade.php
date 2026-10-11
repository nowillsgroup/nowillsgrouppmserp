@extends('layouts.app')
@section('title', 'Interface Hub')
@section('crumbs') Interfaces / Hub @endsection
@section('actions')
  <button class="btn btn-primary" data-modal="ifSettings" id="ifSettingsBtn"><x-icon name="tool"/> Settings</button>
@endsection
@section('content')
@php $c = $counts; $on = fn ($s) => $cfg[$s.'_enabled'] === '1'; @endphp
@if($c['commands_failed'])<div class="alert alert-error" id="failedCommands"><x-icon name="alert"/><div><b>{{ $c['commands_failed'] }}</b> command(s) to the PABX / lock system failed in the last 24 hours. Check the interface connection, then retry.
  <form method="post" action="{{ route('interfaces.retry_all') }}" style="display:inline">@csrf<button class="btn btn-sm" id="retryAllBtn">Retry PABX commands</button></form></div></div>@endif
<div class="grid g-2">
  <div class="card" id="pbxStatus"><div class="card-head"><h3><x-icon name="phone" size="sm"/> PABX / call accounting</h3><span class="badge badge-{{ $on('pbx') ? ($cfg['pbx_driver'] === 'offline' ? 'amber' : 'green') : 'slate' }}">{{ $on('pbx') ? ($cfg['pbx_driver'] === 'offline' ? 'Offline' : ($cfg['pbx_driver'] === 'tcp' ? 'Live (TCP)' : 'Connected')) : 'Off' }}</span></div><div class="card-body"><dl class="kv">
    <dt>Driver</dt><dd>{{ ['demo' => 'Demo (acknowledges at once)', 'offline' => 'Offline – commands queue', 'tcp' => 'TCP template (live PABX)'][$cfg['pbx_driver']] ?? $cfg['pbx_driver'] }}</dd>
    <dt>Auto-post to folio</dt><dd>{{ $cfg['pbx_auto_post'] === '1' ? 'Yes' : 'No – every call goes to review' }}</dd>
    <dt>Last call record</dt><dd>{{ $lastCall ? $lastCall->created_at->diffForHumans().' (ext '.$lastCall->extension.')' : 'none yet' }}</dd>
    <dt>Last PBX push</dt><dd>{{ $lastPush ? \Illuminate\Support\Carbon::parse($lastPush)->diffForHumans() : 'never' }}</dd>
    <dt>SMDR columns</dt><dd><code>{{ $cfg['pbx_format'] }}</code></dd>
    <dt>Class of service at check-in</dt><dd>{{ $cosOptions[$cfg['pbx_default_cos']] }}</dd>
    <dt>Review queue</dt><dd><a href="{{ route('calls.index', ['tab' => 'review']) }}">{{ $c['calls_pending'] }} call(s)</a></dd>
  </dl>
  <div class="field" style="margin-top:.6rem"><label>PBX push URL (POST raw SMDR lines or JSON)</label><div class="flex" style="gap:.4rem"><input class="input mono" readonly value="{{ $pushUrl }}" id="pushUrl" onclick="this.select()">
    <form method="post" action="{{ route('interfaces.token') }}" data-confirm="Generate a new token? The PBX must be updated with the new URL.">@csrf<button class="btn btn-sm" id="tokenBtn"><x-icon name="refresh" size="sm"/> New token</button></form></div>
    <span class="hint">JSON: {"records":[{"ext":"101","number":"04422334455","started_at":"{{ now()->format('Y-m-d H:i:s') }}","duration":185,"trunk":"T1"}]}</span></div>
  </div></div>
  <div class="card" id="lockStatus"><div class="card-head"><h3><x-icon name="key" size="sm"/> Door locks</h3><span class="badge badge-{{ $on('lock') ? ($cfg['lock_driver'] === 'offline' ? 'amber' : 'green') : 'slate' }}">{{ $on('lock') ? ($cfg['lock_driver'] === 'offline' ? 'Offline' : 'Connected') : 'Off' }}</span></div><div class="card-body"><dl class="kv">
    <dt>Lock system</dt><dd>{{ $cfg['lock_system'] }} ({{ $cfg['lock_driver'] }})</dd>
    <dt>Cards per room</dt><dd>up to {{ $cfg['lock_max_cards'] }}{{ $cfg['lock_auto_issue'] ? ' · '.$cfg['lock_auto_issue'].' encoded automatically at check-in' : ' · encoded by the desk' }}</dd>
    <dt>Cards expire</dt><dd>{{ $cfg['lock_checkout_time'] }} on departure + {{ $cfg['lock_grace_minutes'] }} min</dd>
    <dt>Lost-card fee</dt><dd>{{ $cfg['lock_lost_fee'] > 0 ? money($cfg['lock_lost_fee']) : 'none' }}</dd>
    <dt>Common areas</dt><dd>{{ implode(', ', $cfg['areas']) ?: '—' }}</dd>
    <dt>Working guest cards</dt><dd><a href="{{ route('keys.index') }}">{{ $c['cards_active'] }}</a></dd>
    <dt>Master keys out</dt><dd>{{ $c['keys_out'] }}{{ $c['keys_overdue'] ? ' ('.$c['keys_overdue'].' overdue)' : '' }}</dd>
  </dl></div></div>
</div>

<div class="card flush">
  <div class="card-head"><h3>Command log</h3>
    <form method="get" class="flex" style="gap:.4rem"><select class="input" name="system" style="max-width:8rem"><option value="">All systems</option><option value="pbx" @selected($system === 'pbx')>PABX</option><option value="lock" @selected($system === 'lock')>Locks</option></select>
      <select class="input" name="status" style="max-width:8rem"><option value="">All</option>@foreach(['done', 'failed', 'queued'] as $s)<option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>@endforeach</select><button class="btn btn-sm">Filter</button></form></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="commandLog"><thead><tr><th>#</th><th>Time</th><th>System</th><th>Command</th><th>Room</th><th>Details</th><th>Status</th><th>By</th><th></th></tr></thead><tbody>
  @forelse($commands as $cmd)
    <tr data-cmd="{{ $cmd->id }}" data-status="{{ $cmd->status }}"><td class="small muted">{{ $cmd->id }}</td><td class="small nowrap">{{ $cmd->created_at->format('d M H:i:s') }}</td><td>{{ $cmd->system === 'pbx' ? 'PABX' : 'Lock' }}</td><td><b>{{ $cmd->command }}</b></td>
      <td>{{ $cmd->room?->room_no ?? '—' }}</td>
      <td class="small mono" style="max-width:22rem">{{ collect($cmd->payload ?? [])->map(fn ($v, $k) => $k.'='.(is_array($v) ? implode('/', $v) : $v))->implode(' ') }}</td>
      <td><span class="badge badge-{{ $cmd->status === 'done' ? 'green' : ($cmd->status === 'failed' ? 'red' : 'amber') }}">{{ $cmd->status }}</span>@if($cmd->response)<div class="small muted">{{ $cmd->response }}</div>@endif</td>
      <td class="small">{{ $cmd->user?->name ?? 'system' }}</td>
      <td>@if($cmd->status !== 'done' && ! ($cmd->system === 'lock' && in_array($cmd->command, ['issue', 'duplicate'])))<form method="post" action="{{ route('interfaces.retry', $cmd) }}">@csrf<button class="btn btn-sm" data-retry="{{ $cmd->id }}">Retry</button></form>@endif</td></tr>
  @empty<tr><td colspan="9"><x-empty icon="refresh" text="No commands sent yet."/></td></tr>@endforelse
  </tbody></table></div>
  @if($commands->hasPages())<div class="card-foot">{{ $commands->links() }}</div>@endif
</div>

<div class="modal" id="ifSettings"><div class="modal-box" style="max-width:860px"><form method="post" action="{{ route('interfaces.settings') }}" id="ifSettingsForm">@csrf
  <div class="modal-head"><h3>Interface settings</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <h4 style="margin:0 0 .4rem">PABX / call accounting</h4>
    <div class="form-grid">
      <label class="flex" style="gap:.4rem;align-items:center"><input type="checkbox" name="pbx_enabled" value="1" @checked($cfg['pbx_enabled'] === '1')> PABX interface on</label>
      <label class="flex" style="gap:.4rem;align-items:center"><input type="checkbox" name="pbx_auto_post" value="1" @checked($cfg['pbx_auto_post'] === '1')> Post guest calls automatically</label>
      <x-field name="pbx_driver" label="Driver" type="select" :options="['demo' => 'Demo', 'offline' => 'Offline (queue commands)', 'tcp' => 'TCP template (live PABX)']" :value="$cfg['pbx_driver']" :placeholder="false"/>
      <x-field name="pbx_default_cos" label="Class of service at check-in" type="select" :options="$cosOptions" :value="$cfg['pbx_default_cos']" :placeholder="false"/>
      <x-field name="pbx_min_secs" label="Unanswered below (s)" type="number" min="0" max="60" :value="$cfg['pbx_min_secs']" required/>
      <x-field name="pbx_strip_prefix" label="Outside-line digit to strip" :value="$cfg['pbx_strip_prefix']" placeholder="e.g. 9"/>
      <x-field name="pbx_free_numbers" label="Free numbers (* = prefix)" class="span-2" :value="$cfg['pbx_free_numbers']"/>
      <x-field name="pbx_format" label="SMDR column order" class="span-2" :value="$cfg['pbx_format']" hint="date, time, datetime, ext, trunk, number, duration, skip"/>
      <label class="flex" style="gap:.4rem;align-items:center"><input type="checkbox" name="pbx_mask_digits" value="1" @checked($cfg['pbx_mask_digits'] === '1')> Mask last 4 digits on the bill</label>
    </div>
    <h4 style="margin:1rem 0 .4rem">TCP command link &amp; SMDR capture <span class="small muted">(live driver)</span></h4>
    <div class="form-grid">
      <x-field name="pbx_tcp_host" label="Command host" :value="$cfg['pbx_tcp_host']" placeholder="e.g. 192.168.1.20"/>
      <x-field name="pbx_tcp_port" label="Command port" type="number" min="0" max="65535" :value="$cfg['pbx_tcp_port']"/>
      <x-field name="pbx_tcp_timeout" label="Reply timeout (s)" type="number" min="1" max="60" :value="$cfg['pbx_tcp_timeout']"/>
      <x-field name="pbx_tcp_ok" label="Success pattern" :value="$cfg['pbx_tcp_ok']" hint="regex on the reply; blank = PBX does not answer"/>
      <x-field name="pbx_smdr_host" label="SMDR host" :value="$cfg['pbx_smdr_host']" hint="pms:pbx:listen connects here"/>
      <x-field name="pbx_smdr_port" label="SMDR port" type="number" min="0" max="65535" :value="$cfg['pbx_smdr_port']" hint="0 = capture off"/>
      <x-field name="pbx_cmd_checkin" label="Command · check-in" class="span-2" :value="$cfg['pbx_cmd_checkin']" placeholder="e.g. CMD CHECKIN {extension} {name} {cos}" hint="placeholders: {extension} {name} {cos} {language} {date} {time} {datetime}"/>
      <x-field name="pbx_cmd_checkout" label="Command · check-out" class="span-2" :value="$cfg['pbx_cmd_checkout']" placeholder="e.g. COS {extension} BARRED" hint="{extension} {cos} {date} {time} {datetime}"/>
      <x-field name="pbx_cmd_cos" label="Command · class of service" class="span-2" :value="$cfg['pbx_cmd_cos']" placeholder="e.g. COS {extension} {cos}" hint="{extension} {cos} {date} {time} {datetime}"/>
      <x-field name="pbx_cmd_move" label="Command · room move" class="span-2" :value="$cfg['pbx_cmd_move']" placeholder="e.g. MOVE {from} {to}" hint="{from} {to} {cos} {date} {time} {datetime}"/>
    </div>
    <h4 style="margin:1rem 0 .4rem">Door locks</h4>
    <div class="form-grid">
      <label class="flex" style="gap:.4rem;align-items:center"><input type="checkbox" name="lock_enabled" value="1" @checked($cfg['lock_enabled'] === '1')> Lock interface on</label>
      <x-field name="lock_driver" label="Driver" type="select" :options="['demo' => 'Demo', 'offline' => 'Offline']" :value="$cfg['lock_driver']" :placeholder="false"/>
      <x-field name="lock_system" label="Lock system" :value="$cfg['lock_system']"/>
      <x-field name="lock_max_cards" label="Max cards per room" type="number" min="1" max="10" :value="$cfg['lock_max_cards']" required/>
      <x-field name="lock_auto_issue" label="Cards encoded at check-in" type="number" min="0" max="4" :value="$cfg['lock_auto_issue']" required hint="0 = the desk encodes"/>
      <x-field name="lock_lost_fee" label="Lost-card fee" type="number" step="0.01" min="0" :value="$cfg['lock_lost_fee']" required/>
      <x-field name="lock_checkout_time" label="Cards expire at" type="time" :value="$cfg['lock_checkout_time']" required/>
      <x-field name="lock_grace_minutes" label="Grace (min)" type="number" min="0" max="720" :value="$cfg['lock_grace_minutes']" required/>
      <x-field name="lock_master_hours" label="Master key sign-out (h)" type="number" min="1" max="24" :value="$cfg['lock_master_hours']" required/>
      <x-field name="lock_areas" label="Common areas" class="span-2" :value="$cfg['lock_areas']" hint="comma separated"/>
      <div class="field span-2"><label>Granted by default</label><div class="flex" style="gap:.8rem;flex-wrap:wrap">@foreach($cfg['areas'] as $a)<label class="flex small" style="gap:.3rem;align-items:center"><input type="checkbox" name="lock_default_areas[]" value="{{ $a }}" @checked(in_array($a, $cfg['default_areas']))> {{ $a }}</label>@endforeach</div></div>
    </div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveIfSettings">Save settings</button></div>
</form></div></div>
@endsection
