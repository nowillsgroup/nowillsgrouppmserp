{{-- What the pack is telling its reader to do today: the flags, then the actions. --}}
@if(count($pack['watch']) || count($pack['todo']))
  <div class="card brf-band brf-book-attention">
    <div class="card-head"><h3>What needs attention</h3></div>
    <div class="card-body">
      @if(count($pack['watch']))
        <ul class="brf-list">
          @foreach($pack['watch'] as $w)
            <li class="brf-li tone-red"><span class="brf-dot"></span><span class="brf-li-text">{{ $w }}</span></li>
          @endforeach
        </ul>
      @endif
      @if(count($pack['todo']))
        <h4 class="brf-subhead">Actions</h4>
        <ol class="brf-todo">@foreach($pack['todo'] as $t)<li>{{ $t }}</li>@endforeach</ol>
      @endif
    </div>
  </div>
@endif
