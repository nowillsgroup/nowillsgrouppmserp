@php
  $opsCounts = $counts ?? ($snap ?? []);
  $opsShift = \App\Services\FrontDeskOpsService::currentShift();
  $opsShiftLabel = \App\Models\LogEntry::SHIFTS[$opsShift] ?? ucfirst($opsShift);
  $shiftAttention = isset($pendingHandovers) ? $pendingHandovers->count() : (int) ($opsCounts['shift_handover_pending'] ?? (can_do('logbook.view') ? \App\Services\FrontDeskShiftSessionService::pendingCount() : 0));
  $opsLinks = [
    ['label' => 'My Shift', 'route' => 'desk.shift', 'active' => request()->routeIs('desk.shift*'), 'perm' => 'logbook.view', 'badge' => $shiftAttention, 'icon' => 'clock'],
    ['label' => 'Shift log book', 'route' => 'desk.logbook', 'active' => request()->routeIs('desk.logbook*'), 'perm' => 'logbook.view', 'badge' => (int) ($opsCounts['log_unread'] ?? 0), 'icon' => 'book'],
    ['label' => 'Shift hand-over', 'route' => 'desk.handover', 'active' => request()->routeIs('desk.handover*'), 'perm' => 'logbook.view', 'badge' => 0, 'icon' => 'swap'],
    ['label' => 'Wake-up calls', 'route' => 'desk.wakeups', 'active' => request()->routeIs('desk.wakeups*'), 'perm' => 'desk.wakeup', 'badge' => (int) ($opsCounts['wakeups_due'] ?? 0), 'icon' => 'bell'],
    ['label' => 'Messages & parcels', 'route' => 'desk.messages', 'active' => request()->routeIs('desk.messages*'), 'perm' => 'desk.messages', 'badge' => (int) ($opsCounts['messages'] ?? 0) + (int) ($opsCounts['parcels'] ?? 0), 'icon' => 'mail'],
    ['label' => 'Traces', 'route' => 'desk.traces', 'active' => request()->routeIs('desk.traces*'), 'perm' => 'desk.traces', 'badge' => (int) ($opsCounts['traces_today'] ?? (isset($open) ? $open->count() : 0)), 'icon' => 'check'],
    ['label' => 'Lost & found', 'route' => 'lostfound.index', 'active' => request()->routeIs('lostfound.*'), 'perm' => 'lostfound.manage', 'badge' => (int) ($opsCounts['lf_due'] ?? 0) ?: (int) ($opsCounts['lf_reports'] ?? 0), 'icon' => 'search'],
    ['label' => 'Desk settings', 'route' => 'desk.settings', 'active' => request()->routeIs('desk.settings*'), 'perm' => 'logbook.manage', 'badge' => 0, 'icon' => 'tool'],
  ];
