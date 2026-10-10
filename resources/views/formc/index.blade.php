@extends('layouts.app')
@section('title', 'Form C · FRRO')
@section('crumbs') Foreign Guests / Form C @endsection
@section('actions')
  <a class="btn" href="{{ route('fg.formc.export', ['from' => bdate()->copy()->subDays(30)->toDateString(), 'to' => bdate()->toDateString()]) }}" id="formcExport"><x-icon name="download"/> Export CSV</a>
  @if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'formc_register') }}"><x-icon name="chart"/> Register</a>@endif
  @if(can_do('formc.manage') || can_do('forex.manage'))<a class="btn" href="{{ route('fg.settings') }}"><x-icon name="tool"/> Settings</a>@endif
@endsection
@section('content')
@unless($enabled)
<div class="alert alert-warn"><x-icon name="alert"/><div>Form C tracking is switched off for this hotel. @if(can_do('formc.manage'))<a href="{{ route('fg.settings') }}">Switch it on</a>@endif</div></div>
@endunless

@php $al = $alerts; @endphp
@if($al['overdue'] || $al['due_soon'] || $al['departures_late'] || $al['nationality'] || $al['visa'])
<div class="alert alert-warn" id="formcAlerts"><x-icon name="alert"/><div class="flex" style="gap:.9rem;flex-wrap:wrap">
  @if($al['overdue'])<a href="{{ route('fg.formc', ['tab' => 'due']) }}" class="text-red" id="alFcOverdue"><b>{{ $al['overdue'] }}</b> Form C overdue – file on the FRRO portal now</a>@endif
  @if($al['due_soon'])<a href="{{ route('fg.formc', ['tab' => 'due']) }}" id="alFcDue"><b>{{ $al['due_soon'] }}</b> due within {{ $cfg['alert_hours'] }} h</a>@endif
  @if($al['departures_late'])<a href="{{ route('fg.formc', ['tab' => 'departures']) }}" class="text-red" id="alFcDep"><b>{{ $al['departures_late'] }}</b> departure(s) not reported after {{ $cfg['departure_hours'] }} h</a>@endif
  @if($al['nationality'])<a href="#natCheck" id="alFcNat"><b>{{ $al['nationality'] }}</b> in-house guest(s) with a passport but no nationality</a>@endif
  @if($al['visa'])<span id="alFcVisa"><b>{{ $al['visa'] }}</b> guest(s) whose visa ends before check-out</span>@endif
</div></div>
@endif

<div class="stats">
  <x-stat label="To submit" :value="$al['pending']" icon="id" :tone="$al['overdue'] ? 'red' : ($al['pending'] ? 'amber' : 'green')" :sub="$al['overdue'].' overdue · '.$al['incomplete'].' incomplete'"/>
  <x-stat label="Departures to report" :value="$al['departures']" icon="plane" :tone="$al['departures_late'] ? 'red' : 'blue'" :sub="$al['departures_late'].' late'"/>
  <x-stat label="Deadline" :value="$cfg['deadline_hours'].' h'" icon="clock" tone="purple" sub="after check-in (Rule 14, RFR 1992)"/>
  <x-stat label="Nationality to confirm" :value="$al['nationality']" icon="globe" :tone="$al['nationality'] ? 'amber' : 'green'" sub="in-house guests"/>
</div>

@if($check->isNotEmpty())
<div class="card" id="natCheck"><div class="card-head"><h3>Confirm nationality</h3><span class="small muted">These in-house guests have a passport / foreign address but no nationality – Form C depends on it.</span></div>
  <div class="card-body table-wrap"><table class="table"><thead><tr><th>Room</th><th>Guest</th><th>Passport · country</th><th class="right">Nationality</th></tr></thead><tbody>
  @foreach($check as $s)
    @php $g = \App\Services\FormCService::stayGuest($s); @endphp
    <tr data-guest="{{ $g->id }}"><td>{{ $s->room?->room_no ?? '—' }}</td><td><a href="{{ route('guests.show', $g) }}">{{ $g->full_name }}</a></td>
      <td class="small">{{ $g->passport_no ? \App\Models\Guest::mask($g->passport_no) : ($g->id_type === 'passport' ? 'passport' : '—') }} · {{ $g->country ?: '—' }}</td>
      <td class="right">@if(can_do('formc.manage'))<form method="post" action="{{ route('fg.nationality', $g) }}" class="flex" style="gap:.4rem;justify-content:flex-end">@csrf
        <input name="nationality" placeholder="e.g. Germany" required style="max-width:10rem" value="{{ \App\Services\FormCService::isForeignNationality($g->country) ? $g->country : '' }}">
        <button class="btn btn-sm btn-primary">Save</button></form>@endif</td></tr>
  @endforeach
  </tbody></table></div></div>
