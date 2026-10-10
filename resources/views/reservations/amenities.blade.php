@extends('layouts.app')
@section('title', 'Room amenities and photos')
@section('content')
<p class="small"><a href="{{ route('reservations.index') }}">← Reservation desk</a></p>
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3>Amenity catalog</h3></div><div class="card-body">
    <p class="small muted">Names already on room types were copied here. Add a name only when the hotel actually offers it. Nothing is shown until it is attached to a type or a room.</p>
    @if($amenities->isEmpty())<p>No amenities are on file yet.</p>@endif
    <ul>@foreach($amenities as $a)<li>{{ $a->name }} <span class="tiny muted">{{ $a->category }} · {{ $a->links_count }} link(s)</span></li>@endforeach</ul>
    @if(can_do('reservations.edit'))
    <form method="post" action="{{ route('reservations.amenities.store') }}" class="filters">
      @csrf
      <div class="field"><label>Name</label><input name="name" required maxlength="80"></div>
      <div class="field"><label>Category</label><input name="category" placeholder="comfort, bath, view"></div>
      <div class="field"><label>Attach to type</label><select name="room_type_id"><option value="">—</option>@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
      <div class="field"><label>Or one room</label><select name="room_id"><option value="">—</option>@foreach($rooms as $r)<option value="{{ $r->id }}">{{ $r->room_no }}</option>@endforeach</select></div>
      <button class="btn btn-primary">Save amenity</button>
    </form>
    @endif
  </div></div>
  <div class="card"><div class="card-head"><h3>Photos</h3></div><div class="card-body">
    <p class="small muted">Upload a photo of the real room or type. The desk never substitutes a stock hotel photo.</p>
    @if(can_do('reservations.edit'))
    <form method="post" action="{{ route('reservations.images.store') }}" enctype="multipart/form-data" class="filters">
      @csrf
      <div class="field"><label>Photo</label><input type="file" name="photo" accept="image/*" required></div>
      <div class="field"><label>Room</label><select name="room_id"><option value="">—</option>@foreach($rooms as $r)<option value="{{ $r->id }}">{{ $r->room_no }}</option>@endforeach</select></div>
      <div class="field"><label>Or room type</label><select name="room_type_id"><option value="">—</option>@foreach($types as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach</select></div>
      <div class="field"><label>Caption</label><input name="caption" maxlength="160"></div>
      <button class="btn btn-primary">Upload</button>
    </form>
    @endif
    <div class="table-wrap"><table class="table"><thead><tr><th>Room</th><th>Photos on file</th></tr></thead><tbody>
      @foreach($rooms as $r)
        <tr><td>{{ $r->room_no }}</td><td>
          @forelse($r->images as $img)
            <span class="tiny">{{ $img->caption ?: 'Photo' }}@if($img->is_primary) · primary @endif</span>
            @if(can_do('reservations.edit') && ! $img->is_primary)
              <form method="post" action="{{ route('reservations.images.primary', $img) }}" class="inline">@csrf<button class="linkish">Set primary</button></form>
            @endif
          @empty
            No photo on file
          @endforelse
        </td></tr>
      @endforeach
    </tbody></table></div>
  </div></div>
</div>
@endsection
