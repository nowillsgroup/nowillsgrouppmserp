{{-- One block of a briefing. Every pack is built from the same three shapes, so the band is
     written once: a row of stats, a table with an empty state, or a list of what needs doing. --}}
<div class="card brf-band" data-band="{{ $section['key'] }}">
  <div class="card-head">
    <h3>{{ $section['title'] }}</h3>
    @if(!empty($section['note']))<span class="small muted">{{ $section['note'] }}</span>@endif
  </div>

  @if($section['kind'] === 'stats')
    <div class="card-body">
      <div class="stats brf-stats">
        @foreach($section['stats'] as $s)
          <x-stat :label="$s['label']" :value="$s['value']" :sub="$s['sub'] ?? null" :tone="$s['tone'] ?? 'slate'" :icon="$s['icon'] ?? 'grid'"/>
        @endforeach
      </div>
    </div>

  @elseif($section['kind'] === 'table')
    @if(count($section['rows']))
      <div class="table-wrap">
        <table class="table table-compact brf-table">
          <thead><tr>@foreach($section['cols'] as $col)<th>{{ $col['label'] }}</th>@endforeach</tr></thead>
          <tbody>
            @foreach($section['rows'] as $row)
              <tr>
                @foreach($section['cols'] as $col)
                  @php $v = $row[$col['key']] ?? ['text' => '—', 'tone' => null]; @endphp
                  <td>
                    @if(!empty($v['tone']))
                      <span class="badge badge-{{ $v['tone'] }}">{{ $v['text'] }}</span>
                    @else
                      {{ $v['text'] }}
                    @endif
                  </td>
                @endforeach
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @else
      <div class="card-body"><x-empty :text="$section['empty']"/></div>
    @endif

  @else
    @if(count($section['items'] ?? []))
      <ul class="brf-list">
        @foreach($section['items'] as $item)
          <li class="brf-li tone-{{ $item['tone'] ?? 'slate' }}">
            <span class="brf-dot"></span>
            <span class="brf-li-text">{{ $item['text'] }}</span>
            @if(!empty($item['value']))<span class="brf-li-value">{{ $item['value'] }}</span>@endif
          </li>
        @endforeach
      </ul>
    @else
      <div class="card-body"><x-empty :text="$section['empty']" icon="check"/></div>
    @endif
  @endif
</div>
