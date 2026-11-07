@php
  // Rooms keep their pictures in room_images (the same store the reservation desk
  // reads), so the form only has to offer the upload; the card below manages the
  // photos already on the record.
  $photoCount = $photos->count();
@endphp
<div class="card" style="max-width:900px;margin-top:1rem">
  <div class="card-head">
    <h3>Room photos</h3>
    <span class="small muted">{{ $photoCount }} on file@if($photos->where('is_primary', true)->isNotEmpty()) · 1 primary@endif</span>
  </div>
  <div class="card-body">
    @if($photoCount === 0)
      <p class="small muted" style="margin:0">No photo on file yet. Add one above — the reservation desk, the arrivals list and the room board show the primary photo.</p>
    @else
      <div class="room-photo-grid">
        @foreach($photos as $photo)
          <figure class="room-photo">
            <a class="room-photo-full" href="{{ $photo->url() }}" target="_blank" rel="noopener" aria-label="Open the full-size photo of room {{ $room->room_no }}">
              <img src="{{ $photo->url('thumb') }}" alt="{{ $photo->caption ?: 'Room '.$room->room_no }}" loading="lazy">
            </a>
            <figcaption>
              <span class="room-photo-caption">{{ $photo->caption ?: 'Room '.$room->room_no }}</span>
              @if($photo->is_primary)
                <span class="badge badge-green">Primary</span>
              @endif
              <span class="room-photo-actions">
                @unless($photo->is_primary)
                  <form method="post" action="{{ route('masters.photos.primary', [$def['slug'], $room->id, $photo->id]) }}">@csrf
                    <button class="btn btn-sm btn-ghost" type="submit">Make primary</button>
                  </form>
                @endunless
                <form method="post" action="{{ route('masters.photos.hide', [$def['slug'], $room->id, $photo->id]) }}" data-confirm="Remove this photo from the room? It stays on file for anything already pointing at it.">@csrf
                  <button class="btn btn-sm btn-ghost" type="submit">Remove</button>
                </form>
              </span>
            </figcaption>
          </figure>
        @endforeach
      </div>
    @endif

    <form method="post" action="{{ route('masters.photos.store', [$def['slug'], $room->id]) }}" enctype="multipart/form-data" class="filters" style="margin-top:1rem">
      @csrf
      <div class="field"><label for="roomPhotoMore">Add more photos</label>
        <input type="file" name="photos[]" id="roomPhotoMore" accept="image/jpeg,image/png,image/webp" multiple>
      </div>
      <div class="field"><label for="roomPhotoMoreCaption">Caption (optional)</label>
        <input name="photo_caption" id="roomPhotoMoreCaption" maxlength="160" placeholder="Describe the photo, e.g. sea-facing balcony">
      </div>
      <button class="btn btn-primary" type="submit">Upload</button>
    </form>
  </div>
</div>
