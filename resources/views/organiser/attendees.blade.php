@extends('organiser.layout')
@section('title', 'Guest list · '.$e->event_no)
@section('crumbs')<a href="{{ route('organiser.events') }}">My events</a> · <a href="{{ route('organiser.event', $e->id) }}">{{ $e->event_no }}</a> · Guest list@endsection
@section('actions') <button class="btn" type="button" onclick="window.print()"><x-icon name="print"/> Print</button> @endsection
@section('content')
@if($errors->has('guest'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('guest') }}</div></div>@endif
<div class="stats" id="orgGuestStats">
  <x-stat label="Guests on the list" :value="$summary['total']" icon="users" tone="blue"/>
  @foreach($summary['tags'] as $tag => $n)<x-stat :label="\App\Models\OrganiserAttendee::TAGS[$tag] ?? $tag" :value="$n" icon="user" tone="slate"/>@endforeach
  @if($summary['dietary'])<x-stat label="Special meals" :value="array_sum($summary['dietary'])" icon="cup" tone="amber" :sub="collect($summary['dietary'])->take(3)->map(fn($n,$k) => $n.' × '.$k)->implode(', ')"/>@endif
</div>
<div class="g-2">
  <div class="card"><div class="card-body table-wrap">
    @if($rows->isEmpty())<x-empty text="The guest list is empty – add your first guest." icon="users"/>@else
    <table class="table" id="orgAttendees"><thead><tr><th>Guest</th><th>Contact</th><th>Dietary / needs</th><th>Tag</th><th></th></tr></thead><tbody>
    @foreach($rows as $a)
      <tr><td><b>{{ $a->name }}</b>@if($a->notes)<div class="tiny muted">{{ $a->notes }}</div>@endif</td>
        <td class="small">{{ $a->email }}{{ $a->email && $a->phone ? ' · ' : '' }}{{ $a->phone }}</td>
        <td class="small">{{ $a->dietary ?: '—' }}</td><td><span class="badge badge-slate">{{ \App\Models\OrganiserAttendee::TAGS[$a->tag] }}</span></td>
        <td>@if($e->isEditable())<form method="post" action="{{ route('organiser.attendees.delete', [$e->id, $a->id]) }}" onsubmit="return confirm('Remove {{ $a->name }}?')">@csrf<button class="btn btn-sm" type="submit">Remove</button></form>@endif</td></tr>
    @endforeach
    </tbody></table>@endif
  </div></div>
  <div class="card no-print"><div class="card-head"><h3><x-icon name="plus"/> Add a guest</h3></div><div class="card-body">
    @if($e->isEditable())
    <form method="post" action="{{ route('organiser.attendees.store', $e->id) }}" id="orgGuestForm">
      @csrf
      <div class="field"><label for="g_name">Full name</label><input class="input" id="g_name" name="name" value="{{ old('name') }}" required maxlength="120"></div>
      <div class="g-2">
        <div class="field"><label for="g_email">E-mail (optional)</label><input class="input" id="g_email" type="email" name="email" value="{{ old('email') }}" maxlength="190"></div>
        <div class="field"><label for="g_phone">Phone (optional)</label><input class="input" id="g_phone" name="phone" value="{{ old('phone') }}" maxlength="30"></div>
      </div>
      <div class="g-2">
        <div class="field"><label for="g_dietary">Dietary / needs (optional)</label><input class="input" id="g_dietary" name="dietary" value="{{ old('dietary') }}" maxlength="120" placeholder="e.g. vegetarian, no pork, nut allergy"></div>
        <div class="field"><label for="g_tag">Tag</label><select class="input" id="g_tag" name="tag">@foreach(\App\Models\OrganiserAttendee::TAGS as $k => $v)<option value="{{ $k }}" {{ old('tag') === $k ? 'selected' : '' }}>{{ $v }}</option>@endforeach</select></div>
      </div>
      <div class="field"><label for="g_notes">Note (optional)</label><input class="input" id="g_notes" name="notes" value="{{ old('notes') }}" maxlength="200"></div>
      <button class="btn btn-primary" type="submit">Add guest</button>
    </form>
    @else<p class="muted m-0">This event is {{ $e->status }} – the guest list is closed.</p>@endif
  </div></div>
</div>
@endsection
