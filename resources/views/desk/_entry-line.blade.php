@php $unread = ! $e->readBy(auth()->id()); @endphp
<tr data-entry="{{ $e->id }}" class="{{ $unread ? 'unread' : '' }}" style="{{ $unread ? 'background:#fffbeb' : '' }}">
  <td class="small nowrap">{{ $e->created_at->format('d M H:i') }}</td>
  <td><span class="badge badge-{{ $e->categoryTone() }}">{{ $e->categoryLabel() }}</span>@if($e->priority !== 'normal') <span class="badge badge-{{ $e->priority === 'urgent' ? 'red' : 'amber' }}">{{ \App\Models\LogEntry::PRIORITIES[$e->priority] }}</span>@endif</td>
  <td><a href="{{ route('desk.logbook.show', $e) }}"><b>{{ $e->title }}</b></a>@if($unread) <span class="badge badge-amber">New</span>@endif
    @if($e->body)<div class="small muted">{{ \Illuminate\Support\Str::limit($e->body, 160) }}</div>@endif</td>
  <td class="small">{{ $e->stay?->room?->room_no ?? $e->room?->room_no ?? '' }}</td>
  <td class="small">{{ $e->source === 'system' ? 'System' : ($e->author?->name ?? '—') }}</td>
  <td class="small">@if($e->status === 'open')<span class="{{ $e->followUpDue() ? 'text-red' : 'text-blue' }}">Open{{ $e->follow_up_at ? ' · '.$e->follow_up_at->format('d M H:i') : '' }}</span>@elseif($e->status === 'resolved')<span class="text-green">Resolved</span>@else<span class="muted">Note</span>@endif</td>
</tr>
