@php $P = \App\Models\DmPost::class; $mid = $p ? 'postModal' : 'postModalNew'; $sel = $p?->channels ?? []; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:48rem"><form method="post" action="{{ $p ? route('digital.post.update', $p) : route('digital.posts.store') }}">@csrf @if($p) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $p ? 'Edit post' : 'New social post' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label>Working title</label><input name="title" maxlength="150" required value="{{ $p?->title }}" placeholder="Detty December early-bird teaser"></div>
    <div class="field span-all"><label>Channels</label><div class="flex gap-1 wrap" id="{{ $mid }}Channels">@foreach($socialChannels as $c)<label class="dm-check"><input type="checkbox" name="channels[]" value="{{ $c->id }}" @checked(in_array($c->id, $sel))> <span class="dm-dot" style="background:{{ $c->colour() }}"></span> {{ $c->name }}</label>@endforeach</div></div>
    <div class="field span-all"><label>Caption <span class="tiny muted">(X: 280 characters max · others 2,200)</span></label><textarea name="caption" rows="5" maxlength="2200" required placeholder="Write the caption, hashtags and call to action…">{{ $p?->caption }}</textarea></div>
    <div class="field"><label>Publish at</label><input type="datetime-local" name="scheduled_at" required value="{{ ($p?->scheduled_at ?? now()->addDay()->setTime(18, 0))->format('Y-m-d\TH:i') }}"></div>
    <div class="field"><label>Content pillar</label><select name="pillar"><option value="">—</option>@foreach($P::PILLARS as $k => $l)<option value="{{ $k }}" @selected($p?->pillar === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Campaign</label><select name="campaign_id"><option value="">—</option>@foreach($campaignList as $c)<option value="{{ $c->id }}" @selected($p?->campaign_id === $c->id)>{{ $c->number }} {{ $c->name }}</option>@endforeach</select></div>
    <div class="field"><label>Tracked link</label><select name="link_id"><option value="">—</option>@foreach($linkList as $l)<option value="{{ $l->id }}" @selected($p?->link_id === $l->id)>/go/{{ $l->code }} – {{ $l->name }}</option>@endforeach</select></div>
    <div class="field span-all"><label>Media (link to the image / video / design file)</label><input type="url" name="media_url" maxlength="500" value="{{ $p?->media_url }}" placeholder="https://drive.example.com/…"></div>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn" name="submit" value="0">Save draft</button><button class="btn btn-primary" name="submit" value="1">Save & send for approval</button></div>
</form></div></div>
