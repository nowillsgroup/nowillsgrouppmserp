@extends('portal.layout')
@section('title', 'Chat with us')
@section('heading', 'Chat with us')
@section('sub', $inHours ? 'Our guest services team usually replies within a few minutes.' : 'We reply between '.$cfg['from'].' and '.$cfg['to'].' – for anything urgent dial 0 from your room phone.')
@section('content')
<div class="gp-card gp-chat" id="gpChat" data-poll="{{ route('portal.chat.poll', $token) }}" data-send="{{ route('portal.chat.send', $token) }}" data-last="{{ $list->max('id') ?? 0 }}">
  <div class="gp-thread" id="gpThread" aria-live="polite">
    @forelse($list as $m)
      <div class="gp-bubble {{ $m->direction === 'in' ? 'me' : 'them' }}" data-id="{{ $m->id }}">
        <div class="gp-b-body">{{ $m->body }}</div>
        <div class="gp-b-meta">{{ $m->direction === 'in' ? 'You' : ($m->auto ? $hotel->name : ($m->user ? \Illuminate\Support\Str::before($m->user->name, ' ').' · '.$hotel->name : $hotel->name)) }} · {{ $m->created_at->format($m->created_at->isToday() ? 'H:i' : 'D H:i') }}</div>
      </div>
    @empty
      <p class="muted small center m-0" id="gpChatEmpty">Ask us anything – extra towels, a restaurant booking, directions, a late check-out… A real person at the front desk will answer.</p>
    @endforelse
  </div>
  @if($conv && $conv->status === 'closed' && $conv->csat_asked && $conv->csat === null)
    <form method="post" action="{{ route('portal.chat.rate', $token) }}" class="gp-rate" id="gpChatRate">@csrf
      <div class="small bold">How did we do?</div>
      <div class="stars">@for($i = 5; $i >= 1; $i--)<input type="radio" name="stars" id="cs{{ $i }}" value="{{ $i }}" onchange="this.form.submit()"><label for="cs{{ $i }}" title="{{ $i }} / 5">★</label>@endfor</div>
    </form>
  @endif
  @if($canWrite)
  <form method="post" action="{{ route('portal.chat.send', $token) }}" class="gp-compose" id="gpChatForm">@csrf
    <textarea name="body" id="gpChatBody" rows="1" maxlength="1000" placeholder="Type a message…" required class="input">{{ old('body') }}</textarea>
    <button class="btn btn-primary" id="gpChatSend" aria-label="Send"><x-icon name="chev"/></button>
  </form>
  <div class="tiny muted" id="gpChatErr" hidden></div>
  @else
  <p class="tiny muted m-0 mt-1">Your stay has ended – to reach us please e-mail {{ $hotel->email ?: 'the hotel' }}{{ $hotel->phone ? ' or call '.$hotel->phone : '' }}.</p>
  @endif
</div>
<style>
.gp-chat{display:flex;flex-direction:column;gap:.6rem;min-height:55vh}
.gp-thread{flex:1;display:flex;flex-direction:column;gap:.45rem;max-height:62vh;overflow-y:auto;padding:.2rem}
.gp-bubble{max-width:82%;padding:.55rem .75rem;border-radius:14px;font-size:.92rem;line-height:1.35}
.gp-bubble.me{align-self:flex-end;background:#1d4ed8;color:#fff;border-bottom-right-radius:4px}
.gp-bubble.them{align-self:flex-start;background:#f1f5f9;color:#0f172a;border-bottom-left-radius:4px}
.gp-b-body{white-space:pre-line;word-wrap:break-word}
.gp-b-meta{font-size:.68rem;opacity:.75;margin-top:.2rem}
.gp-compose{display:flex;gap:.45rem;align-items:flex-end;border-top:1px solid #e2e8f0;padding-top:.6rem}
.gp-compose textarea{flex:1;resize:none;min-height:42px;max-height:140px;border-radius:12px}
.gp-compose .btn{height:42px;width:46px;padding:0;display:grid;place-items:center;border-radius:12px}
.gp-rate{background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:.6rem .8rem;display:flex;align-items:center;justify-content:space-between;gap:.6rem}
</style>
@endsection
@push('scripts')
<script>
(function () {
  const box = document.getElementById('gpChat'); if (!box) return;
  const thread = document.getElementById('gpThread'), form = document.getElementById('gpChatForm'), ta = document.getElementById('gpChatBody'), err = document.getElementById('gpChatErr');
  let last = +box.dataset.last || 0, busy = false;
  const down = () => { thread.scrollTop = thread.scrollHeight; };
  const add = m => {
    if (thread.querySelector('[data-id="' + m.id + '"]')) return;
    document.getElementById('gpChatEmpty')?.remove();
    const d = document.createElement('div'); d.className = 'gp-bubble ' + (m.mine ? 'me' : 'them'); d.dataset.id = m.id;
    const b = document.createElement('div'); b.className = 'gp-b-body'; b.textContent = m.body;
    const t = document.createElement('div'); t.className = 'gp-b-meta'; t.textContent = m.who + ' · ' + m.at;
    d.append(b, t); thread.append(d); last = Math.max(last, m.id); down();
  };
  const poll = () => fetch(box.dataset.poll + '?after=' + last, {headers: {Accept: 'application/json'}}).then(r => r.ok ? r.json() : null).then(j => {
    if (!j) return; j.messages.forEach(add);
    if (j.rate && !document.getElementById('gpChatRate')) location.reload();
  }).catch(() => {});
  down();
  setInterval(() => { if (!document.hidden) poll(); }, 4000);
  if (form) {
    ta.addEventListener('input', () => { ta.style.height = 'auto'; ta.style.height = Math.min(140, ta.scrollHeight) + 'px'; });
    ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });
    form.addEventListener('submit', e => {
      e.preventDefault(); if (busy || !ta.value.trim()) return; busy = true; err.hidden = true;
      fetch(box.dataset.send, {method: 'POST', headers: {Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name=_token]').value},
        body: JSON.stringify({body: ta.value})}).then(r => r.json().then(j => ({ok: r.ok, j}))).then(({ok, j}) => {
        if (!ok) { err.textContent = j.error || j.message || 'Could not send – please try again.'; err.hidden = false; return; }
        ta.value = ''; ta.style.height = ''; return poll();
      }).catch(() => { err.textContent = 'No connection – please try again.'; err.hidden = false; })
        .finally(() => { busy = false; form.querySelectorAll('button').forEach(b => b.disabled = false); });
    });
  }
})();
</script>
@endpush
