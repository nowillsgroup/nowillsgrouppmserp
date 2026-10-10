@php $mid = $x ? 'contactModal'.$x->id : 'contactModalNew'; @endphp
<div class="modal" id="{{ $mid }}"><div class="modal-box" style="max-width:40rem"><form method="post" action="{{ $x ? route('sales.contact.update', $x) : route('sales.contacts.store', $c) }}">@csrf @if($x) @method('PUT') @endif
  <div class="modal-head"><h3>{{ $x ? 'Edit '.$x->name : 'New contact at '.$c->name }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label>Name</label><input name="name" maxlength="120" required value="{{ $x?->name }}"></div>
    <div class="field"><label>Job title</label><input name="title" maxlength="120" value="{{ $x?->title }}" placeholder="Travel manager, HR director…"></div>
    <div class="field"><label>E-mail</label><input type="email" name="email" maxlength="150" value="{{ $x?->email }}"></div>
    <div class="field"><label>Phone</label><input name="phone" maxlength="30" value="{{ $x?->phone }}"></div>
    <div class="field"><label>Birthday</label><input type="date" name="birthday" value="{{ $x?->birthday?->toDateString() }}"></div>
    <div class="field"></div>
    <div class="field span-all"><label>Notes</label><input name="notes" maxlength="500" value="{{ $x?->notes }}" placeholder="Preferences, assistant's name, best time to call…"></div>
    <label class="small"><input type="checkbox" name="is_primary" value="1" @checked($x?->is_primary ?? ! $c->contacts->count())> Primary contact (used on statements)</label>
    <label class="small"><input type="checkbox" name="decision_maker" value="1" @checked($x?->decision_maker)> Decision maker</label>
    @if($x)<label class="small"><input type="checkbox" name="is_active" value="1" @checked($x->is_active)> Active</label>@endif
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save contact</button></div>
</form></div></div>