@endphp
<style>
  .desk-ops-switcher{margin:0 0 1rem;padding:.95rem 1rem .8rem;background:linear-gradient(125deg,#f0fdf4 0%,#f8fbf8 72%,#fff 100%);border:1px solid #d9eadc;border-radius:14px;box-shadow:0 2px 8px rgba(20,83,45,.045)}
  .desk-ops-switcher-head{display:flex;align-items:center;justify-content:space-between;gap:.8rem;margin-bottom:.72rem}
  .desk-ops-title{display:flex;align-items:center;gap:.65rem;min-width:0}
  .desk-ops-mark{display:grid;place-items:center;width:34px;height:34px;flex:0 0 34px;border-radius:10px;background:#14532d;color:#fff;font-size:.77rem;font-weight:850;letter-spacing:-.04em}
  .desk-ops-title-copy{min-width:0}.desk-ops-eyebrow{display:block;color:#53715d;font-size:.62rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase;line-height:1.2}
  .desk-ops-title-copy strong{display:block;color:#173b26;font-size:.93rem;line-height:1.3}.desk-ops-meta{display:flex;align-items:center;gap:.4rem;flex:0 0 auto;color:#496450;font-size:.72rem;font-weight:650}
  .desk-ops-meta span{padding:.28rem .55rem;border:1px solid #d6e5d8;border-radius:999px;background:rgba(255,255,255,.75);white-space:nowrap}
  .desk-ops-nav{display:flex;gap:.35rem;overflow-x:auto;padding:.1rem .05rem .2rem;scrollbar-width:thin;scrollbar-color:#c5d8c8 transparent}
  .desk-ops-link{display:inline-flex;align-items:center;gap:.42rem;min-height:36px;padding:.42rem .68rem;border:1px solid transparent;border-radius:9px;color:#3e5746;font-size:.76rem;font-weight:700;line-height:1.2;text-decoration:none;white-space:nowrap;transition:background .15s,border-color .15s,color .15s;flex:0 0 auto}
  .desk-ops-link:hover{background:#fff;border-color:#d7e7d9;color:#14532d;text-decoration:none}
  .desk-ops-link:focus-visible{outline:3px solid #86efac;outline-offset:2px}
  .desk-ops-link[aria-current=page]{background:#14532d;border-color:#14532d;color:#fff;box-shadow:0 2px 5px rgba(20,83,45,.16)}
  .desk-ops-link-icon{display:grid;place-items:center;width:20px;height:20px;border-radius:6px;background:#e3f1e5;color:#286340;flex:0 0 20px}
  .desk-ops-link[aria-current=page] .desk-ops-link-icon{background:rgba(255,255,255,.16);color:#fff}
  .desk-ops-link-icon svg{width:13px;height:13px}
  .desk-ops-badge{display:inline-flex;align-items:center;justify-content:center;min-width:19px;height:19px;padding:0 .28rem;border-radius:999px;background:#fff1cf;color:#8a4b00;font-size:.64rem;font-weight:850;line-height:1}
  .desk-ops-link[aria-current=page] .desk-ops-badge{background:#fff;color:#14532d}
  #deskLogbook .log-row[data-entry]{align-items:flex-start;transition:background-color .15s ease}
  #deskLogbook .log-row[data-entry]:hover{filter:brightness(.985)}
  #deskLogbook .log-row[data-entry]>div:first-child{padding-right:.85rem;border-right:1px solid var(--c-border);line-height:1.45}
  #deskLogbook .log-row[data-entry]>div:nth-child(2){padding-top:.02rem}
  #deskLogbook .log-row[data-entry] a:not(.btn){text-underline-offset:3px}
  #deskLogbook .log-row[data-entry] a:not(.btn):hover{text-decoration:underline}
  #deskLogbook .filters .input{min-height:36px;border-radius:8px}
  #deskLogbook .tabs a{white-space:nowrap}
  body.dark #deskLogbook .log-row[data-entry]:hover{filter:brightness(1.12)}
  body.dark #deskLogbook .log-row[data-entry]>div:first-child{border-color:var(--c-border)}
  @media(max-width:560px){#deskLogbook .log-row[data-entry]{gap:.55rem!important;padding:.75rem!important}#deskLogbook .log-row[data-entry]>div:first-child{min-width:4.4rem!important;padding-right:.55rem;font-size:.67rem}}
  body.dark .desk-ops-switcher{background:linear-gradient(125deg,#162b1c,#15231a 75%);border-color:#294332}
  body.dark .desk-ops-title-copy strong{color:#e4f0e6}body.dark .desk-ops-eyebrow,body.dark .desk-ops-meta{color:#a9c4ae}
  body.dark .desk-ops-meta span{background:#1c3022;border-color:#34513a;color:#c5d6c8}
  body.dark .desk-ops-link{color:#c5d6c8}body.dark .desk-ops-link:hover{background:#1c3022;border-color:#34513a;color:#d8f3dd}
  body.dark .desk-ops-link[aria-current=page]{background:#246038;border-color:#36794d;color:#fff}
  body.dark .desk-ops-link-icon{background:#24412c;color:#b8e0c0}
  body.dark .desk-ops-badge{background:#573c19;color:#ffe0a3}body.dark .desk-ops-link[aria-current=page] .desk-ops-badge{background:#e7f7e9;color:#14532d}
  @media(max-width:640px){.desk-ops-switcher{padding:.75rem .7rem}.desk-ops-switcher-head{align-items:flex-start}.desk-ops-meta{flex-direction:column;align-items:flex-end;gap:.2rem}.desk-ops-meta span{font-size:.64rem;padding:.22rem .42rem}.desk-ops-nav{margin:0 -.1rem}.desk-ops-link{min-height:34px;padding:.38rem .55rem;font-size:.72rem}}
</style>
<section class="desk-ops-switcher" aria-label="Desk Operations workspace">
  <div class="desk-ops-switcher-head">
    <div class="desk-ops-title">
      <span class="desk-ops-mark" aria-hidden="true">DO</span>
      <span class="desk-ops-title-copy"><span class="desk-ops-eyebrow">Front desk workspace</span><strong>Desk Operations</strong></span>
    </div>
    <div class="desk-ops-meta"><span>{{ $opsShiftLabel }} shift</span><span>{{ bdate()->format('D, d M') }}</span></div>
  </div>
  <nav class="desk-ops-nav" aria-label="Desk Operations pages">
    @foreach($opsLinks as $link)
      @if(can_do($link['perm']))
        <a class="desk-ops-link" href="{{ route($link['route']) }}" @if($link['active']) aria-current="page" @endif>
          <span class="desk-ops-link-icon" aria-hidden="true"><x-icon :name="$link['icon']"/></span>
          <span>{{ $link['label'] }}</span>
          @if($link['badge'] > 0)<span class="desk-ops-badge" aria-label="{{ $link['badge'] }} items need attention">{{ $link['badge'] > 99 ? '99+' : $link['badge'] }}</span>@endif
        </a>
      @endif
    @endforeach
  </nav>
</section>
