@extends('layouts.app')
@section('title', 'Routing Rules')
@section('crumbs') Guest Messaging / Routing Rules @endsection
@php $C = \App\Models\Conversation::class; $GPS = \App\Services\GuestPortalService::class; @endphp
@section('actions')<button class="btn btn-primary" data-modal="ruleNew" id="ruleNewBtn"><x-icon name="plus"/> New rule</button>@endsection
@section('content')
<div class="g-2-1">
  <div class="card"><div class="card-body table-wrap">
    <p class="small muted mt-0">Every new guest message is checked against the rules in order; the <b>first</b> matching rule routes the conversation, can mark it urgent, raise a guest request for an in-house guest and send an automatic answer. Messages no rule matches go to the default team.</p>
    <table class="table table-compact" id="ruleList"><thead><tr><th class="num">#</th><th>Rule</th><th>When the message contains</th><th>Then</th><th class="num">Matched</th><th></th></tr></thead><tbody>
    @forelse($list as $r)
      <tr class="{{ $r->is_active ? '' : 'muted' }}"><td class="num">{{ $r->sort }}</td><td><b>{{ $r->name }}</b>@if(! $r->is_active) <span class="badge badge-slate">off</span>@endif</td>
        <td class="small">{{ \Illuminate\Support\Str::limit($r->keywords, 80) }}</td><td class="small">{{ $r->actionsLabel() }}</td>
        <td class="num">{{ $r->hits }}@if($r->last_hit_at)<div class="tiny muted">{{ $r->last_hit_at->format('d M') }}</div>@endif</td>
        <td class="nowrap"><button class="btn btn-sm" data-modal="rule{{ $r->id }}">Edit</button>
          <form method="post" action="{{ route('inbox.rules.destroy', $r) }}" class="inline" onsubmit="return confirm('Delete this rule?')">@csrf @method('DELETE')<button class="btn btn-sm" title="Delete"><x-icon name="trash" size="sm"/></button></form></td></tr>
    @empty<tr><td colspan="6" class="muted">No rules yet.</td></tr>@endforelse
    </tbody></table>
  </div></div>
  <div class="card"><div class="card-head"><h3>Try a message</h3></div><div class="card-body">
    <form method="post" action="{{ route('inbox.rules.test') }}" id="ruleTestForm">@csrf
      <textarea name="text" rows="3" class="input" maxlength="500" required placeholder="e.g. The air con in my room is not working">{{ old('text') }}</textarea>
      <button class="btn btn-sm mt-1">Which rule handles it?</button>
    </form>
    <p class="tiny muted">Nothing is stored or sent – this only shows which rule would match.</p>
  </div></div>
</div>
@foreach(array_merge([null], $list->all()) as $r)
<div class="modal" id="{{ $r ? 'rule'.$r->id : 'ruleNew' }}"><div class="modal-box lg"><form method="post" action="{{ $r ? route('inbox.rules.update', $r) : route('inbox.rules.store') }}" @if(! $r) data-reset @endif>@csrf @if($r) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $r ? $r->name : 'New routing rule' }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body form-grid">
    <div class="field"><label>Name</label><input name="name" class="input" maxlength="80" required value="{{ $r?->name }}"></div>
    <div class="field"><label>Order</label><input type="number" name="sort" class="input" min="0" max="999" value="{{ $r?->sort ?? 10 }}"></div>
    <div class="field span-all"><label>Keywords or phrases (comma separated)</label><input name="keywords" class="input" maxlength="400" required value="{{ $r?->keywords }}" placeholder="towel, towels, extra towel"><small class="muted">whole words, any letter case</small></div>
    <div class="field"><label>Route to team</label><select name="department" class="input"><option value="">— keep —</option>@foreach($C::DEPARTMENTS as $k => $l)<option value="{{ $k }}" @selected($r?->department === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>Raise a guest request (in-house guests)</label><select name="request" class="input"><option value="">— none —</option>
      @foreach($categories as $ck => [$cl]) @if(! in_array($ck, ['checkout', 'wakeup', 'late_checkout', 'front_desk']))<optgroup label="{{ $cl }}">@foreach($GPS::items($ck) as $it)<option value="{{ $ck }}|{{ $it }}" @selected($r && $r->request_category === $ck && $r->request_item === $it)>{{ $it }}</option>@endforeach</optgroup>@endif @endforeach
    </select></div>
    <div class="field span-all"><label>Automatic answer (optional)</label><textarea name="reply" rows="3" maxlength="1000" placeholder="Leave empty to let a person answer">{{ $r?->reply }}</textarea><small class="muted">Placeholders: {{ collect($vars)->map(fn ($v) => '{'.$v.'}')->implode(' ') }}</small></div>
    <div class="span-all"><label class="small tv-cb"><input type="checkbox" name="urgent" value="1" @checked($r?->urgent)> Mark the conversation urgent</label><label class="small tv-cb"><input type="checkbox" name="is_active" value="1" @checked($r ? $r->is_active : true)> Rule in use</label></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save rule</button></div>
</form></div></div>
@endforeach
@endsection
