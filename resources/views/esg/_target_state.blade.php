@php $st = $t['state']; $p = max(0, min(100, $t['progress'] ?? 0)); @endphp
<div class="flex items-center gap-1"><div class="esg-prog"><i class="{{ $st }}" style="width:{{ $p }}%"></i></div>
<span class="badge badge-{{ ['achieved' => 'green', 'on_track' => 'blue', 'behind' => 'amber', 'no_data' => 'slate'][$st] }}">{{ ['achieved' => 'achieved', 'on_track' => 'on track', 'behind' => 'behind', 'no_data' => 'no data'][$st] }}{{ $t['progress'] !== null && $st !== 'no_data' ? ' · '.$t['progress'].'%' : '' }}</span></div>
