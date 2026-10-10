@extends('layouts.app')
@section('title', 'Scheduled Reports')
@section('crumbs') <a href="{{ route('reports.index') }}">Reports</a> / Scheduled reports @endsection
@section('actions')
<a class="btn" href="{{ route('reports.library') }}"><x-icon name="star"/> My reports</a>
@if(can_do('reports.alerts'))<a class="btn" href="{{ route('reports.alerts') }}"><x-icon name="alert"/> KPI alerts</a>@endif
@endsection
@php
  $RS = \App\Services\ReportScheduleService::class; $S = \App\Models\ReportSchedule::class; $me = auth()->user();
  $e = $editing; $newKey = request('new'); $newView = request('view');
  $f = fn ($k, $def = null) => old($k, $e ? $e->{$k} : $def);
  $selKey = old('report_key', $e?->report_key ?? ($newKey && isset($reports[$newKey]) ? $newKey : 'flash'));
  $selView = old('report_view_id', $e?->report_view_id ?? $newView);
  $selUsers = array_map('intval', (array) old('recipient_users', $e?->recipient_users ?? [$me->id]));
  $tone = ['sent' => 'green', 'partial' => 'amber', 'failed' => 'red', 'skipped' => 'slate'];
  $allowed = $RS::cfg('rpt_email_domains');
@endphp
@section('content')
<div class="stats">
  <x-stat label="Active schedules" :value="$stats['active']" icon="mail" tone="green" :sub="$stats['paused'].' paused'"/>
  <x-stat label="Sent (7 days)" :value="$stats['sent_7d']" icon="check" tone="blue" sub="report e-mails delivered"/>
  <x-stat label="Failed runs (7 days)" :value="$stats['failed_7d']" icon="alert" :tone="$stats['failed_7d'] ? 'red' : 'slate'" :sub="$stats['failing'].' schedule(s) currently failing'"/>
  <x-stat label="Saved views" :value="$stats['views']" icon="star" tone="purple" sub="all users"/>
</div>

