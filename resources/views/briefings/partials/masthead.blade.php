{{-- Who the pack is for, which business date it answers on, and whether it has been signed. --}}
<div class="card brf-masthead">
  <div class="card-body brf-masthead-body">
    <div class="brf-masthead-main">
      <div class="brf-role"><x-icon :name="$pack['icon']"/></div>
      <div>
        <h2>{{ $pack['role'] }}</h2>
        <div class="small muted">{{ $pack['title'] }}</div>
        <div class="brf-masthead-meta">
          <span><x-icon name="calendar"/> Business date <b>{{ $pack['date']->format('D j M Y') }}</b></span>
          @if(!empty($isExec))
            <span><b>{{ $signed }}</b> of <b>{{ $packCount }}</b> departments signed</span>
          @else
            <span>{{ count($pack['headline']) }} headline figures · {{ count($pack['sections']) }} blocks</span>
          @endif
          <span>Figures read from source transactions</span>
        </div>
      </div>
    </div>
    <div class="brf-masthead-side">
      @if(!empty($isExec))
        <span class="badge badge-{{ $signed === $packCount ? 'green' : 'amber' }}">{{ $signed }}/{{ $packCount }} signed</span>
      @elseif($pack['signoff'])
        <span class="badge badge-green"><x-icon name="check"/> Signed {{ $pack['signoff']->signed_at?->format('H:i') }}</span>
        <div class="tiny muted">by {{ $pack['signoff']->user?->name ?? 'a manager' }}</div>
      @else
        <span class="badge badge-amber">Not signed</span>
        <div class="tiny muted">the GM sees this as outstanding</div>
      @endif
    </div>
  </div>
</div>
