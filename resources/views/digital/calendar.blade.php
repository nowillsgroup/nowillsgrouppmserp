@extends('layouts.app')
@section('title', 'Content Calendar')
@section('crumbs') Digital Marketing / Content calendar @endsection
@php $P = \App\Models\DmPost::class; $canWrite = can_do('digital.content') || can_do('digital.manage'); @endphp
@section('actions')
  @if($canWrite)<button class="btn btn-primary" data-modal="postModalNew" id="newPostBtn"><x-icon name="plus"/> New post</button>@endif
@endsection
@section('content')
@if($queue->count() && (can_do('digital.approve') || can_do('digital.manage')))
<div class="card flush mb-1" id="approvalQueue"><div class="card-head"><h3><x-icon name="check"/> Awaiting approval ({{ $queue->count() }})</h3></div>
<div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Post</th><th>Slot</th><th>Author</th><th></th></tr></thead><tbody>
@foreach($queue as $p)<tr data-post="{{ $p->id }}"><td><a href="{{ route('digital.post', $p) }}"><b>{{ $p->title }}</b></a><div class="tiny muted">{{ \Illuminate\Support\Str::limit($p->caption, 90) }}</div></td><td class="small nowrap">{{ $p->scheduled_at->format('D d M H:i') }}</td><td class="small">{{ $p->author?->name }}</td>
  <td class="nowrap">@if($p->author_id !== auth()->id())<a class="btn btn-sm btn-primary" href="{{ route('digital.post', $p) }}">Review</a>@else<span class="tiny muted">your post – another approver reviews it</span>@endif</td></tr>@endforeach
</tbody></table></div></div>@endif

<div class="card flush" id="calCard"><div class="card-head"><h3><x-icon name="calendar"/> {{ $month->format('F Y') }}</h3>
  <div class="flex gap-05"><form method="get" class="flex gap-05"><input type="hidden" name="month" value="{{ $month->format('Y-m') }}"><select name="status" aria-label="Status" onchange="this.form.submit()"><option value="">All statuses</option>@foreach($P::STATUSES as $k => [$l])<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select></form>
  <a class="btn btn-sm" href="?month={{ $month->copy()->subMonthNoOverflow()->format('Y-m') }}&status={{ $status }}" id="calPrev">←</a><a class="btn btn-sm" href="?month={{ bdate()->format('Y-m') }}">Today</a><a class="btn btn-sm" href="?month={{ $month->copy()->addMonthNoOverflow()->format('Y-m') }}&status={{ $status }}" id="calNext">→</a></div></div>
<div class="card-body pad"><div class="mk-cal" id="dmCal">@foreach(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<div class="mk-head">{{ $d }}</div>@endforeach
  @foreach($days as $d)<div class="mk-day {{ $d['in'] ? '' : 'out' }} {{ $d['date']->isSameDay(bdate()) ? 'today' : '' }}" data-date="{{ $d['date']->toDateString() }}"><div class="tiny muted">{{ $d['date']->day }}</div>
    @foreach($d['posts'] as $p)<a class="mk-ev ps-{{ $p->isMissed() ? 'missed' : $p->status }}" href="{{ route('digital.post', $p) }}" title="{{ $p->scheduled_at->format('H:i') }} {{ $p->title }} ({{ $p->isMissed() ? 'missed' : $p->statusLabel() }})">{{ $p->scheduled_at->format('H:i') }} {{ $p->title }}</a>@endforeach</div>@endforeach
</div>
<div class="flex gap-1 wrap mt-1 tiny">@foreach(['draft', 'review', 'approved', 'rejected', 'published'] as $s)<span><span class="mk-ev ps-{{ $s }}" style="display:inline-block">{{ $P::STATUSES[$s][0] }}</span></span>@endforeach<span><span class="mk-ev ps-missed" style="display:inline-block">Missed slot</span></span></div>
</div></div>

<div class="card flush mt-1" id="postList"><div class="card-head"><h3><x-icon name="layers"/> Posts in {{ $month->format('F') }}</h3></div>
<div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>Slot</th><th>Post</th><th>Channels</th><th>Status</th><th>Author</th><th class="num">Reach</th><th class="num">Eng.</th></tr></thead><tbody>
@forelse($list as $p)<tr data-post="{{ $p->id }}"><td class="small nowrap">{{ $p->scheduled_at->format('D d M H:i') }}</td><td><a href="{{ route('digital.post', $p) }}">{{ $p->title }}</a>@if($p->pillar)<div class="tiny muted">{{ $P::PILLARS[$p->pillar] ?? '' }}</div>@endif</td>
  <td>@foreach((array) $p->channels as $cid)@if($c = $channelsById[$cid] ?? null)<span class="dm-dot" style="background:{{ $c->colour() }}" title="{{ $c->name }}"></span>@endif @endforeach</td>
  <td><span class="badge badge-{{ $p->isMissed() ? 'red' : $p->statusTone() }}">{{ $p->isMissed() ? 'Missed slot' : $p->statusLabel() }}</span></td><td class="small">{{ $p->author?->name }}</td>
  <td class="num">{{ $p->status === 'published' ? number_format($p->reach) : '' }}</td><td class="num">{{ $p->status === 'published' ? number_format($p->engagements) : '' }}</td></tr>
@empty<tr><td colspan="7" class="empty">No posts this month.</td></tr>@endforelse</tbody></table></div></div>
@if($canWrite)@include('digital._post_modal', ['p' => null])@endif
@endsection
@push('scripts')<script>if (location.hash === '#new' && document.getElementById('postModalNew')) { document.getElementById('newPostBtn').click(); }</script>@endpush
