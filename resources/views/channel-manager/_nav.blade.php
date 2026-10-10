<div class="tabs" id="cmNav">
  <a href="{{ route('cm.index') }}" class="{{ request()->routeIs('cm.index') ? 'active' : '' }}">Dashboard</a>
  <a href="{{ route('cm.inbox') }}" class="{{ request()->routeIs('cm.inbox', 'cm.message') ? 'active' : '' }}">OTA inbox @if($navAtt = \App\Services\ChannelService::attentionCount())<span class="badge badge-amber">{{ $navAtt }}</span>@endif</a>
  <a href="{{ route('cm.pushes') }}" class="{{ request()->routeIs('cm.pushes') ? 'active' : '' }}">Sync log</a>
  <a href="{{ route('cm.parity') }}" class="{{ request()->routeIs('cm.parity') ? 'active' : '' }}">Rate parity</a>
  @if(can_do('channels.manage'))<a href="{{ route('channels.index') }}">iCal feeds</a>@endif
</div>
