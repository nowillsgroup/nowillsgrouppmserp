@extends('layouts.app')
@section('title', 'Parking Settings')
@section('crumbs') Parking &amp; Valet / Tariff &amp; settings @endsection
@section('content')
<div class="g-2-1">
<div class="card"><div class="card-head"><h3>Tariff &amp; valet</h3></div><div class="card-body">
<form method="post" action="{{ route('parking.settings.save') }}" id="pkSettingsForm">@csrf<div class="form-grid">
  <div class="field"><label>Guest self-park per night</label><input type="number" name="guest_rate" min="0" step="0.01" value="{{ $cfg['guest_rate'] }}" required><span class="tiny muted">0 = complimentary</span></div>
  <div class="field"><label>Guest valet per night</label><input type="number" name="valet_rate" min="0" step="0.01" value="{{ $cfg['valet_rate'] }}" required></div>
  <div class="field span-all"><label class="check"><input type="checkbox" name="audit_post" value="1" @checked($cfg['audit_post'])> The night audit charges guest parking to the room folio each night</label></div>
  <div class="field"><label>Visitor free minutes</label><input type="number" name="free_minutes" min="0" max="240" value="{{ $cfg['free_minutes'] }}" required></div>
  <div class="field"><label>Visitor rate per hour</label><input type="number" name="hour_rate" min="0" step="0.01" value="{{ $cfg['hour_rate'] }}" required></div>
  <div class="field"><label>Visitor maximum per day</label><input type="number" name="daily_max" min="0" step="0.01" value="{{ $cfg['daily_max'] }}" required><span class="tiny muted">0 = no cap</span></div>
  <div class="field"><label>Visitor valet fee</label><input type="number" name="valet_fee" min="0" step="0.01" value="{{ $cfg['valet_fee'] }}" required></div>
  <div class="field"><label>Lost-ticket fee</label><input type="number" name="lost_fee" min="0" step="0.01" value="{{ $cfg['lost_fee'] }}" required></div>
  <div class="field"><label>Outlet validation up to (hours)</label><input type="number" name="validation_max" min="0" max="24" value="{{ $cfg['validation_max'] }}" required><span class="tiny muted">0 = no validation</span></div>
  <div class="field"><label>Valet retrieval target (min)</label><input type="number" name="sla_minutes" min="1" max="120" value="{{ $cfg['sla_minutes'] }}" required></div>
  <div class="field"><label>Visitor overstay alert (hours)</label><input type="number" name="overstay_hours" min="1" max="720" value="{{ $cfg['overstay_hours'] }}" required></div>
  <div class="field"><label>"Nearly full" at %</label><input type="number" name="full_pct" min="50" max="100" value="{{ $cfg['full_pct'] }}" required></div>
</div><p class="tiny muted mt-1">Charges post under the <b>Parking &amp; Valet</b> category (GL 4075, USALI other operated department "Parking") and carry VAT, NHIL and GETFund like other services.</p>
<button class="btn btn-primary mt-1">Save settings</button></form></div></div>
<div class="card flush"><div class="card-head"><h3>Visitor tariff check</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="pkTariffTable"><thead><tr><th>Parked</th><th class="num">Billable h</th><th class="num">Fee (net)</th></tr></thead><tbody>
  @foreach($examples as $e)<tr><td>{{ intdiv($e['m'], 60) ? intdiv($e['m'], 60).' h ' : '' }}{{ $e['m'] % 60 ? ($e['m'] % 60).' min' : '' }}</td><td class="num">{{ $e['t']['hours'] }}</td><td class="num">{{ money($e['t']['amount']) }}</td></tr>@endforeach
</tbody></table><p class="tiny muted" style="padding:0 1rem 1rem">Hours are rounded up once the free period is over; each full day costs the daily maximum. Validation hours come off the time first.</p></div></div>
</div>
@endsection
