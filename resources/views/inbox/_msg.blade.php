@php $IS = \App\Services\InboxService::class; @endphp
@if($m->direction === 'system')
  <div class="inb-sys" data-id="{{ $m->id }}"><span>{{ $m->body }}</span> <time>{{ $m->created_at->format($m->created_at->isToday() ? 'H:i' : 'd M H:i') }}</time></div>
@else
  <div class="inb-msg inb-{{ $m->direction }}{{ $m->auto ? ' inb-auto' : '' }}{{ $m->status === 'failed' ? ' inb-failed' : '' }}" data-id="{{ $m->id }}">
    @if($m->direction === 'note')<div class="inb-note-tag"><x-icon name="lock" size="sm"/> Internal note – the guest does not see this</div>@endif
    <div class="inb-body">{{ $m->body }}</div>
    <div class="inb-meta">
      <b>{{ $m->direction === 'in' ? ($c->displayName()) : $m->authorLabel() }}</b> · {{ $m->created_at->format($m->created_at->isToday() ? 'H:i' : 'd M H:i') }}
      @if($m->direction === 'out')
        · <span class="inb-st inb-st-{{ $m->status }}" title="{{ $m->error }}">{{ ['sent' => '✓ sent', 'delivered' => '✓✓ delivered', 'read' => '✓✓ read', 'failed' => '✕ not sent'][$m->status] ?? $m->status }}</span>
        @if($m->auto)<span class="badge badge-slate">auto</span>@endif
        @if($m->broadcast_id)<span class="badge badge-purple">broadcast</span>@endif
        @if($m->response_sec !== null)<span class="tiny muted" title="Time the guest waited for this answer">answered in {{ $IS::fmtSecs($m->response_sec) }}</span>@endif
      @endif
    </div>
    @if($m->status === 'failed' && $m->error)<div class="tiny inb-err">{{ $m->error }}</div>@endif
  </div>
@endif
