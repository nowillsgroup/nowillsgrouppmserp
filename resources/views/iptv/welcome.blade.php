@extends('layouts.app')
@section('title', 'Welcome Screens')
@section('crumbs') IPTV &amp; Guest Welcome / Welcome Screens @endsection
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
        <div class="field"><label>In-house guest</label><select name="stay">@foreach($stays as $s)@php $g = $s->guest ?? $s->reservation?->guest; @endphp<option value="{{ $s->id }}" @selected(($preview['stay']->id ?? null) === $s->id)>{{ $s->room?->room_no }} · {{ $g ? trim($g->first_name.' '.$g->last_name) : '—' }}</option>@endforeach</select></div>
        <div class="field"><label>Language</label><select name="lang">@foreach($L as $k => $l)<option value="{{ $k }}" @selected(($preview['lang'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="field"><label>Screen</label><select name="template"><option value="">Automatic</option>@foreach($list as $t)<option value="{{ $t->id }}" @selected(($preview['template']->id ?? null) === $t->id)>{{ $t->name }}</option>@endforeach</select></div>
        <button class="btn btn-sm">Preview</button></form>
      @if($preview)
        <div class="tv-prev tv-th-{{ $preview['theme'] }}" id="tvPreview">
          <div class="tv-prev-top"><span>{{ hotel()->name }}</span><span>{{ now()->format('H:i') }}</span></div>
          <h2>{{ $preview['headline'] }}</h2>
          <p>{!! nl2br(e($preview['body'])) !!}</p>
          <div class="tv-prev-foot">Room {{ $preview['stay']->room?->room_no }} · OK to continue</div>
        </div>
        <p class="tiny muted">Guest segments: {{ collect($preview['segments'])->map(fn ($s) => $T::SEGMENTS[$s] ?? $s)->implode(', ') }} · automatic choice: <b>{{ $preview['auto']?->name ?? 'plain welcome' }}</b></p>
      @else<p class="muted small">No guests in house to preview with.</p>@endif
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