@endif

<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\FormCController::TABS as $k => $l)<a href="{{ route('fg.formc', array_filter(['tab' => $k, 'q' => request('q'), 'nationality' => request('nationality')])) }}" class="{{ $tab === $k ? 'active' : '' }}" data-tab="{{ $k }}">{{ $l }}@if($k === 'due' && $al['pending']) <span class="badge badge-{{ $al['overdue'] ? 'red' : 'amber' }}">{{ $al['pending'] }}</span>@elseif($k === 'departures' && $al['departures']) <span class="badge badge-blue">{{ $al['departures'] }}</span>@endif</a>@endforeach
  </div></div>
  <form class="filters" style="padding:.6rem 1rem 0">
    <input type="hidden" name="tab" value="{{ $tab }}">
    <input class="input" name="q" value="{{ request('q') }}" placeholder="Form no, name, passport or FRRO ref" id="formcSearch">
    <select class="input" name="nationality" data-autosubmit aria-label="Nationality"><option value="">All nationalities</option>@foreach($nationalities as $n)<option @selected(request('nationality') === $n)>{{ $n }}</option>@endforeach</select>
    <button class="btn btn-sm">Filter</button>
  </form>
  <div class="card-body table-wrap"><table class="table" id="formcTable">
    <thead><tr><th>Form</th><th>Guest</th><th>Nationality</th><th>Room</th><th>Checked in</th><th>{{ $tab === 'departures' ? 'Checked out' : 'Deadline' }}</th><th>Details</th><th>Status</th><th class="right"></th></tr></thead><tbody>
    @forelse($records as $f)
      @php $st = $f->state(); $miss = $f->status === 'pending' ? count(\App\Services\FormCService::missing($f)) : 0; @endphp
      <tr data-form="{{ $f->form_no }}" class="{{ $st === 'exempt' ? 'muted-row' : '' }}">
        <td class="nowrap"><a href="{{ route('fg.formc.show', $f) }}" class="bold">{{ $f->form_no }}</a>@if($f->is_companion)<div class="tiny muted">companion</div>@endif</td>
        <td>{{ $f->name() }}<div class="tiny muted">{{ $f->passport_no ? \App\Models\Guest::mask($f->passport_no) : 'no passport no.' }}</div></td>
        <td>{{ $f->nationality ?: '—' }}</td>
        <td>{{ $f->stay?->room?->room_no ?? '—' }}</td>
        <td class="nowrap small">{{ $f->checked_in_at?->format('d M H:i') }}</td>
        <td class="nowrap small">
          @if($tab === 'departures'){{ $f->departed_at?->format('d M H:i') }}
          @elseif($f->status === 'pending')<span class="{{ $f->isOverdue() ? 'text-red bold' : '' }}">{{ $f->deadline_at?->format('d M H:i') }}</span><div class="tiny muted">{{ $f->isOverdue() ? 'overdue '.$f->deadline_at->diffForHumans(null, true) : 'in '.now()->diffForHumans($f->deadline_at, true) }}</div>
          @else{{ $f->deadline_at?->format('d M H:i') }}@endif
        </td>
        <td class="small">@if($f->status === 'pending'){!! $miss ? '<span class="text-red">'.$miss.' missing</span>' : '<span class="text-green">complete</span>' !!}@elseif($f->frro_ref)<span class="muted">{{ $f->frro_ref }}</span>@if($f->isLate()) <span class="badge badge-amber">late</span>@endif @endif</td>
        <td>{!! formc_badge($st) !!}</td>
        <td class="right nowrap"><a class="btn btn-sm" href="{{ route('fg.formc.show', $f) }}">{{ $f->status === 'pending' && can_do('formc.manage') ? 'Complete' : 'Open' }}</a></td>
      </tr>
    @empty<tr><td colspan="9"><x-empty text="Nothing here." icon="id"/></td></tr>@endforelse
  </tbody></table></div>
  <div class="card-foot">{{ $records->links() }}</div>
</div>
<p class="small muted">Form C is filed on <b>indianfrro.gov.in</b> (Foreigners Act 1946 s.14 &amp; Rule 14 of the Registration of Foreigners Rules 1992) within {{ $cfg['deadline_hours'] }} h of a foreign national's arrival; the portal's reference number is recorded here. Departures are reported after check-out.</p>
@endsection
