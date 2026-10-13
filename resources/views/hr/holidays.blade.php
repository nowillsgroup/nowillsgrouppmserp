@extends('layouts.app')
@section('title', 'Public Holidays · '.$year)
@section('crumbs') HR &amp; Payroll / Public holidays @endsection
@section('actions')
<a class="btn" href="{{ route('hr.attendance') }}"><x-icon name="calendar"/> Daily attendance</a>
<a class="btn" href="{{ route('hr.register', ['month' => bdate()->format('Y-m')]) }}"><x-icon name="grid"/> Monthly register</a>
@endsection
@section('content')

<div class="card"><div class="card-body">
  <form class="filters" method="get">
    <div class="field"><label>Year</label><select name="year" data-autosubmit>@foreach($years as $y)<option value="{{ $y }}" @selected($y == $year)>{{ $y }}</option>@endforeach</select></div>
    <div class="legend small muted">{{ count($rows->where('holiday', true)) }} paid public holiday(s) · {{ $rows->where('commemorative', true)->count() }} commemorative day(s){{ $custom ? ' · <b>edited by this property</b>' : ' · gazetted Ghana calendar' }}</div>
  </form>
</div></div>

<div class="alert alert-info"><x-icon name="info"/><div>
  <b>Public Holidays Act, 2001 (Act 601)</b> as amended by the <b>Public Holidays and Commemorative Days (Amendment) Act, 2025 (Act 1142)</b>, read with the Labour Act, 2003 (Act 651):
  a public holiday is a <b>paid</b> non-working day (s. 72); it <b>does not use up annual leave</b> (s. 22); it is <b>not part of the rest periods</b> (s. 43); and a temporary or casual worker must be paid a full day's remuneration for it and, if he works, the pay for the hours worked on top (s. 77) – the {{ rtrim(rtrim(number_format($multiplier, 2), '0'), '.') }}× holiday rate payroll applies.
  <b>Commemorative days</b> (Africa Unity Day, Republic Day) are observed but are <b>working days</b>: they are listed here and never marked PH.
  Dates that follow the moon and the substitute day given when a holiday falls on a weekend move with the official gazette, so the calendar below is editable.
</div></div>

<div class="grid g-2-1 mt-2">
<div class="card flush"><div class="card-head"><h3>{{ $year }} calendar</h3>
  @if($custom)<span class="badge badge-amber">edited</span>@else<span class="badge badge-blue">gazetted default</span>@endif</div>
  @if(can_do('hr.manage'))
  <form method="post" action="{{ route('hr.holidays.save') }}">@csrf
    <input type="hidden" name="year" value="{{ $year }}">
    <div class="card-body table-wrap"><table class="table table-compact">
      <thead><tr><th style="width:150px">Date</th><th>Name</th><th style="width:150px">Day</th><th style="width:190px">Type</th></tr></thead><tbody>
      @foreach($rows as $r)
        <tr>
          <td><input class="input input-sm" type="date" name="date[]" value="{{ $r['date']->format('Y-m-d') }}"></td>
          <td><input class="input input-sm" name="name[]" value="{{ $r['name'] }}" maxlength="90"></td>
          <td class="small muted">{{ $r['weekday'] }}</td>
          <td><select class="input input-sm" name="commemorative[]"><option value="0" @selected(! $r['commemorative'])>Public holiday (paid)</option><option value="1" @selected($r['commemorative'])>Commemorative day (working)</option></select></td>
        </tr>
      @endforeach
      @for($i = 0; $i < 3; $i++)
        <tr class="muted"><td><input class="input input-sm" type="date" name="date[]" value=""></td>
          <td><input class="input input-sm" name="name[]" placeholder="Add a day (property closure, gazette change…)" maxlength="90"></td>
          <td class="small">new</td>
          <td><select class="input input-sm" name="commemorative[]"><option value="0">Public holiday (paid)</option><option value="1">Commemorative day (working)</option></select></td></tr>
      @endfor
      </tbody></table></div>
    <div class="card-foot flex gap-2 wrap">
      <button class="btn btn-primary"><x-icon name="check"/> Save calendar</button>
      <span class="tiny muted">Clear a date to remove the day. Saved days apply to the roster, the attendance register, leave and every <b>draft</b> payroll run at once; an approved month keeps the calendar it was run on.</span>
    </div>
  </form>
  <form method="post" action="{{ route('hr.holidays.reset') }}" class="card-foot" onsubmit="return confirm('Replace the {{ $year }} calendar with the gazetted Ghana public holidays?')">
    @csrf<input type="hidden" name="year" value="{{ $year }}">
    <button class="btn btn-sm"><x-icon name="refresh"/> Reset {{ $year }} to the gazetted calendar</button>
  </form>
  @else
  <div class="card-body table-wrap"><table class="table table-compact">
    <thead><tr><th>Date</th><th>Holiday</th><th>Day</th><th>Type</th></tr></thead><tbody>
    @foreach($rows as $r)<tr><td>{{ dmy($r['date']) }}</td><td class="bold">{{ $r['name'] }}</td><td class="small muted">{{ $r['weekday'] }}</td>
      <td>@if($r['holiday'])<span class="badge badge-blue">Paid holiday</span>@else<span class="badge badge-slate">Commemorative</span>@endif</td></tr>@endforeach
    </tbody></table></div>
  @endif
