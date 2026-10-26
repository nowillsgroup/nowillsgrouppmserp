@extends('layouts.app')
@section('title', 'Welcome Screens')
@section('crumbs') IPTV &amp; Guest Welcome / Welcome Screens @endsection
@push('head')
<style>
.tv-prev-luxury{aspect-ratio:16/9;display:grid;grid-template-columns:minmax(0,1.35fr) minmax(8rem,.65fr);grid-template-rows:auto 1fr auto;gap:0 4%;padding:5% 6%;border:1px solid rgba(231,210,168,.35);border-radius:14px;background-color:#142321;background-image:linear-gradient(90deg,rgba(5,14,16,.76),rgba(6,15,17,.46) 61%,rgba(4,12,13,.12)),linear-gradient(0deg,rgba(4,10,11,.5),transparent 44%),var(--tv-preview-photo);background-position:center;background-size:cover;color:#fff}
.tv-prev-luxury-brand{grid-column:1/-1;display:flex;align-items:center;gap:.6rem;min-width:0}
.tv-prev-luxury-mark{display:grid;place-items:center;width:1.8rem;height:1.8rem;border:1px solid #ead4a0;border-radius:50%;color:#ead4a0;font-family:Georgia,serif}
.tv-prev-luxury-brand-copy{display:flex;flex-direction:column;gap:.1rem;min-width:0}
.tv-prev-luxury-brand-copy small,.tv-prev-luxury-kicker,.tv-prev-luxury-stay>small{color:#ead4a0;font-size:.58rem;font-weight:800;letter-spacing:.16em;text-transform:uppercase}
.tv-prev-luxury-brand-copy b{overflow:hidden;color:#fff;font-size:.78rem;letter-spacing:.03em;text-overflow:ellipsis;white-space:nowrap}
.tv-prev-luxury-copy{grid-column:1;grid-row:2;align-self:center;max-width:100%;padding:.4rem 0}
.tv-prev-luxury-copy h2{max-width:100%;margin:.45rem 0;font-family:Georgia,"Times New Roman",serif;font-size:1.55rem;font-weight:400;line-height:1.12;text-shadow:0 2px 12px rgba(0,0,0,.5)}
.tv-prev-luxury-copy p{max-width:100%;margin:0;color:rgba(255,255,255,.87);font-size:.67rem;line-height:1.4}
.tv-prev-luxury-rule{display:block;width:1.5rem;height:2px;margin:.4rem 0;background:#ead4a0}
.tv-prev-luxury-cta{display:inline-flex;gap:.55rem;align-items:center;margin-top:.55rem;padding:.3rem .75rem;border:1px solid #ead4a0;border-radius:999px;background:#ead4a0;color:#15201e;font-size:.64rem;font-weight:800}
.tv-prev-luxury-stay{grid-column:2;grid-row:2;align-self:end;padding:.55rem .7rem;border:1px solid rgba(239,220,180,.35);border-radius:10px;background:rgba(12,25,24,.72)}
.tv-prev-luxury-stay>small{display:block;margin-bottom:.35rem}
.tv-prev-luxury-stay div{display:flex;flex-direction:column;gap:.1rem;margin-top:.25rem}
.tv-prev-luxury-stay div small{color:rgba(255,255,255,.62);font-size:.52rem;letter-spacing:.1em;text-transform:uppercase}
.tv-prev-luxury-stay div b{color:#fff;font-size:.68rem;font-weight:700}
.tv-prev-luxury-footer{grid-column:1/-1;grid-row:3;display:flex;align-items:center;justify-content:space-between;margin-top:.4rem;color:rgba(255,255,255,.72);font-size:.52rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase}
.tv-prev-luxury-footer span:last-child{color:#ead4a0}
@media(max-width:800px){.tv-prev-luxury{grid-template-columns:minmax(0,1fr) minmax(7rem,.6fr);gap:0 2%}.tv-prev-luxury-copy h2{font-size:1.3rem}}
</style>
@endpush
@php $u = auth()->user(); $canMg = \App\Services\IptvService::canManage($u); $T = \App\Models\TvWelcomeTemplate::class; $L = \App\Support\TvLang::LANGS; @endphp
@section('actions')
  @if($canMg)<button class="btn btn-primary" data-modal="tvTplModal" id="tvTplNew"><x-icon name="plus"/> New welcome screen</button>@endif
@endsection
@section('content')
<div class="g-2">
  <div>
    <div class="card flush"><div class="card-head"><h3>Welcome screens</h3><span class="tiny muted">guest’s language first, then the highest priority</span></div><div class="card-body table-wrap"><table class="table table-compact" id="tvTplList"><thead><tr><th>Name</th><th>Shown to</th><th>Lang</th><th class="num">Prio</th><th class="num">Shown</th><th></th></tr></thead><tbody>
    @forelse($list as $t)<tr class="{{ $t->isCurrent() ? '' : 'muted' }}"><td><span class="tv-sw tv-th-{{ $t->theme }}"></span> <b>{{ $t->name }}</b><div class="tiny muted">{{ \Illuminate\Support\Str::limit($t->headline, 50) }}{{ $t->valid_from || $t->valid_to ? ' · '.($t->valid_from?->format('d M') ?? '…').'–'.($t->valid_to?->format('d M Y') ?? '…') : '' }}{{ $t->is_active ? '' : ' · inactive' }}</div></td>
      <td class="small">{{ $T::SEGMENTS[$t->segment] ?? $t->segment }}</td><td class="small">{{ strtoupper($t->language) }}</td><td class="num small">{{ $t->priority }}</td><td class="num small">{{ $shown[$t->id] ?? 0 }}</td>
      <td class="nowrap"><a class="btn btn-sm" href="{{ route('iptv.welcome', ['template' => $t->id, 'stay' => $preview['stay']->id ?? null, 'lang' => $t->language]) }}">Preview</a>@if($canMg) <button class="btn btn-sm" data-modal="tvTpl{{ $t->id }}">Edit</button>@endif</td></tr>
    @empty<tr><td colspan="6" class="muted">No welcome screens – guests see a plain “Welcome” with their name.</td></tr>@endforelse
    </tbody></table></div></div>
    <div class="card"><div class="card-head"><h3>How the welcome is chosen</h3></div><div class="card-body small">
      <p class="m-0">When a guest is checked in, the room TV greets them with an active screen whose segment fits the guest (birthday today, VIP, returning, company or group booking, long stay 7+ nights, or every guest). Screens in the guest’s language are tried first – from their profile, else their nationality – then the hotel default and English; among those the highest priority wins. The guest can switch language on the TV; it is remembered on their profile for next time. The welcome shows once per stay and again after “Re-show welcome” on the reservation.</p>
    </div></div>
  </div>
  <div>
    <div class="card"><div class="card-head"><h3>Preview</h3></div><div class="card-body">
      <form method="get" class="filters" style="align-items:flex-end" id="tvPreviewForm">
        <div class="field"><label>In-house guest</label><select name="stay" @disabled($stays->isEmpty())>@forelse($stays as $s)@php $g = $s->guest ?? $s->reservation?->guest; @endphp<option value="{{ $s->id }}" @selected(($preview['stay']->id ?? null) === $s->id)>{{ $s->room?->room_no }} · {{ $g ? trim($g->first_name.' '.$g->last_name) : '—' }}</option>@empty<option value="">No checked-in guests</option>@endforelse</select></div>
        <div class="field"><label>Language</label><select name="lang">@foreach($L as $k => $l)<option value="{{ $k }}" @selected(($preview['lang'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="field"><label>Screen</label><select name="template"><option value="">Automatic</option>@foreach($list as $t)<option value="{{ $t->id }}" @selected(($preview['template']->id ?? null) === $t->id)>{{ $t->name }}</option>@endforeach</select></div>
        <button class="btn btn-sm">Preview</button></form>
      @php
        $pvStay = $preview['stay'] ?? null;
        $pvTheme = $preview['theme'] ?? 'gold';
        $pvHeadline = $preview['headline'] ?? 'Akwaaba, Guest!';
        $pvBody = $preview['body'] ?? ('We are delighted to welcome you to '.hotel()->name.'. Relax, unwind, and make yourself at home.');
        $welcomeImageUrl = parse_url(asset($welcomeImage), PHP_URL_PATH) ?: '/'.ltrim($welcomeImage, '/');
      @endphp
      <div class="tv-prev-luxury tv-th-{{ $pvTheme }}" id="tvPreview" style="--tv-preview-photo:url('{{ $welcomeImageUrl }}')">
        <div class="tv-prev-luxury-brand"><span class="tv-prev-luxury-mark">✦</span><span class="tv-prev-luxury-brand-copy"><small>WELCOME TO</small><b>{{ hotel()->name }}</b></span></div>
        <div class="tv-prev-luxury-copy"><span class="tv-prev-luxury-kicker">A WARM WELCOME</span><span class="tv-prev-luxury-rule"></span><h2>{{ $pvHeadline }}</h2><p>{!! nl2br(e($pvBody)) !!}</p><span class="tv-prev-luxury-cta">Continue <b>›</b></span></div>
        <div class="tv-prev-luxury-stay"><small>YOUR STAY</small><div><small>ROOM</small><b>{{ $pvStay?->room?->room_no ?? '—' }}</b></div><div><small>WE’RE DELIGHTED YOU’RE HERE</small><b>Make yourself at home</b></div></div>
        <div class="tv-prev-luxury-footer"><span>{{ hotel()->name }} · Guest welcome</span><span>✦ &nbsp; YOUR STAY, REIMAGINED</span></div>
      </div>
      @if($preview)
        <p class="tiny muted">Guest segments: {{ collect($preview['segments'])->map(fn ($s) => $T::SEGMENTS[$s] ?? $s)->implode(', ') }} · automatic choice: <b>{{ $preview['auto']?->name ?? 'plain welcome' }}</b></p>
      @else
        <p class="tiny muted">Design preview using the hotel photo. Check in a guest to preview their personalized welcome and room details.</p>
      @endif
    </div></div>
  </div>
</div>
@if($canMg)
<div class="modal" id="tvTplModal"><div class="modal-box lg"><form method="post" action="{{ route('iptv.welcome.store') }}" data-reset>@csrf
  <div class="modal-head"><h3>New welcome screen</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">@include('iptv._template_fields', ['x' => null])</div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
@foreach($list as $t)
<div class="modal" id="tvTpl{{ $t->id }}"><div class="modal-box lg"><form method="post" action="{{ route('iptv.welcome.update', $t) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>{{ $t->name }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body"><div class="form-grid">@include('iptv._template_fields', ['x' => $t])</div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div></form></div></div>
@endforeach
@endif
@endsection
