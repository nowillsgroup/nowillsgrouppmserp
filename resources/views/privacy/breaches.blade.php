@extends('layouts.app')
@section('title', 'Personal-data breach register')
@section('crumbs') <a href="{{ route('privacy.index') }}">Privacy</a> / Breaches @endsection
@section('actions')@if(can_do('privacy.breach'))<a class="btn btn-primary" href="{{ route('privacy.breaches.create') }}" id="newBreach"><x-icon name="plus"/> Record breach</a>@endif @endsection
@section('content')
@include('privacy._nav')
<p class="small muted">Every personal-data breach is recorded here – including ones that turn out not to need notification. Notifiable breaches must be reported to the Data Protection Commission within {{ $hours }} hours of detection, and the people affected informed.</p>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="breachTable">
  <thead><tr><th>Ref</th><th>Breach</th><th>Detected</th><th>Severity</th><th class="num">Affected</th><th>Authority</th><th>People informed</th><th>Status</th></tr></thead><tbody>
  @forelse($breaches as $b)
  <tr class="{{ $b->notificationPending() && $b->deadline()->isPast() ? 'row-alert' : '' }}"><td><a href="{{ route('privacy.breaches.show', $b) }}" class="bold">{{ $b->ref }}</a></td><td class="small">{{ $b->title }}<div class="tiny muted">{{ collect($b->categoryList())->map(fn ($c) => \App\Models\DataBreach::CATEGORIES[$c] ?? $c)->implode(', ') }}</div></td>
    <td class="small nowrap">{{ $b->detected_at->format('d M Y H:i') }}</td>
    <td><span class="badge badge-{{ ['low' => 'slate', 'medium' => 'amber', 'high' => 'red', 'critical' => 'red'][$b->severity] }}">{{ ucfirst($b->severity) }}</span></td>
    <td class="num">{{ number_format($b->affected_count) }}</td>
    <td class="small">@if(! $b->notifiable)<span class="muted">not notifiable</span>@elseif($b->authority_notified_at){{ $b->authority_notified_at->format('d M H:i') }}@if($b->authority_notified_at->gt($b->deadline())) <span class="badge badge-red">late</span>@endif
      @else @php $hl = $b->hoursLeft(); @endphp<span class="badge {{ $hl < 0 ? 'badge-red' : ($hl < 24 ? 'badge-amber' : 'badge-slate') }}">{{ $hl < 0 ? abs($hl).' h overdue' : $hl.' h left' }}</span>@endif</td>
    <td class="small">{{ $b->subjects_notified_at?->format('d M Y') ?? '—' }}</td>
    <td>{!! status_badge($b->status) !!}</td></tr>
  @empty<tr><td colspan="8"><x-empty text="No breaches recorded." icon="shield"/></td></tr>@endforelse
  </tbody></table></div>@if($breaches->hasPages())<div class="card-foot">{{ $breaches->links() }}</div>@endif</div>
@endsection