</div>

<div>
  <div class="card"><div class="card-head"><h3>Coming up</h3></div><div class="card-body">
    @forelse($upcoming as $h)
      <div class="flex between mb-1"><span><b>{{ $h['name'] }}</b><div class="tiny muted">{{ $h['date']->format('l') }}</div></span>
        <span class="num">{{ dmy($h['date']) }}<div class="tiny muted">in {{ $h['days'] }} day(s)</div></span></div>
    @empty<p class="small muted">No public holiday in the next {{ $notifyDays }} day(s). Reminders go out {{ $notifyDays }} days ahead on the <a href="{{ route('hr.attendance') }}">attendance</a> screen and in the nightly HR run.</p>@endforelse
  </div></div>

  <div class="card"><div class="card-head"><h3>Worked on a holiday this year</h3><span class="badge badge-slate">{{ $worked->count() }}</span></div>
    <div class="card-body table-wrap"><table class="table table-compact">
      <thead><tr><th>Date</th><th>Employee</th><th>Clock</th><th class="num">Hours</th><th class="num">Paid on top</th></tr></thead><tbody>
      @forelse($worked as $a)@php
        $in = \Illuminate\Support\Carbon::parse($a->in_time); $out = \Illuminate\Support\Carbon::parse($a->out_time);
        if ($out->lte($in)) { $out->addDay(); }
        $hrs = floor($in->diffInMinutes($out) / 60 * 4) / 4;
        $rate = $a->employee && (float) $a->employee->salary > 0 ? (float) $a->employee->salary / 30 / max(1, $shiftHours) : 0;
      @endphp<tr><td class="small">{{ dmy($a->date) }}</td><td class="bold small">{{ $a->employee?->name }}<div class="tiny muted">{{ $a->remarks }}</div></td>
        <td class="small">{{ substr((string) $a->in_time, 0, 5) }}–{{ substr((string) $a->out_time, 0, 5) }}</td>
        <td class="num">{{ rtrim(rtrim(number_format($hrs, 2), '0'), '.') }}</td>
        <td class="num">{{ $rate ? money($hrs * $rate * max(0, $multiplier - 1)) : '—' }}</td></tr>
      @empty<tr><td colspan="5"><x-empty text="Nobody has clocked hours on a public holiday this year – the day is paid to everyone whether they work or not." icon="calendar"/></td></tr>@endforelse
      </tbody></table></div>
    <div class="card-foot tiny muted">Hours come from the time clock or the daily register (status <b>PH</b> with clock times). Payroll pays them on the <b>OTH</b> line at the holiday rate; the day itself is already inside the salary.</div>
  </div>

  @if(can_do('payroll.manage'))
  <div class="card"><div class="card-head"><h3>Holiday pay rules</h3></div>
    <form method="post" action="{{ route('hr.holidays.save') }}" class="card-body form-grid">@csrf
      <input type="hidden" name="year" value="{{ $year }}">
      @foreach($rows as $r)<input type="hidden" name="date[]" value="{{ $r['date']->format('Y-m-d') }}"><input type="hidden" name="name[]" value="{{ $r['name'] }}"><input type="hidden" name="commemorative[]" value="{{ $r['commemorative'] ? 1 : 0 }}">@endforeach
      <x-field name="payroll_holiday_multiplier" label="Holiday work multiplier (s. 77)" type="number" step="0.25" min="1" max="4" :value="$multiplier" required
        hint="The day is already paid, so the hours worked earn (multiplier − 1) more. 2 pays the hours again – the double time the industry applies."/>
      <x-field name="hr_holiday_notify_days" label="Remind the roster / payroll this many days ahead" type="number" min="0" max="60" :value="$notifyDays" required/>
      <div><button class="btn btn-primary btn-sm"><x-icon name="check"/> Save rules</button></div>
    </form>
  </div>
  @endif
</div>
</div>
@endsection
