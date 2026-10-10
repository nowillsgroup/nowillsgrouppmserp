@php $st = ['tentative' => ['Awaiting hotel', 'badge-amber'], 'confirmed' => ['Confirmed', 'badge-green'], 'checked_in' => ['In house', 'badge-blue'], 'checked_out' => ['Stayed', 'badge-slate'], 'cancelled' => ['Cancelled', 'badge-red'], 'no_show' => ['No-show', 'badge-red']][$r->status] ?? [ucfirst($r->status), 'badge-slate']; @endphp
<span class="badge {{ $st[1] }}">{{ $st[0] }}</span>
