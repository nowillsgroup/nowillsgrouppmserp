@php $c = $a['carbon']; $h = $a['hcmi']; $w = $a['waste']; $s = $a['social']; $g = $a['gov']; $src = $a['sourcing']; $gr = $a['green'];
  $f = fn ($v, $d = 1, $sfx = '') => $v === null ? '—' : number_format($v, $d).$sfx; @endphp
<div class="esg-report" id="esgReport">
  <p class="small">Reporting period <b>{{ \Carbon\Carbon::parse($a['from'])->format('d M Y') }} – {{ \Carbon\Carbon::parse($a['to'])->format('d M Y') }}</b>@if($a['env_from'] !== $a['from']) · energy &amp; carbon from {{ \Carbon\Carbon::parse($a['env_from'])->format('d M Y') }} (first meter reading)@endif.
    Carbon follows the GHG Protocol scopes; the per-room figure follows the Hotel Carbon Measurement Initiative (HCMI).</p>
  <div class="kpis">
    <div><b>{{ $f($c['total_kg'] / 1000, 1) }} t</b><span>CO₂e, Scopes 1–3</span></div><div><b>{{ $f($h['per_room'], 2) }} kg</b><span>CO₂e per occupied room (HCMI)</span></div>
    <div><b>{{ $f($h['hwmi_room'], 0) }} L</b><span>water per occupied room (HWMI)</span></div><div><b>{{ $f($w['diversion'], 1, '%') }}</b><span>waste diverted from landfill</span></div>
    <div><b>{{ $f($s['female_pct'], 1, '%') }}</b><span>women in the workforce</span></div><div><b>{{ $f($s['female_mgmt_pct'], 1, '%') }}</b><span>women in management</span></div>
    <div><b>{{ $f($src['pct'], 1, '%') }}</b><span>spend with local suppliers</span></div><div><b>{{ $g['cert']['score'] ?? '—' }}%</b><span>certification readiness</span></div>
  </div>

  <h2>Environment</h2>
  <table><thead><tr><th>Scope</th><th>Source</th><th>Activity</th><th>t CO₂e</th></tr></thead><tbody>
    @foreach(collect($c['rows'])->sortBy('scope') as $r)<tr><td>Scope {{ $r['scope'] }}</td><td>{{ $r['label'] }}</td><td class="n">{{ number_format($r['qty'], $r['qty'] < 100 ? 1 : 0) }} {{ $r['unit'] }}</td><td class="n">{{ number_format($r['kg'] / 1000, 2) }}</td></tr>@endforeach
    <tr><th colspan="3">Scope 1 {{ number_format($c['scopes'][1] / 1000, 2) }} t · Scope 2 {{ number_format($c['scopes'][2] / 1000, 2) }} t · Scope 3 {{ number_format($c['scopes'][3] / 1000, 2) }} t</th><th class="n">{{ number_format($c['total_kg'] / 1000, 2) }}</th></tr>
  </tbody></table>
  <table><tbody>
    <tr><th>Energy</th><td class="n">{{ number_format($c['kwh']) }} kWh</td><th>Renewable (solar)</th><td class="n">{{ number_format($c['solar_kwh']) }} kWh · {{ $c['grid_kwh'] + $c['solar_kwh'] > 0 ? round($c['solar_kwh'] / ($c['grid_kwh'] + $c['solar_kwh']) * 100, 1) : 0 }}%</td></tr>
    <tr><th>Energy per occupied room</th><td class="n">{{ $f($h['kwh_room']) }} kWh</td><th>Meeting space</th><td class="n">{{ $f($h['per_m2h'], 4) }} kg CO₂e / m²·h</td></tr>
    <tr><th>Water</th><td class="n">{{ number_format($c['water_m3']) }} m³</td><th>Occupied rooms</th><td class="n">{{ number_format($h['occupied']) }}</td></tr>
    <tr><th>Waste</th><td class="n">{{ number_format($w['total']) }} kg · {{ $f($w['per_room'], 2) }} kg / room</td><th>Food waste</th><td class="n">{{ number_format($w['food']) }} kg</td></tr>
    <tr><th>Refrigerant leakage</th><td class="n">{{ number_format(($c['rows']['refrigerant']['kg'] ?? 0) / 1000, 2) }} t CO₂e</td><th>Green-stay nights</th><td class="n">{{ $gr['nights'] }} · {{ number_format($gr['co2'], 0) }} kg CO₂e saved</td></tr>
  </tbody></table>

  <h2>Targets</h2>
  <table><thead><tr><th>Metric</th><th>Baseline</th><th>Current</th><th>Target</th><th>Status</th></tr></thead><tbody>
    @forelse($a['targets'] as $t)<tr><td>{{ $t['label'] }}</td><td class="n">{{ number_format($t['target']->baseline_value, $t['dp']) }} ({{ $t['target']->baseline_year }})</td><td class="n">{{ $t['current'] !== null ? number_format($t['current'], $t['dp']) : '—' }} {{ $t['unit'] }}</td>
      <td class="n">{{ number_format($t['target']->target_value, $t['dp']) }} ({{ $t['target']->target_year }})</td><td>{{ ['achieved' => 'Achieved', 'on_track' => 'On track', 'behind' => 'Behind', 'no_data' => 'No data'][$t['state']] }}</td></tr>
    @empty<tr><td colspan="5">No targets set.</td></tr>@endforelse
  </tbody></table>

  <h2>Initiatives</h2>
  <table><thead><tr><th>Initiative</th><th>Status</th><th>Capex</th><th>Saving / yr</th><th>t CO₂e / yr</th></tr></thead><tbody>
    @forelse($a['initiatives'] as $i)<tr><td>{{ $i->number }} {{ $i->title }}</td><td>{{ $i->statusLabel() }}{{ $i->completed_on ? ' '.$i->completed_on->format('M Y') : '' }}</td><td class="n">{{ money($i->capex) }}</td><td class="n">{{ money($i->saving_ghs) }}</td><td class="n">{{ number_format($i->saving_co2_kg / 1000, 2) }}</td></tr>
    @empty<tr><td colspan="5">None.</td></tr>@endforelse
  </tbody></table>

  <h2>Social</h2>
  <table><tbody>
    <tr><th>Headcount</th><td class="n">{{ $s['headcount'] }} ({{ $s['female'] }} women, {{ $s['male'] }} men)</td><th>Ghanaian staff</th><td class="n">{{ $f($s['local_pct'], 1, '%') }}</td></tr>
    <tr><th>New hires</th><td class="n">{{ $s['hires'] }} ({{ $s['local_hires'] }} local)</td><th>Turnover</th><td class="n">{{ $f($s['turnover_pct'], 1, '%') }}</td></tr>
    <tr><th>Training hours</th><td class="n">{{ number_format($s['training_hours']) }} ({{ $f($s['training_per_emp']) }} per employee)</td><th>LTIFR</th><td class="n">{{ $f($s['ltifr'], 2) }} ({{ $s['lti'] }} lost-time injuries)</td></tr>
    <tr><th>Gender pay gap (mean basic)</th><td class="n">{{ $f($s['pay_gap_pct'], 1, '%') }}</td><th>Below minimum wage</th><td class="n">{{ $s['below_min']->count() }}</td></tr>
    <tr><th>Community investment</th><td class="n">{{ money($s['community']['amount']) }}</td><th>Volunteering</th><td class="n">{{ number_format($s['community']['hours']) }} h · {{ number_format($s['community']['people']) }} people reached</td></tr>
  </tbody></table>
  @if($a['community_list']->count())<table><thead><tr><th>Date</th><th>Contribution</th><th>Beneficiary</th><th>Value</th></tr></thead><tbody>
    @foreach($a['community_list'] as $x)<tr><td>{{ $x->date->format('d M') }}</td><td>{{ $x->kindLabel() }}</td><td>{{ $x->beneficiary }}</td><td class="n">{{ (float) $x->amount ? money($x->amount) : $x->hours.' h' }}</td></tr>@endforeach
  </tbody></table>@endif

  <h2>Governance</h2>
  <table><tbody>
    <tr><th>Policies in force</th><td class="n">{{ $g['active'] }} of {{ $g['policies'] }} ({{ $g['overdue'] }} past review)</td><th>Certification readiness</th><td class="n">{{ $g['cert']['score'] ?? '—' }}% · {{ $g['cert']['mandatory_met'] }}/{{ $g['cert']['mandatory'] }} mandatory</td></tr>
    <tr><th>Local purchasing</th><td class="n">{{ money($src['local']) }} of {{ money($src['total']) }}</td><th>Data breaches (12 m)</th><td class="n">{{ $g['breaches'] }}</td></tr>
    <tr><th>Licences / permits expired</th><td class="n">{{ $g['compliance_expired'] }} of {{ $g['compliance_total'] }}</td><th>Refrigerant systems above leak alert</th><td class="n">{{ collect($a['leaks'])->where('flag', true)->count() }}</td></tr>
  </tbody></table>
  <p class="tiny" style="color:#64748b">Factors: Ghana grid 0.314 kg CO₂e/kWh (Ember 2023 generation mix) unless changed in settings; DEFRA / DESNZ factors for water and waste; IPCC AR5 GWP-100 for refrigerants. Figures are management estimates and have not been externally assured.</p>
</div>
