@extends('layouts.app')
@section('title', 'Quick Replies')
@section('crumbs') Guest Messaging / Quick Replies @endsection
@php $C = \App\Models\Conversation::class; @endphp
@section('actions')<button class="btn btn-primary" data-modal="qrNew" id="qrNewBtn"><x-icon name="plus"/> New quick reply</button>@endsection
@section('content')
<div class="card"><div class="card-body table-wrap">
  <p class="small muted mt-0">Type <b>/shortcut</b> in the reply box (or pick from the list) to insert the text. Placeholders are filled in for the guest: {{ collect($vars)->map(fn ($v) => '{'.$v.'}')->implode(' ') }}</p>
  <table class="table table-compact" id="qrList"><thead><tr><th>Shortcut</th><th>Title</th><th>Text</th><th>Team</th><th class="num">Used</th><th></th></tr></thead><tbody>
  @forelse($list as $q)
    <tr class="{{ $q->is_active ? '' : 'muted' }}"><td><code>/{{ $q->shortcut }}</code></td><td>{{ $q->title }}@if(! $q->is_active) <span class="badge badge-slate">off</span>@endif</td><td class="small">{{ \Illuminate\Support\Str::limit($q->body, 110) }}</td>
      <td class="small">{{ $q->department ? ($C::DEPARTMENTS[$q->department] ?? $q->department) : 'All' }}</td><td class="num">{{ $q->uses }}</td>
      <td class="nowrap"><button class="btn btn-sm" data-modal="qr{{ $q->id }}">Edit</button>
        <form method="post" action="{{ route('inbox.replies.destroy', $q) }}" class="inline" onsubmit="return confirm('Delete this quick reply?')">@csrf @method('DELETE')<button class="btn btn-sm" title="Delete"><x-icon name="trash" size="sm"/></button></form></td></tr>
  @empty<tr><td colspan="6" class="muted">No quick replies yet.</td></tr>@endforelse
  </tbody></table>
</div></div>
@foreach(array_merge([null], $list->all()) as $q)
<div class="modal" id="{{ $q ? 'qr'.$q->id : 'qrNew' }}"><div class="modal-box lg"><form method="post" action="{{ $q ? route('inbox.replies.update', $q) : route('inbox.replies.store') }}" @if(! $q) data-reset @endif>@csrf @if($q) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $q ? '/'.$q->shortcut : 'New quick reply' }}</h3><button type="button" class="btn btn-sm" data-close>&times;</button></div>
  <div class="modal-body form-grid">
    <div class="field"><label>Shortcut</label><input name="shortcut" class="input" maxlength="30" required pattern="[A-Za-z0-9_\-]+" value="{{ $q?->shortcut }}" placeholder="wifi"></div>
    <div class="field"><label>Title</label><input name="title" class="input" maxlength="80" required value="{{ $q?->title }}"></div>
    <div class="field span-all"><label>Text</label><textarea name="body" rows="5" maxlength="1500" required>{{ $q?->body }}</textarea></div>
    <div class="field"><label>Team</label><select name="department" class="input"><option value="">All teams</option>@foreach($C::DEPARTMENTS as $k => $l)<option value="{{ $k }}" @selected($q?->department === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label>&nbsp;</label><label class="small tv-cb"><input type="checkbox" name="is_active" value="1" @checked($q ? $q->is_active : true)> In use</label></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endforeach
@endsection
