@extends('portal.layout')
@section('title', 'Requests')
@section('heading', 'How can we help?')
@section('sub', $state === 'inhouse' ? 'Requests go straight to our team – you can follow them here.' : ($state === 'upcoming' ? 'Send a message to the front desk before you arrive.' : 'Your requests during the stay.'))
@section('content')
<style>
.gp-cat{background:#fff;border:1px solid var(--c-border);border-radius:14px;margin-bottom:.55rem;overflow:hidden}
.gp-cat summary{list-style:none;display:flex;align-items:center;gap:.7rem;padding:.8rem 1rem;cursor:pointer;font-weight:600}
.gp-cat summary::-webkit-details-marker{display:none}
.gp-cat summary .ic{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:#eef2ff;color:#1d4ed8}
.gp-cat summary .chev{margin-left:auto;transition:transform .2s;color:#94a3b8}.gp-cat[open] summary .chev{transform:rotate(90deg)}
.gp-cat .body{padding:0 1rem 1rem}
</style>

@php $cats = $state === 'inhouse' ? $cats : $cats->only('front_desk'); $open = old('category', request('c')); @endphp
@if($state !== 'departed')
@foreach($cats as $key => $c)
<details class="gp-cat" id="cat-{{ $key }}" @if($open === $key) open @endif>
  <summary><span class="ic"><x-icon :name="$c['icon']"/></span>{{ $c['label'] }}<span class="chev"><x-icon name="chev" size="sm"/></span></summary>
  <form method="post" action="{{ route('portal.requests.store', $token) }}" class="body">@csrf
    <input type="hidden" name="category" value="{{ $key }}"><input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">
    @if(! in_array($key, ['front_desk', 'wakeup', 'late_checkout']))
      <div class="gp-chips mb-1">@foreach($c['items'] as $it)<label class="gp-chip"><input type="radio" name="item" value="{{ $it }}" required @checked(old('category') === $key && old('item') === $it)>{{ $it }}</label>@endforeach</div>
    @endif
    @if($key === 'laundry' && isset($laundry))
      <p class="small muted m-0 mb-1">Back in {{ $laundry['hours']['normal'] }} h, or {{ $laundry['hours']['express'] }} h with express (+{{ rtrim(rtrim(number_format($laundry['express'], 2), '0'), '.') }} %). Charged to your room on delivery, plus tax.</p>
      <details class="mb-1" id="laundryPrices"><summary class="small" style="cursor:pointer;color:#1d4ed8">See the laundry price list</summary>
        <table class="gp-table small mt-1"><thead><tr><th style="text-align:left">Item</th><th>Wash</th><th>Dry clean</th><th>Press</th></tr></thead><tbody>
        @foreach($laundry['prices'] as $rows)@foreach($rows as $it)<tr><td>{{ $it->name }}</td>@foreach(['wash', 'dryclean', 'press'] as $sk)<td style="text-align:right">{{ $it->rate($sk) === null ? '–' : money($it->rate($sk), false) }}</td>@endforeach</tr>@endforeach @endforeach
        </tbody></table></details>
    @endif
    @if($key === 'amenity')
      <div class="field"><label>Quantity</label><select name="quantity" class="input">@for($q = 1; $q <= 5; $q++)<option>{{ $q }}</option>@endfor</select></div>
    @endif
    @if(in_array($key, ['wakeup', 'transport']))
      <div class="field"><label>{{ $key === 'wakeup' ? 'Wake me at' : 'Pick-up time' }}</label>
        <input type="datetime-local" name="preferred_at" class="input" required min="{{ now()->format('Y-m-d\TH:i') }}" value="{{ old('category') === $key ? old('preferred_at') : now()->addDay()->setTime(7, 0)->format('Y-m-d\TH:i') }}"></div>
    @endif
    @if($key === 'late_checkout')
      <p class="small muted m-0 mb-1">Standard check-out is {{ substr($hotel->checkout_time, 0, 5) }} on {{ $stay->departure_date->format('D d M') }}. Late check-out is subject to availability and may be charged.</p>
      <div class="field"><label>I'd like to leave at</label><select name="time" class="input">@foreach(['12:00','13:00','14:00','15:00','16:00','18:00'] as $t)@if($t > substr($hotel->checkout_time, 0, 5))<option>{{ $t }}</option>@endif @endforeach</select></div>
    @endif
    <div class="field"><label>{{ in_array($key, ['front_desk']) ? 'Your message' : 'Notes (optional)' }}</label>
      <textarea name="notes" rows="2" maxlength="500" class="input" @if($key === 'front_desk') required @endif placeholder="{{ $key === 'maintenance' ? 'Describe the problem…' : '' }}">{{ old('category') === $key ? old('notes') : '' }}</textarea></div>
    <button class="btn btn-primary btn-block">Send request</button>
  </form>
</details>
@endforeach
@endif

@if(isset($laundry) && $laundry['orders']->count())
<div class="gp-card mt-1" id="myLaundry"><h3>My laundry</h3>
@foreach($laundry['orders'] as $lo)
  <div class="gp-row"><div><b>{{ $lo->order_no }}</b>{{ $lo->speed === 'express' ? ' · express' : '' }}<div class="tiny muted">{{ $lo->pieces ? $lo->pieces.' pcs' : 'Being counted' }}{{ (float) $lo->total ? ' · '.money($lo->total).' + tax' : '' }}</div>
    <div class="tiny muted">{{ $lo->status === 'delivered' ? 'Delivered '.$lo->delivered_at?->format('d M H:i') : 'Expected back '.$lo->promised_at?->format('D d M, H:i') }}</div></div>
    <span class="badge badge-{{ \App\Models\LaundryOrder::TONES[$lo->status] }}">{{ $lo->statusLabel() }}</span></div>
@endforeach
</div>
@endif
<div class="gp-card mt-1"><h3>My requests</h3>
@forelse($list as $r)
  <div class="gp-row">
    <div style="flex:1;min-width:0"><b class="small">{{ $r->quantity > 1 ? $r->quantity.' × ' : '' }}{{ $r->item }}</b>
      <div class="tiny muted">{{ $r->request_no }} · {{ $r->created_at->format('d M H:i') }}@if($r->preferred_at) · for {{ $r->preferred_at->format('D d M H:i') }}@endif</div>
      @if($r->notes)<div class="tiny">“{{ \Illuminate\Support\Str::limit($r->notes, 120) }}”</div>@endif
      @if($r->staff_note)<div class="tiny" style="color:#1e40af">Hotel: {{ $r->staff_note }}</div>@endif
      @if($r->status === 'done' && ! $r->rating && $state !== 'upcoming')
        <form method="post" action="{{ route('portal.requests.rate', [$token, $r->id]) }}" class="flex items-center gap-1 mt-1">@csrf
          <span class="tiny muted">Rate:</span><span class="stars">@for($s = 5; $s >= 1; $s--)<input type="radio" id="r{{ $r->id }}s{{ $s }}" name="rating" value="{{ $s }}" onchange="this.form.submit()"><label for="r{{ $r->id }}s{{ $s }}">★</label>@endfor</span></form>
      @elseif($r->rating)<div class="tiny" style="color:#f59e0b">{{ str_repeat('★', $r->rating) }}</div>@endif
    </div>
    <div class="right"><span class="st {{ $r->status }}">{{ \App\Models\GuestRequest::STATUSES[$r->status] }}</span>
      @if($r->status === 'new')<form method="post" action="{{ route('portal.requests.cancel', [$token, $r->id]) }}" class="mt-1">@csrf<button class="btn btn-ghost btn-sm" style="color:#b91c1c">Cancel</button></form>@endif
    </div>
  </div>
@empty
  <p class="small muted m-0">No requests yet.</p>
@endforelse
</div>
@endsection