<details class="card" id="scheduleForm" {{ $e || $newKey || $errors->any() || session('error') ? 'open' : '' }}>
  <summary class="card-head" style="cursor:pointer"><h3><x-icon name="{{ $e ? 'edit' : 'plus' }}"/> {{ $e ? 'Edit schedule “'.$e->name.'”' : 'New scheduled report' }}</h3><span class="small muted">the report runs with the owner's permissions{{ $allowed ? ' · recipients limited to '.$allowed : '' }}</span></summary>
  <div class="card-body">
  <form method="post" action="{{ $e ? route('reports.schedules.update', $e) : route('reports.schedules.store') }}">@csrf @if($e) @method('PUT') @endif
    <div class="form-grid">
      <div class="field"><label for="s_name">Name</label><input id="s_name" name="name" maxlength="120" value="{{ $f('name') }}" placeholder="e.g. Morning flash for the GM"></div>
      <div class="field"><label for="s_view">Saved view <span class="muted tiny">(optional)</span></label><select id="s_view" name="report_view_id"><option value="">— pick a report instead —</option>
        @foreach($views as $v)<option value="{{ $v->id }}" @selected((string) $selView === (string) $v->id)>{{ $v->name }} · {{ $v->title() }}</option>@endforeach</select></div>
      <div class="field"><label for="s_report">Report</label><select id="s_report" name="report_key">@foreach($reports as $k => $t)<option value="{{ $k }}" @selected($selKey === $k)>{{ $t }}</option>@endforeach</select></div>
      <div class="field"><label for="s_range">Period</label><select id="s_range" name="range">@foreach($RS::RANGES as $rk => $rl) @continue($rk === 'custom')<option value="{{ $rk }}" @selected($f('range', $RS::defaultRange($selKey)) === $rk)>{{ $rl }}</option>@endforeach</select><small class="muted">"As on" reports use the period's end date. A saved view keeps its own period.</small></div>
      <div class="field"><label for="s_freq">Send</label><select id="s_freq" name="frequency" data-freq>@foreach($S::FREQUENCIES as $k => $l)<option value="{{ $k }}" @selected($f('frequency', 'daily') === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="field" data-when="weekly"><label for="s_wd">Weekday</label><select id="s_wd" name="weekday">@foreach($S::WEEKDAYS as $k => $l)<option value="{{ $k }}" @selected((int) $f('weekday', 1) === $k)>{{ $l }}</option>@endforeach</select></div>
      <div class="field" data-when="monthly"><label for="s_md">Day of month</label><select id="s_md" name="month_day">@for($i = 1; $i <= 28; $i++)<option value="{{ $i }}" @selected((int) $f('month_day', 1) === $i)>{{ ordinal_day($i) }}</option>@endfor<option value="0" @selected((string) $f('month_day') === '0')>Last day</option></select></div>
      <div class="field" data-when="daily,weekly,monthly"><label for="s_time">At (hotel time)</label><input id="s_time" type="time" name="send_time" value="{{ $f('send_time', '07:00') }}"></div>
      <div class="field"><label for="s_fmt">Format</label><select id="s_fmt" name="format">@foreach($S::FORMATS as $k => $l)<option value="{{ $k }}" @selected($f('format', 'both') === $k)>{{ $l }}</option>@endforeach</select></div>
    </div>
    <div class="field mt-1"><label>Colleagues <span class="muted tiny">(must be allowed to see the report)</span></label>
      <div class="flex gap-2 wrap">@foreach($users as $u)<label class="check small"><input type="checkbox" name="recipient_users[]" value="{{ $u->id }}" @checked(in_array($u->id, $selUsers))> <span>{{ $u->name }} <span class="muted tiny">{{ $u->email }}</span></span></label>@endforeach</div></div>
    <div class="form-grid mt-1">
      <div class="field span-2"><label for="s_emails">Other e-mail addresses</label><textarea id="s_emails" name="recipient_emails" rows="2" placeholder="owner@example.com, gm@example.com">{{ $f('recipient_emails') }}</textarea><small class="muted">Comma-separated. Max {{ $RS::cfg('rpt_max_recipients') }} recipients in total.</small></div>
      <div class="field span-2"><label for="s_msg">Note in the e-mail <span class="muted tiny">(optional)</span></label><textarea id="s_msg" name="message" rows="2" maxlength="500">{{ $f('message') }}</textarea></div>
      <label class="check"><input type="checkbox" name="skip_empty" value="1" @checked($f('skip_empty'))> <span>Don't send when the report is empty</span></label>
    </div>
    <div class="flex gap-1 mt-1"><button class="btn btn-primary" id="scheduleSave">{{ $e ? 'Update schedule' : 'Create schedule' }}</button>@if($e)<a class="btn" href="{{ route('reports.schedules') }}">Cancel</a>@endif</div>
  </form>
  </div>
</details>

<div data-tabwrap>
<div class="tabs" data-tabs>
  <button class="active" data-tab="t-schedules">Schedules ({{ $schedules->count() }})</button>
  <button data-tab="t-log">Delivery log</button>
  @if(can_do('settings.manage'))<button data-tab="t-settings">Settings</button>@endif
</div>

<div class="tab-pane active" id="t-schedules"><div class="card flush"><div class="card-body table-wrap"><table class="table" id="schedTable">
  <thead><tr><th>Schedule</th><th>Report &amp; period</th><th>When</th><th>Recipients</th><th>Owner</th><th>Last run</th><th>Next run</th><th></th></tr></thead>
  <tbody>@forelse($schedules as $s) @php $can = $s->user_id === $me->id || can_do('settings.manage'); $rc = count($s->emails()); @endphp
    <tr data-schedule="{{ $s->id }}" class="{{ $s->is_active ? '' : 'muted' }}">
      <td class="bold">{{ $s->name }} @if(! $s->is_active)<span class="badge badge-slate">paused</span>@endif @if($s->fail_count)<span class="badge badge-red" title="{{ $s->last_error }}">{{ $s->fail_count }} failure(s)</span>@endif</td>
      <td class="small">{{ $s->title() }}<div class="tiny muted">{{ $s->view ? 'view “'.$s->view->name.'” · ' : '' }}{{ $RS::RANGES[$s->range] ?? $s->range }} · {{ $S::FORMATS[$s->format] ?? $s->format }}</div></td>
      <td class="small">{{ $s->when() }}</td>
      <td class="small" title="{{ implode(', ', $s->emails()) }}">{{ $rc }} recipient(s)@if($s->recipient_emails)<div class="tiny muted">incl. external</div>@endif</td>
      <td class="small">{{ $s->user?->name }}</td>
      <td class="small">@if($s->last_run_at){{ $s->last_run_at->format('d M H:i') }} <span class="badge badge-{{ $tone[$s->last_status] ?? 'slate' }}">{{ $s->last_status }}</span>@if($s->last_error)<div class="tiny text-red">{{ \Illuminate\Support\Str::limit($s->last_error, 90) }}</div>@endif @else — @endif</td>
      <td class="small">{{ $s->next_run_at?->format('D d M H:i') ?? ($s->is_active && $s->frequency === 'after_audit' ? 'next night audit' : '—') }}</td>
      <td class="nowrap right">
        @if($can)
        <form method="post" action="{{ route('reports.schedules.run', $s) }}" style="display:inline">@csrf<button class="btn btn-sm btn-primary" title="Run and e-mail now"><x-icon name="mail"/> Send now</button></form>
        <form method="post" action="{{ route('reports.schedules.toggle', $s) }}" style="display:inline">@csrf<button class="btn btn-sm">{{ $s->is_active ? 'Pause' : 'Resume' }}</button></form>
        <a class="btn btn-sm btn-ghost" href="{{ route('reports.schedules', ['edit' => $s->id]) }}#scheduleForm" title="Edit"><x-icon name="edit"/></a>
        <form method="post" action="{{ route('reports.schedules.destroy', $s) }}" style="display:inline" data-confirm="Delete the schedule “{{ $s->name }}”?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost" title="Delete"><x-icon name="trash"/></button></form>
        @endif
        <a class="btn btn-sm btn-ghost" href="{{ route('reports.schedules', ['schedule' => $s->id]) }}#t-log" title="History"><x-icon name="clock"/></a>
      </td>
    </tr>
  @empty
    <tr><td colspan="8"><x-empty text="No scheduled reports yet." icon="mail"/></td></tr>
  @endforelse</tbody>
</table></div></div></div>

<div class="tab-pane" id="t-log">
  <form class="filters" method="get"><input type="hidden" name="schedule" value="{{ request('schedule') }}">
    <div class="field"><label>Status</label><select name="status" onchange="this.form.submit()"><option value="">All</option>@foreach($tone as $k => $t)<option value="{{ $k }}" @selected(request('status') === $k)>{{ ucfirst($k) }}</option>@endforeach</select></div>
    @if(request('schedule'))<a class="btn btn-sm btn-soft" href="{{ route('reports.schedules') }}#t-log">All schedules ✕</a>@endif
  </form>
  <div class="card flush"><div class="card-body table-wrap"><table class="table table-compact" id="deliveryTable">
  <thead><tr><th>When</th><th>Schedule</th><th>Period</th><th>Trigger</th><th>Status</th><th class="num">Rows</th><th>Delivered</th><th>Detail</th><th></th></tr></thead>
  <tbody>@forelse($deliveries as $d)
    <tr data-delivery="{{ $d->id }}"><td class="small nowrap">{{ $d->created_at->format('d M H:i') }}</td><td class="small">{{ $d->title }}</td>
      <td class="small">{{ $d->period_from ? ($d->period_from->equalTo($d->period_to) ? $d->period_from->format('d M Y') : $d->period_from->format('d M').' – '.$d->period_to->format('d M Y')) : '—' }}</td>
      <td class="small">{{ ['schedule' => 'Scheduled', 'after_audit' => 'After night audit', 'manual' => 'Sent now'][$d->trigger] ?? $d->trigger }}@if($d->user)<div class="tiny muted">{{ $d->user->name }}</div>@endif</td>
      <td><span class="badge badge-{{ $tone[$d->status] ?? 'slate' }}">{{ $d->status }}</span></td>
      <td class="num">{{ $d->rows }}</td><td class="small">{{ $d->delivered }} / {{ $d->recipients }}<div class="tiny muted">{{ \Illuminate\Support\Str::limit($d->sent_to, 60) }}</div></td>
      <td class="tiny {{ $d->status === 'failed' ? 'text-red' : 'muted' }}">{{ \Illuminate\Support\Str::limit($d->error, 110) }}</td>
      <td>@if($d->period_to && $d->status !== 'failed')<a class="btn btn-sm btn-ghost" href="{{ route('reports.deliveries.csv', $d) }}" title="Download CSV (re-generated for the same period)"><x-icon name="download"/></a>@endif</td></tr>
  @empty
    <tr><td colspan="9"><x-empty text="Nothing has been sent yet." icon="mail"/></td></tr>
  @endforelse</tbody></table></div></div>
  {{ $deliveries->fragment('t-log')->links() }}
</div>

@if(can_do('settings.manage'))
<div class="tab-pane" id="t-settings"><div class="card"><div class="card-body">
  <form method="post" action="{{ route('reports.schedules.settings') }}">@csrf<div class="form-grid">
    @foreach($RS::SETTINGS as $k => [$def, $label])
      <div class="field {{ $k === 'rpt_email_domains' ? 'span-2' : '' }}"><label for="{{ $k }}">{{ $label }}</label><input id="{{ $k }}" name="{{ $k }}" value="{{ $RS::cfg($k) }}" {{ $k === 'rpt_email_domains' ? 'placeholder=nowills.com.gh' : 'type=number min=1' }}></div>
    @endforeach
  </div><button class="btn btn-primary mt-1">Save settings</button></form>
  <p class="small muted mt-1">Due schedules are sent by <code>php artisan pms:reports-run</code>, which the scheduler runs every 5 minutes (<code>php artisan schedule:run</code> from cron). “After the night audit” schedules and KPI alerts run when the audit closes the day.</p>
</div></div></div>
@endif
</div>
@push('scripts')
<script>
(() => {
  const f = document.querySelector('[data-freq]'); if (!f) return;
  const sync = () => document.querySelectorAll('[data-when]').forEach(el => { el.hidden = !el.dataset.when.split(',').includes(f.value); });
  f.addEventListener('change', sync); sync();
  const v = document.getElementById('s_view'), r = document.getElementById('s_report'), p = document.getElementById('s_range');
  const lock = () => { r.disabled = p.disabled = !!v.value; };
  v.addEventListener('change', lock); lock();
})();
</script>
@endpush
@endsection
