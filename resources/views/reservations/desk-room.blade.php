@php
  $stay = $room->currentStay;
  $roomTypeName = $room->roomType?->name ?? $room->roomType?->code ?? 'Room type not set';
  $floorName = $room->floor?->name ?? 'Floor not assigned';
  $roomStatusLabel = ucwords(str_replace('_', ' ', $room->status));
  $hkStatusLabel = ucwords(str_replace('_', ' ', $room->hk_status));
  $roomStatusClass = str_replace('_', '-', $room->status);
  $hkStatusClass = str_replace('_', '-', $room->hk_status);
  $canViewFolio = can_do('folio.view');
  $stayBal = $canViewFolio && $stay?->folio ? $stay->folio->balance() : null;
  $gallery = collect();
  if ($photo) {
    $featured = $roomImages->firstWhere('path', $photo);
    $gallery->push(['path' => $photo, 'caption' => $featured?->caption ?: 'Room '.$room->room_no]);
  }
  foreach ($roomImages as $image) {
    if ($image->path !== $photo) $gallery->push(['path' => $image->path, 'caption' => $image->caption ?: 'Room '.$room->room_no]);
  }
  $gallery = $gallery->unique('path')->take(6)->values();
@endphp
<main class="room-detail-page" id="roomProfile">
  <header class="room-profile-hero">
    <div class="room-profile-gallery" role="group" aria-label="Room photos">
      <figure class="room-profile-feature">
        @if($gallery->isNotEmpty())
          <img id="roomHeroPhoto" src="{{ asset($gallery->first()['path']) }}" alt="{{ $gallery->first()['caption'] }}">
        @else
          <div class="room-profile-no-photo" role="img" aria-label="No photo on file for room {{ $room->room_no }}">
            <span>{{ $room->roomType?->code ?? 'ROOM' }}</span>
            <small>No room photo on file</small>
          </div>
        @endif
        <figcaption><span>ROOM PROFILE</span><strong>Room {{ $room->room_no }}</strong></figcaption>
      </figure>
      @if($gallery->count() > 1)
        <div class="room-profile-thumbnails" role="group" aria-label="Choose a room photo">
          @foreach($gallery as $image)
            <button type="button" class="room-profile-thumbnail {{ $loop->first ? 'is-selected' : '' }}" data-room-photo="{{ asset($image['path']) }}" data-room-alt="{{ $image['caption'] }}" aria-label="Show photo: {{ $image['caption'] }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">
              <img src="{{ asset($image['path']) }}" alt="" loading="lazy">
            </button>
          @endforeach
        </div>
      @endif
    </div>

    <div class="room-profile-copy">
      <div class="room-profile-eyebrow"><span>FRONT OFFICE</span><i aria-hidden="true">/</i><span>ROOM PROFILE</span></div>
      <h1>Room {{ $room->room_no }}</h1>
      <p class="room-profile-subtitle">{{ $roomTypeName }} <span aria-hidden="true">·</span> {{ $floorName }}</p>
      <div class="room-profile-statuses" role="group" aria-label="Current room and housekeeping status">
        <span class="room-profile-status room-state-{{ $roomStatusClass }}"><i aria-hidden="true"></i>{{ $roomStatusLabel }}</span>
        <span class="room-profile-status hk-state-{{ $hkStatusClass }}">HK · {{ $hkStatusLabel }}</span>
        @if(!$room->is_active)<span class="room-profile-status room-state-inactive">Inactive inventory</span>@endif
      </div>
      <div class="room-profile-actions">
        <a class="room-profile-button is-primary" href="{{ route('reservations.index', ['view' => 'board']) }}"><span aria-hidden="true">←</span> Room board</a>
        <a class="room-profile-button" href="{{ route('reservations.index', ['view' => 'timeline', 'date' => bdate()->toDateString(), 'days' => 14, 'group' => 'type', 'q' => $room->room_no]) }}">View room timeline</a>
        <button class="room-profile-button" type="button" data-print-room>Print profile</button>
        @if(can_do('housekeeping.view') || can_do('housekeeping.manage'))
          <a class="room-profile-button" href="{{ route('housekeeping.index') }}">Housekeeping board</a>
        @endif
        @if(can_do('maintenance.manage'))
          <a class="room-profile-button" href="{{ route('maintenance.index') }}">Maintenance</a>
        @endif
      </div>
      <dl class="room-profile-facts">
        <div><dt>Room type</dt><dd>{{ $roomTypeName }}</dd></div>
        <div><dt>Floor</dt><dd>{{ $floorName }}</dd></div>
        <div><dt>Extension</dt><dd>{{ $room->extension ?: 'Not set' }}</dd></div>
        <div><dt>Smoking</dt><dd>{{ $room->is_smoking ? 'Smoking room' : 'Non-smoking' }}</dd></div>
      </dl>
    </div>
  </header>

  <section class="room-profile-summary" aria-label="Room overview">
    <div class="room-summary-item"><span>In-house guest</span><strong>{{ $stay ? '1 stay' : 'None' }}</strong><small>{{ $stay ? 'Currently checked in' : 'No active stay' }}</small></div>
    <div class="room-summary-item"><span>Open maintenance</span><strong>{{ number_format($openTicketCount) }}</strong><small>{{ \Illuminate\Support\Str::plural('ticket', $openTicketCount) }} requiring attention</small></div>
    <div class="room-summary-item"><span>Recent housekeeping</span><strong>{{ number_format($tasks->count()) }}</strong><small>Latest recorded tasks</small></div>
    <div class="room-summary-item"><span>Recent stays</span><strong>{{ number_format($history->count()) }}</strong><small>Latest completed room stays</small></div>
  </section>

  <div class="room-profile-primary-grid">
    <section class="room-profile-panel room-current-stay" aria-labelledby="roomCurrentStayHeading">
      <div class="room-panel-heading">
        <div><span class="room-panel-kicker">GUEST &amp; STAY</span><h2 id="roomCurrentStayHeading">Current occupancy</h2></div>
        @if($stay)<span class="room-panel-live"><i aria-hidden="true"></i> In house</span>@endif
      </div>
      @if($stay)
        <div class="room-current-guest">
          <span class="room-guest-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($stay->reservation?->guest?->full_name ?? 'G', 0, 1)) }}</span>
          <div class="room-current-guest-copy">
            <a href="{{ route('reservations.stay', $stay) }}" class="room-current-guest-name">{{ $stay->reservation?->guest?->full_name ?? 'Guest profile unavailable' }}</a>
            <span>{{ $stay->reservation?->reservation_no ?? 'Reservation details' }}@if($stay->reservation?->guest?->is_vip) <b class="room-guest-vip">VIP</b>@endif</span>
          </div>
        </div>
        <dl class="room-stay-facts {{ $canViewFolio ? 'has-folio' : '' }}">
          <div><dt>Arrival</dt><dd><time datetime="{{ $stay->arrival_date->toDateString() }}">{{ $stay->arrival_date->format('d M Y') }}</time></dd></div>
          <div><dt>Departure</dt><dd><time datetime="{{ $stay->departure_date->toDateString() }}">{{ $stay->departure_date->format('d M Y') }}</time></dd></div>
          @if($canViewFolio)<div><dt>Folio balance</dt><dd>{{ $stayBal === null ? 'No folio linked' : money($stayBal) }}</dd></div>@endif
        </dl>
        <a class="room-panel-link" href="{{ route('reservations.stay', $stay) }}">Open stay details <span aria-hidden="true">→</span></a>
      @else
        <div class="room-panel-empty"><span aria-hidden="true">—</span><div><strong>No guest is checked in</strong><p>There is no active in-house stay assigned to this room.</p></div></div>
      @endif
    </section>

    <section class="room-profile-panel room-amenities-panel" aria-labelledby="roomAmenitiesHeading">
      <div class="room-panel-heading">
        <div><span class="room-panel-kicker">ROOM FEATURES</span><h2 id="roomAmenitiesHeading">Amenities &amp; notes</h2></div>
        <span class="room-panel-count">{{ number_format($amenities->count()) }}</span>
      </div>
      @if($amenities->isNotEmpty())
        <ul class="room-amenity-list">
          @foreach($amenities as $amenity)<li><span aria-hidden="true">✓</span>{{ $amenity }}</li>@endforeach
        </ul>
      @else
        <p class="room-panel-muted">No amenities are recorded for this room or its room type.</p>
      @endif
      @if($room->notes)
        <div class="room-note"><strong>Room note</strong><p>{{ $room->notes }}</p></div>
      @endif
    </section>
  </div>

  <section class="room-profile-panel room-upcoming-panel" aria-labelledby="roomUpcomingHeading">
    <div class="room-panel-heading">
      <div><span class="room-panel-kicker">FOR THIS ROOM</span><h2 id="roomUpcomingHeading">Upcoming stays</h2></div>
      <span class="room-panel-count">Next {{ number_format($upcomingStays->count()) }}</span>
    </div>
    @if($upcomingStays->isNotEmpty())
      <ol class="room-upcoming-list">
        @foreach($upcomingStays as $upcoming)
          @php $upcomingGuest = $upcoming->reservation?->guest?->full_name ?? 'Guest profile unavailable'; @endphp
          <li class="room-upcoming-row" aria-label="Upcoming stay: {{ $upcomingGuest }}, arrives {{ $upcoming->arrival_date->format('d M Y') }}, departs {{ $upcoming->departure_date->format('d M Y') }}">
            <time class="room-upcoming-date" datetime="{{ $upcoming->arrival_date->toDateString() }}"><span>{{ $upcoming->arrival_date->format('D') }}</span><strong>{{ $upcoming->arrival_date->format('d') }}</strong><small>{{ $upcoming->arrival_date->format('M Y') }}</small></time>
            <div class="room-upcoming-copy">
              <a href="{{ route('reservations.stay', $upcoming) }}">{{ $upcomingGuest }}</a>
              <span>{{ $upcoming->reservation?->reservation_no ?? 'Reservation details' }} · <time datetime="{{ $upcoming->arrival_date->toDateString() }}">{{ $upcoming->arrival_date->format('d M Y') }}</time> → <time datetime="{{ $upcoming->departure_date->toDateString() }}">{{ $upcoming->departure_date->format('d M Y') }}</time></span>
              <small>{{ ($upcoming->reservation?->source?->name ?? 'Source not recorded').($upcoming->reservation?->booking_type === 'group' ? ' · Group stay' : '') }}</small>
            </div>
            <span class="room-upcoming-status status-{{ str_replace('_', '-', $upcoming->reservation?->status ?? 'confirmed') }}">{{ ucwords(str_replace('_', ' ', $upcoming->reservation?->status ?? 'confirmed')) }}</span>
          </li>
        @endforeach
      </ol>
    @else
      <p class="room-panel-muted">No upcoming reserved stay is currently assigned to this room.</p>
    @endif
  </section>

  @if($outages->isNotEmpty())
    <section class="room-profile-panel room-outages-panel" aria-labelledby="roomOutagesHeading">
      <div class="room-panel-heading">
        <div><span class="room-panel-kicker">INVENTORY CONTROL</span><h2 id="roomOutagesHeading">Room blocks</h2></div>
        <span class="room-panel-count">{{ number_format($outages->count()) }}</span>
      </div>
      <ul class="room-outage-list">
        @foreach($outages as $outage)
          @php
            $outageActive = $outage->starts_on->toDateString() <= bdate()->toDateString();
            $outageReason = \App\Models\RoomOutage::REASONS[$outage->reason] ?? str_replace('_', ' ', $outage->reason);
          @endphp
          <li class="room-outage-row {{ $outageActive ? 'is-active' : 'is-upcoming' }}">
            <span class="room-outage-marker" aria-hidden="true">!</span>
            <div class="room-outage-copy">
              <strong>{{ $outageActive ? 'Active block' : 'Upcoming block' }} · {{ $outageReason }}</strong>
              <span>Starts <time datetime="{{ $outage->starts_on->toDateString() }}">{{ $outage->starts_on->format('d M Y') }}</time> · room returns <time datetime="{{ $outage->ends_on->toDateString() }}">{{ $outage->ends_on->format('d M Y') }}</time></span>
              @if($outage->notes)<small>{{ $outage->notes }}</small>@endif
            </div>
            @if(can_do('reservations.edit'))
              <form method="post" action="{{ route('reservations.outages.release', $outage) }}" onsubmit="return confirm('Release this room block?')">
                @csrf
                <button class="room-profile-button is-danger" type="submit">Release block</button>
              </form>
            @endif
          </li>
        @endforeach
      </ul>
    </section>
  @endif

  <div class="room-profile-operations-grid">
    <section class="room-profile-panel" aria-labelledby="roomHousekeepingHeading">
      <div class="room-panel-heading">
        <div><span class="room-panel-kicker">CLEANING &amp; INSPECTIONS</span><h2 id="roomHousekeepingHeading">Housekeeping activity</h2></div>
        <span class="room-panel-count">{{ number_format($tasks->count()) }}</span>
      </div>
      @if($tasks->isNotEmpty())
        <ol class="room-activity-list">
          @foreach($tasks as $task)
            <li>
              <span class="room-activity-mark" aria-hidden="true">{{ $task->status === 'completed' ? '✓' : '•' }}</span>
              <div class="room-activity-copy">
                <strong>{{ \App\Models\HousekeepingTask::TYPES[$task->task_type] ?? ucwords(str_replace('_', ' ', $task->task_type)) }}</strong>
                <span><time datetime="{{ $task->task_date?->toDateString() }}">{{ $task->task_date?->format('d M Y') ?? 'Date unavailable' }}</time>@if($task->employee) · {{ $task->employee->name }}@endif</span>
                @if($task->completed_at)<small>Completed <time datetime="{{ $task->completed_at->toIso8601String() }}">{{ $task->completed_at->format('d M Y, H:i') }}</time></small>@endif
              </div>
              <span class="room-activity-status status-{{ str_replace('_', '-', $task->status) }}">{{ ucwords(str_replace('_', ' ', $task->status)) }}</span>
            </li>
          @endforeach
        </ol>
      @else
        <p class="room-panel-muted">No housekeeping activity is recorded for this room yet.</p>
      @endif
    </section>

    <section class="room-profile-panel" aria-labelledby="roomMaintenanceHeading">
      <div class="room-panel-heading">
        <div><span class="room-panel-kicker">REPAIRS &amp; FOLLOW-UP</span><h2 id="roomMaintenanceHeading">Maintenance</h2></div>
        <span class="room-panel-count {{ $openTicketCount ? 'has-alert' : '' }}">{{ number_format($openTicketCount) }} open</span>
      </div>
      @if($tickets->isNotEmpty())
        <ol class="room-activity-list">
          @foreach($tickets as $ticket)
            <li>
              <span class="room-activity-mark maintenance-mark" aria-hidden="true">!</span>
              <div class="room-activity-copy">
                <strong>@if(can_do('maintenance.manage'))<a href="{{ route('maintenance.index') }}">{{ $ticket->ticket_no }}</a>@else{{ $ticket->ticket_no }}@endif · {{ $ticket->title }}</strong>
                <span>{{ ucfirst($ticket->priority) }} priority · {{ ucfirst(str_replace('_', ' ', $ticket->category)) }}@if($ticket->due_date) · Due <time datetime="{{ $ticket->due_date->toDateString() }}">{{ $ticket->due_date->format('d M Y') }}</time>@endif</span>
              </div>
              <span class="room-activity-status status-{{ str_replace('_', '-', $ticket->status) }}">{{ ucwords(str_replace('_', ' ', $ticket->status)) }}</span>
            </li>
          @endforeach
        </ol>
      @else
        <p class="room-panel-muted">No maintenance ticket is on file for this room.</p>
      @endif
    </section>
  </div>

  <section class="room-profile-panel room-history-panel" aria-labelledby="roomHistoryHeading">
    <div class="room-panel-heading">
      <div><span class="room-panel-kicker">ROOM HISTORY</span><h2 id="roomHistoryHeading">Previous stays</h2></div>
      <span class="room-panel-count">{{ number_format($history->count()) }}</span>
    </div>
    @if($history->isNotEmpty())
      <div class="room-history-table-wrap">
        <table class="room-history-table">
          <caption class="sr-only">Latest completed stays in room {{ $room->room_no }}</caption>
          <thead><tr><th scope="col">Guest</th><th scope="col">Reservation</th><th scope="col">Arrival</th><th scope="col">Departure</th><th scope="col">Status</th></tr></thead>
          <tbody>
            @foreach($history as $old)
              <tr>
                <td>{{ $old->reservation?->guest?->full_name ?? 'Guest profile unavailable' }}</td>
                <td><a href="{{ route('reservations.stay', $old) }}">{{ $old->reservation?->reservation_no ?? 'Open stay' }}</a></td>
                <td><time datetime="{{ $old->arrival_date->toDateString() }}">{{ $old->arrival_date->format('d M Y') }}</time></td>
                <td><time datetime="{{ $old->departure_date->toDateString() }}">{{ $old->departure_date->format('d M Y') }}</time></td>
                <td><span class="room-activity-status status-checked-out">Checked out</span></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @else
      <p class="room-panel-muted">No previous checkout is on file for this room.</p>
    @endif
  </section>

  @if(can_do('reservations.edit') || can_do('housekeeping.manage') || can_do('maintenance.manage'))
    <section class="room-action-area" id="roomActions" aria-labelledby="roomActionsHeading">
      <div class="room-action-heading">
        <div><span class="room-panel-kicker">AUTHORIZED OPERATIONS</span><h2 id="roomActionsHeading">Room actions</h2><p>Updates are saved to the room’s operational record.</p></div>
      </div>
      <div class="room-action-grid">
        @if(can_do('reservations.edit'))
          <section class="room-action-card" aria-labelledby="blockRoomHeading">
            <span class="room-action-icon icon-amber" aria-hidden="true">!</span>
            <h3 id="blockRoomHeading">Block this room</h3>
            <p>Take the room out of sale for a date range. The return date is the morning the room becomes available again.</p>
            <form method="post" action="{{ route('reservations.outages.store') }}" class="room-action-form" data-preview="{{ route('reservations.outages.preview') }}">
              @csrf
              <input type="hidden" name="room_id" value="{{ $room->id }}">
              <label>Block starts<input type="date" name="starts_on" required value="{{ old('starts_on', bdate()->toDateString()) }}" aria-describedby="blockRoomPreview"></label>
              <label>Room returns on<input type="date" name="ends_on" required value="{{ old('ends_on', bdate()->copy()->addDay()->toDateString()) }}" aria-describedby="blockRoomPreview"></label>
              <label>Reason<select name="reason" required>@foreach(\App\Models\RoomOutage::REASONS as $key => $label)<option value="{{ $key }}" @selected(old('reason', 'maintenance') === $key)>{{ $label }}</option>@endforeach</select></label>
              <label class="room-field-wide">Note (optional)<input name="notes" maxlength="255" value="{{ old('notes') }}" placeholder="Add context for the team"></label>
              <p class="room-action-quote" id="blockRoomPreview" aria-live="polite" role="status">Preview checks for overlapping stays. Nothing is saved until you confirm.</p>
              <div class="room-form-actions"><button class="room-profile-button" type="button" data-quote>Preview block</button><button class="room-profile-button is-primary" type="submit">Confirm block</button></div>
            </form>
          </section>
          <section class="room-action-card" aria-labelledby="uploadRoomPhotoHeading">
            <span class="room-action-icon icon-green" aria-hidden="true">▧</span>
            <h3 id="uploadRoomPhotoHeading">Update room photos</h3>
            <p>Add a genuine room image for the front-desk team. Uploads are saved to this room record.</p>
            <form method="post" action="{{ route('reservations.images.store') }}" enctype="multipart/form-data" class="room-action-form">
              @csrf
              <input type="hidden" name="room_id" value="{{ $room->id }}">
              <label>Choose image<input type="file" name="photo" accept="image/*" required></label>
              <label>Caption (optional)<input name="caption" maxlength="160" value="{{ old('caption') }}" placeholder="Describe this photo"></label>
              <button class="room-profile-button is-primary" type="submit">Upload room photo</button>
            </form>
          </section>
        @endif

        @if(can_do('housekeeping.manage'))
          <section class="room-action-card" aria-labelledby="requestCleaningHeading">
            <span class="room-action-icon icon-blue" aria-hidden="true">✦</span>
            <h3 id="requestCleaningHeading">Request housekeeping</h3>
            <p>Create a dated task for this room and send it to the housekeeping workflow.</p>
            <form method="post" action="{{ route('housekeeping.tasks.store') }}" class="room-action-form">
              @csrf
              <input type="hidden" name="room_id" value="{{ $room->id }}">
              <label>Task type<select name="task_type" required>@foreach(\App\Models\HousekeepingTask::TYPES as $key => $label)<option value="{{ $key }}" @selected(old('task_type', 'cleaning') === $key)>{{ $label }}</option>@endforeach</select></label>
              <label>Task date<input type="date" name="task_date" required value="{{ old('task_date', bdate()->toDateString()) }}"></label>
              <label>Priority<select name="priority" required>@foreach(['normal', 'high', 'low'] as $priority)<option value="{{ $priority }}" @selected(old('priority', 'normal') === $priority)>{{ ucfirst($priority) }}</option>@endforeach</select></label>
              <label>Note (optional)<input name="notes" maxlength="500" value="{{ old('notes') }}" placeholder="Instructions for housekeeping"></label>
              <button class="room-profile-button is-primary" type="submit">Create housekeeping task</button>
            </form>
          </section>
          <section class="room-action-card" aria-labelledby="updateHousekeepingHeading">
            <span class="room-action-icon icon-green" aria-hidden="true">✓</span>
            <h3 id="updateHousekeepingHeading">Update room status</h3>
            <p>Set the room’s current housekeeping condition.</p>
            <form method="post" action="{{ route('housekeeping.status') }}" class="room-action-form">
              @csrf
              <input type="hidden" name="rooms[]" value="{{ $room->id }}">
              <label>Housekeeping status<select name="hk_status" required>@foreach(['clean' => 'Clean', 'dirty' => 'Dirty', 'inspected' => 'Inspected', 'touch_up' => 'Touch up'] as $key => $label)<option value="{{ $key }}" @selected($room->hk_status === $key)>{{ $label }}</option>@endforeach</select></label>
              <label>Note (optional)<input name="notes" maxlength="500" placeholder="Add an update note"></label>
              <button class="room-profile-button is-primary" type="submit">Save room status</button>
              <a class="room-action-text-link" href="{{ route('housekeeping.index') }}">Open housekeeping board →</a>
            </form>
          </section>
        @elseif(can_do('housekeeping.view'))
          <section class="room-action-card room-action-readonly">
            <span class="room-action-icon icon-blue" aria-hidden="true">i</span>
            <h3>Housekeeping board</h3>
            <p>You can view housekeeping work. Updating room status requires housekeeping management permission.</p>
            <a class="room-profile-button" href="{{ route('housekeeping.index') }}">Open housekeeping board</a>
          </section>
        @endif

        @if(can_do('maintenance.manage'))
          <section class="room-action-card" aria-labelledby="createMaintenanceHeading">
            <span class="room-action-icon icon-amber" aria-hidden="true">⌁</span>
            <h3 id="createMaintenanceHeading">Report a maintenance issue</h3>
            <p>Open a ticket for repairs or a room condition that needs follow-up.</p>
            <form method="post" action="{{ route('maintenance.store') }}" class="room-action-form">
              @csrf
              <input type="hidden" name="room_id" value="{{ $room->id }}">
              <label>Issue title<input name="title" required maxlength="160" value="{{ old('title') }}" placeholder="What needs attention?"></label>
              <label>Category<select name="category" required>@foreach(['electrical','plumbing','ac','carpentry','it','civil','general'] as $category)<option value="{{ $category }}" @selected(old('category', 'general') === $category)>{{ ucfirst($category) }}</option>@endforeach</select></label>
              <label>Priority<select name="priority" required>@foreach(['low','normal','high','urgent'] as $priority)<option value="{{ $priority }}" @selected(old('priority', 'normal') === $priority)>{{ ucfirst($priority) }}</option>@endforeach</select></label>
              <label class="room-action-checkbox"><input type="checkbox" name="block_room" value="1" @checked(old('block_room'))> Block this room while it is repaired</label>
              <button class="room-profile-button is-primary" type="submit">Create maintenance ticket</button>
            </form>
          </section>
        @endif
      </div>
    </section>
  @endif

  <footer class="room-profile-footer">
    <span>Room {{ $room->room_no }} · {{ $roomTypeName }} · {{ $floorName }}</span>
    <a href="{{ route('reservations.index', ['view' => 'board']) }}">Back to the room board ↑</a>
  </footer>
</main>

@push('head')
<style>
.room-page-breadcrumb{display:flex;align-items:center;gap:9px;margin:0 0 14px;color:#758178;font-size:.76rem}
.room-page-breadcrumb a{color:#286443;font-weight:750;text-decoration:none}.room-page-breadcrumb a:hover{text-decoration:underline;text-underline-offset:3px}
.room-page-frame{max-width:1500px;margin:0 auto}
.room-detail-page{--room-ink:#20372a;--room-muted:#65756a;--room-line:#e1e9e2;--room-soft:#f6f9f6;--room-green:#246440;--room-green-dark:#194b2e;display:grid;gap:18px;color:var(--room-ink)}
.room-profile-hero{display:grid;grid-template-columns:minmax(280px,.94fr) minmax(360px,1.06fr);overflow:hidden;border:1px solid #dce7de;border-radius:20px;background:linear-gradient(135deg,#fff 0%,#f6faf6 100%);box-shadow:0 12px 34px rgba(34,76,47,.07)}
.room-profile-gallery{min-width:0;background:#e7eee8}.room-profile-feature{position:relative;display:grid;min-height:330px;margin:0;overflow:hidden;background:linear-gradient(145deg,#254e35,#7b9c7e);isolation:isolate}
.room-profile-feature:after{position:absolute;z-index:1;inset:42% 0 0;background:linear-gradient(transparent,rgba(9,26,15,.72));content:"";pointer-events:none}
.room-profile-feature>img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.room-profile-feature figcaption{position:absolute;z-index:2;right:20px;bottom:18px;left:20px;display:flex;flex-direction:column;gap:4px;color:#fff;text-shadow:0 1px 8px rgba(0,0,0,.4)}
.room-profile-feature figcaption span{font-size:.61rem;font-weight:850;letter-spacing:.16em}.room-profile-feature figcaption strong{font-size:1.25rem}
.room-profile-no-photo{display:grid;min-height:330px;place-content:center;gap:7px;color:#e9f3eb;text-align:center;background:radial-gradient(circle at 20% 15%,#4c805e,#193b29 70%)}
.room-profile-no-photo span{font-size:2.4rem;font-weight:900;letter-spacing:.08em}.room-profile-no-photo small{font-size:.78rem}
.room-profile-thumbnails{display:flex;gap:8px;overflow:auto;padding:10px 12px;background:#f0f5f0}
.room-profile-thumbnail{flex:0 0 68px;height:52px;padding:0;overflow:hidden;border:2px solid transparent;border-radius:8px;background:#fff;cursor:pointer}
.room-profile-thumbnail img{width:100%;height:100%;object-fit:cover}.room-profile-thumbnail:hover,.room-profile-thumbnail.is-selected{border-color:#2c7047}.room-profile-thumbnail:focus-visible{outline:3px solid #8cb99b;outline-offset:2px}
.room-profile-copy{display:flex;flex-direction:column;justify-content:center;padding:clamp(22px,4vw,44px)}
.room-profile-eyebrow{display:flex;gap:8px;align-items:center;color:#52735b;font-size:.64rem;font-weight:850;letter-spacing:.14em}.room-profile-eyebrow i{font-style:normal;color:#97aa9b}
.room-profile-copy h1{margin:12px 0 4px;color:#203a29;font-size:clamp(2rem,4vw,3rem);font-weight:850;letter-spacing:-.05em;line-height:1.05}
.room-profile-subtitle{margin:0;color:#66776b;font-size:.91rem}.room-profile-statuses{display:flex;flex-wrap:wrap;gap:8px;margin-top:20px}
.room-profile-status{display:inline-flex;align-items:center;gap:7px;padding:7px 11px;border:1px solid #dce7de;border-radius:999px;background:#fff;color:#3d5945;font-size:.72rem;font-weight:800}
.room-profile-status i{width:7px;height:7px;border-radius:50%;background:#718177}.room-state-vacant{border-color:#c7e4d0;background:#edf8f0;color:#205d36}.room-state-vacant i{background:#27814a}
.room-state-occupied{border-color:#f0d5d1;background:#fff3f0;color:#943b31}.room-state-occupied i{background:#c55247}
.room-state-out-of-order,.room-state-out-of-service,.room-state-inactive{border-color:#e5d7d4;background:#f8f1ef;color:#7d4239}.room-state-out-of-order i,.room-state-out-of-service i{background:#aa5448}
.hk-state-clean,.hk-state-inspected{border-color:#cfe5d3;background:#f2f8f2;color:#356442}.hk-state-dirty{border-color:#f0d8c7;background:#fff6ef;color:#875031}.hk-state-touch-up{border-color:#eadfb9;background:#fff9e9;color:#785b1e}
.room-profile-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:20px}
.room-profile-button{display:inline-flex;min-height:38px;align-items:center;justify-content:center;gap:7px;padding:8px 13px;border:1px solid #d4e0d6;border-radius:9px;background:#fff;color:#31543a;font:inherit;font-size:.73rem;font-weight:800;text-decoration:none;cursor:pointer;transition:background .15s,border-color .15s,transform .15s}
.room-profile-button:hover{border-color:#9ab9a0;background:#f6faf6;transform:translateY(-1px)}.room-profile-button:focus-visible{outline:3px solid #8ab79a;outline-offset:2px}
.room-profile-button.is-primary{border-color:#246440;background:#246440;color:#fff}.room-profile-button.is-primary:hover{border-color:#194b2e;background:#194b2e}
.room-profile-button.is-danger{border-color:#ebcfcb;background:#fff;color:#8d352d}.room-profile-button.is-danger:hover{border-color:#d8aaa3;background:#fff6f4}
.room-profile-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px 16px;margin:23px 0 0;padding-top:18px;border-top:1px solid #e1e9e2}
.room-profile-facts div{min-width:0}.room-profile-facts dt,.room-stay-facts dt{margin-bottom:3px;color:#78877c;font-size:.61rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
.room-profile-facts dd,.room-stay-facts dd{margin:0;color:#2e4635;font-size:.78rem;font-weight:750;overflow-wrap:anywhere}
.room-profile-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));overflow:hidden;border:1px solid var(--room-line);border-radius:14px;background:#fff}
.room-summary-item{display:flex;min-width:0;flex-direction:column;gap:4px;padding:15px 18px;border-right:1px solid var(--room-line)}.room-summary-item:last-child{border-right:0}
.room-summary-item>span{color:#748177;font-size:.59rem;font-weight:850;letter-spacing:.08em;text-transform:uppercase}.room-summary-item strong{color:#254532;font-size:1.3rem;line-height:1.1}.room-summary-item small{color:#78867c;font-size:.65rem}
.room-profile-primary-grid,.room-profile-operations-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.room-profile-panel{min-width:0;padding:20px;border:1px solid var(--room-line);border-radius:15px;background:#fff;box-shadow:0 5px 18px rgba(31,67,42,.035)}
.room-panel-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:15px}.room-panel-heading h2,.room-action-heading h2{margin:4px 0 0;color:#263d2e;font-size:1.03rem;font-weight:820;letter-spacing:-.02em}
.room-panel-kicker{color:#718578;font-size:.57rem;font-weight:850;letter-spacing:.13em}.room-panel-count{display:inline-flex;min-width:30px;min-height:30px;align-items:center;justify-content:center;padding:0 9px;border:1px solid #e0e9e1;border-radius:999px;background:#f7faf7;color:#536b59;font-size:.68rem;font-weight:850;white-space:nowrap}.room-panel-count.has-alert{border-color:#f0d7ad;background:#fff8e9;color:#775318}
.room-panel-live{display:inline-flex;align-items:center;gap:6px;color:#2d7044;font-size:.66rem;font-weight:850}.room-panel-live i{width:7px;height:7px;border-radius:50%;background:#35a05b;box-shadow:0 0 0 3px #e4f3e8}
.room-current-guest{display:flex;align-items:center;gap:12px;padding:12px;border:1px solid #e2ebe3;border-radius:12px;background:#f8fbf8}
.room-guest-avatar{display:grid;width:42px;height:42px;flex:none;place-items:center;border:1px solid #d5e6d8;border-radius:13px;background:#eaf4eb;color:#28613d;font-size:1rem;font-weight:900}
.room-current-guest-copy{display:flex;min-width:0;flex-direction:column;gap:4px}.room-current-guest-name{overflow:hidden;color:#234e32;font-size:.88rem;font-weight:850;text-decoration:none;text-overflow:ellipsis;white-space:nowrap}.room-current-guest-name:hover{text-decoration:underline;text-underline-offset:3px}
.room-current-guest-copy>span{color:#778379;font-size:.67rem}.room-guest-vip{display:inline-flex;margin-left:4px;padding:2px 5px;border-radius:5px;background:#fff4d9;color:#795b1d;font-size:.56rem}
.room-stay-facts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:15px 0;padding:0}.room-stay-facts.has-folio{grid-template-columns:repeat(3,minmax(0,1fr))}.room-panel-link{display:inline-flex;align-items:center;gap:8px;color:#246440;font-size:.72rem;font-weight:850;text-decoration:none}.room-panel-link:hover{text-decoration:underline;text-underline-offset:3px}
.room-panel-empty{display:flex;align-items:center;gap:12px;padding:16px;border:1px dashed #d5e2d6;border-radius:12px;background:#f8fbf8}.room-panel-empty>span{color:#719079;font-size:1.5rem;font-weight:700}.room-panel-empty strong{color:#344c39;font-size:.78rem}.room-panel-empty p{margin:3px 0 0;color:#7b887e;font-size:.68rem}
.room-amenity-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px 12px;margin:0;padding:0;list-style:none}.room-amenity-list li{display:flex;min-width:0;align-items:center;gap:8px;color:#405a47;font-size:.72rem;overflow-wrap:anywhere}.room-amenity-list li span{display:grid;width:19px;height:19px;flex:none;place-items:center;border-radius:6px;background:#eaf4eb;color:#2b7043;font-size:.65rem;font-weight:900}
.room-panel-muted{margin:0;color:#758278;font-size:.72rem;line-height:1.6}
.room-upcoming-list{display:grid;gap:9px;margin:0;padding:0;list-style:none}.room-upcoming-row{display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid #e4ebe4;border-radius:11px;background:#fbfdfb}.room-upcoming-date{display:flex;width:52px;min-height:58px;flex:none;flex-direction:column;align-items:center;justify-content:center;border:1px solid #dce9de;border-radius:10px;background:#edf6ee;color:#2c6640}.room-upcoming-date span{font-size:.52rem;font-weight:850;text-transform:uppercase}.room-upcoming-date strong{font-size:1.15rem;line-height:1.15}.room-upcoming-date small{font-size:.52rem}.room-upcoming-copy{display:flex;min-width:0;flex:1;flex-direction:column;gap:3px}.room-upcoming-copy>a{overflow:hidden;color:#285d39;font-size:.75rem;font-weight:850;text-decoration:none;text-overflow:ellipsis;white-space:nowrap}.room-upcoming-copy>a:hover{text-decoration:underline;text-underline-offset:3px}.room-upcoming-copy span,.room-upcoming-copy small{color:#65756a;font-size:.61rem;line-height:1.45}.room-upcoming-status{display:inline-flex;flex:none;align-items:center;padding:5px 8px;border:1px solid #d8e4da;border-radius:999px;background:#f4f8f4;color:#4c6251;font-size:.57rem;font-weight:800;white-space:nowrap}.room-upcoming-status.status-tentative{border-color:#ecdcae;background:#fff9e9;color:#79601d}.room-upcoming-status.status-confirmed{border-color:#cfe5d3;background:#f0f8f1;color:#31633d}
.room-note{margin-top:14px;padding:11px 12px;border-left:3px solid #a8c7ae;border-radius:0 9px 9px 0;background:#f5f9f5}.room-note strong{color:#3c5942;font-size:.65rem}.room-note p{margin:4px 0 0;color:#65766a;font-size:.7rem;line-height:1.5;overflow-wrap:anywhere}
.room-outages-panel{border-color:#eadcb8;background:linear-gradient(135deg,#fffdf7,#fff)}.room-outage-list{display:grid;gap:9px;margin:0;padding:0;list-style:none}.room-outage-row{display:flex;align-items:center;gap:11px;padding:11px 12px;border:1px solid #ecdcb6;border-radius:11px;background:#fff9eb}.room-outage-row.is-active{border-color:#efcfca;background:#fff5f2}.room-outage-marker{display:grid;width:28px;height:28px;flex:none;place-items:center;border-radius:9px;background:#f5e6bf;color:#76591c;font-weight:900}.room-outage-row.is-active .room-outage-marker{background:#f4dfdb;color:#8e4137}.room-outage-copy{display:flex;min-width:0;flex:1;flex-direction:column;gap:3px}.room-outage-copy strong{color:#5d4a24;font-size:.72rem}.room-outage-row.is-active .room-outage-copy strong{color:#793c34}.room-outage-copy span,.room-outage-copy small{color:#796f58;font-size:.64rem;line-height:1.45}.room-outage-copy small{overflow-wrap:anywhere}
.room-profile-operations-grid{align-items:start}.room-activity-list{display:grid;gap:0;margin:0;padding:0;list-style:none}.room-activity-list li{display:flex;align-items:flex-start;gap:10px;padding:11px 0;border-top:1px solid #edf1ed}.room-activity-list li:first-child{padding-top:0;border-top:0}.room-activity-mark{display:grid;width:25px;height:25px;flex:none;place-items:center;border-radius:8px;background:#eaf4eb;color:#2e7446;font-size:.76rem;font-weight:900}.room-activity-mark.maintenance-mark{background:#fff3df;color:#85601c}.room-activity-copy{display:flex;min-width:0;flex:1;flex-direction:column;gap:3px}.room-activity-copy strong{color:#354a39;font-size:.69rem;line-height:1.4;overflow-wrap:anywhere}.room-activity-copy strong a{color:#276443;text-decoration:none}.room-activity-copy strong a:hover{text-decoration:underline}.room-activity-copy>span,.room-activity-copy small{color:#77847a;font-size:.61rem;line-height:1.45}.room-activity-status{display:inline-flex;flex:none;align-items:center;padding:4px 7px;border:1px solid #e0e8e1;border-radius:999px;background:#f6f9f6;color:#526357;font-size:.56rem;font-weight:800;white-space:nowrap}.room-activity-status.status-completed,.room-activity-status.status-resolved,.room-activity-status.status-closed{border-color:#cfe5d3;background:#f0f8f1;color:#31633d}.room-activity-status.status-open,.room-activity-status.status-pending{border-color:#f0d8ad;background:#fff8e9;color:#785719}.room-activity-status.status-in-progress{border-color:#d2e0f0;background:#f1f6fc;color:#3d5b7e}.room-activity-status.status-urgent{border-color:#efcfca;background:#fff3f1;color:#8a3c34}
.room-history-table-wrap{overflow-x:auto}.room-history-table{width:100%;border-collapse:collapse;text-align:left}.room-history-table th{padding:9px 10px;border-bottom:1px solid #e3eae4;color:#748177;font-size:.59rem;font-weight:850;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap}.room-history-table td{padding:11px 10px;border-bottom:1px solid #eef2ee;color:#45564a;font-size:.68rem;white-space:nowrap}.room-history-table tbody tr:last-child td{border-bottom:0}.room-history-table a{color:#286443;font-weight:800;text-decoration:none}.room-history-table a:hover{text-decoration:underline}
.room-action-area{padding:20px;border:1px solid #dce7de;border-radius:16px;background:linear-gradient(140deg,#f8fbf8,#f2f7f2)}.room-action-heading{margin-bottom:15px}.room-action-heading h2{font-size:1.15rem}.room-action-heading p{margin:5px 0 0;color:#758278;font-size:.71rem}.room-action-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.room-action-card{display:flex;min-width:0;flex-direction:column;align-items:flex-start;padding:16px;border:1px solid #e0e9e1;border-radius:13px;background:#fff}.room-action-icon{display:grid;width:31px;height:31px;place-items:center;border-radius:10px;font-size:.87rem;font-weight:900}.icon-amber{background:#fff3df;color:#805e1f}.icon-green{background:#eaf4eb;color:#286640}.icon-blue{background:#edf4fb;color:#466888}.room-action-card h3{margin:11px 0 4px;color:#2c4432;font-size:.83rem}.room-action-card>p{margin:0 0 12px;color:#748177;font-size:.67rem;line-height:1.5}.room-action-form{display:grid;width:100%;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.room-action-form label{display:grid;min-width:0;gap:5px;color:#637166;font-size:.62rem;font-weight:800}.room-action-form input:not([type=checkbox]),.room-action-form input[type=file],.room-action-form select{width:100%;min-width:0;min-height:38px;padding:8px 9px;border:1px solid #d7e2d8;border-radius:8px;background:#fff;color:#34483a;font:inherit;font-size:.68rem}.room-action-form input[type=file]{padding:6px;font-size:.61rem}.room-action-form input:focus-visible,.room-action-form select:focus-visible{outline:3px solid #acd0b5;outline-offset:1px;border-color:#76a584}.room-field-wide{grid-column:1/-1}.room-action-quote{grid-column:1/-1;margin:0;padding:9px 10px;border:1px solid #e3ebe3;border-radius:8px;background:#f7faf7;color:#647469;font-size:.63rem;line-height:1.5}.room-form-actions{display:flex;flex-wrap:wrap;gap:7px;grid-column:1/-1}.room-action-form>.room-profile-button{justify-self:start}.room-action-checkbox{display:flex!important;align-items:center;gap:8px!important;grid-column:1/-1;padding:8px 10px;border:1px solid #e3ebe3;border-radius:8px;background:#f8faf8}.room-action-checkbox input{accent-color:#246440}.room-action-text-link{color:#286443;font-size:.66rem;font-weight:800;text-decoration:none}.room-action-text-link:hover{text-decoration:underline}.room-action-readonly{justify-content:flex-start}
.room-profile-footer{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:10px;padding:4px 2px 12px;color:#77847a;font-size:.65rem}.room-profile-footer a{color:#286443;font-weight:800;text-decoration:none}.room-profile-footer a:hover{text-decoration:underline}
.room-detail-page :is(a,button,input,select):focus-visible{outline:3px solid #8eb99a;outline-offset:2px}
@media(max-width:1050px){.room-profile-hero{grid-template-columns:minmax(240px,.85fr) minmax(330px,1.15fr)}.room-profile-feature,.room-profile-no-photo{min-height:300px}}
@media(max-width:760px){.room-profile-hero{grid-template-columns:1fr}.room-profile-feature,.room-profile-no-photo{min-height:290px}.room-profile-copy{padding:23px}.room-profile-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.room-summary-item:nth-child(2){border-right:0}.room-summary-item:nth-child(-n+2){border-bottom:1px solid var(--room-line)}.room-profile-primary-grid,.room-profile-operations-grid{grid-template-columns:1fr}.room-profile-facts{margin-top:18px}.room-action-grid{grid-template-columns:1fr}}
@media(max-width:520px){.room-detail-page{gap:12px}.room-upcoming-row{align-items:flex-start;flex-wrap:wrap}.room-upcoming-status{margin-left:64px}.room-profile-hero{border-radius:15px}.room-profile-feature,.room-profile-no-photo{min-height:230px}.room-profile-copy{padding:19px}.room-profile-copy h1{font-size:2.2rem}.room-profile-actions{gap:6px}.room-profile-button{min-height:36px;padding:7px 10px;font-size:.68rem}.room-profile-facts{gap:10px}.room-summary-item{padding:12px}.room-summary-item strong{font-size:1.15rem}.room-profile-panel{padding:15px}.room-amenity-list{grid-template-columns:1fr 1fr;gap:8px}.room-stay-facts{grid-template-columns:1fr;gap:9px}.room-stay-facts div{display:flex;justify-content:space-between;gap:10px}.room-outage-row{align-items:flex-start;flex-wrap:wrap}.room-outage-row form{width:100%;margin-left:39px}.room-action-area{padding:15px}.room-action-form{grid-template-columns:1fr}.room-field-wide,.room-action-quote,.room-form-actions,.room-action-checkbox{grid-column:auto}.room-action-checkbox{display:flex!important}.room-history-table th,.room-history-table td{padding:9px 8px}.room-profile-footer{font-size:.6rem}}
@media(prefers-reduced-motion:reduce){.room-profile-button{transition:none}.room-profile-button:hover{transform:none}}
@media print{.room-page-breadcrumb,.room-profile-actions,.room-action-area,.room-profile-footer a,.room-outage-row form,.room-profile-thumbnails{display:none!important}.room-detail-page{gap:10px}.room-profile-hero,.room-profile-panel,.room-profile-summary{box-shadow:none;break-inside:avoid;border-color:#bbb}.room-profile-hero{grid-template-columns:35% 65%}.room-profile-feature,.room-profile-no-photo{min-height:190px}.room-profile-copy{padding:16px}.room-profile-panel{padding:12px}.room-profile-summary{grid-template-columns:repeat(4,1fr)}.room-profile-primary-grid,.room-profile-operations-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.room-action-area{display:none}}
body.dark .room-detail-page{--room-ink:#e5eee6;--room-muted:#b3c3b5;--room-line:#38483c;color:#e5eee6}body.dark .room-profile-hero{border-color:#384b3c;background:linear-gradient(135deg,#1c2a20,#202e23);box-shadow:none}body.dark .room-profile-copy h1,body.dark .room-panel-heading h2,body.dark .room-action-heading h2{color:#e4eee5}body.dark .room-profile-subtitle,body.dark .room-profile-facts dd,body.dark .room-stay-facts dd{color:#cfdbd0}body.dark .room-profile-eyebrow,body.dark .room-panel-kicker{color:#b2c8b5}body.dark .room-profile-facts,body.dark .room-stay-facts{border-color:#38483c}body.dark .room-profile-summary,body.dark .room-profile-panel,body.dark .room-action-card{border-color:#38483c;background:#202d23;box-shadow:none}body.dark .room-summary-item{border-color:#38483c}body.dark .room-summary-item>span,body.dark .room-summary-item small,body.dark .room-profile-footer,body.dark .room-panel-muted{color:#b1c0b3}body.dark .room-summary-item strong,body.dark .room-current-guest-copy strong,body.dark .room-outage-copy strong,body.dark .room-note strong{color:#e1ece2}body.dark .room-profile-status,body.dark .room-profile-button,body.dark .room-room-type,body.dark .room-profile-thumbnail{border-color:#46584a;background:#253429;color:#d3e0d5}body.dark .room-profile-button.is-primary{border-color:#36734c;background:#286440;color:#fff}body.dark .room-profile-button.is-danger{border-color:#68443f;background:#352622;color:#f2c7c0}body.dark .room-state-vacant,body.dark .hk-state-clean,body.dark .hk-state-inspected{border-color:#345a3e;background:#203b28;color:#b9d9be}body.dark .room-state-occupied{border-color:#68443f;background:#382724;color:#f0c1ba}body.dark .room-state-out-of-order,body.dark .room-state-out-of-service,body.dark .room-state-inactive{border-color:#55443e;background:#302622;color:#e4c2b8}body.dark .hk-state-dirty{border-color:#684c37;background:#392c21;color:#e8c6a9}body.dark .hk-state-touch-up{border-color:#65562f;background:#352f20;color:#e7d79f}body.dark .room-profile-actions .room-profile-button:not(.is-primary):hover,body.dark .room-profile-thumbnail:hover,body.dark .room-profile-thumbnail.is-selected{border-color:#77a882;background:#2a3d2f}body.dark .room-current-guest,body.dark .room-panel-empty,body.dark .room-note,body.dark .room-activity-list li,body.dark .room-upcoming-row,body.dark .room-action-area{border-color:#38483c;background:#1b281f}body.dark .room-guest-avatar,body.dark .room-amenity-list li span,body.dark .room-activity-mark{border-color:#35533a;background:#243a29;color:#c2dcc5}body.dark .room-current-guest-name,body.dark .room-amenity-list li,body.dark .room-note p,body.dark .room-panel-empty strong,body.dark .room-activity-copy strong,body.dark .room-history-table td,body.dark .room-upcoming-copy>a{color:#d1ded3}body.dark .room-upcoming-copy span,body.dark .room-upcoming-copy small{color:#acbcaf}body.dark .room-upcoming-date{border-color:#35533a;background:#243a29;color:#c2dcc5}body.dark .room-upcoming-status{border-color:#46584a;background:#253429;color:#d1ded3}body.dark .room-upcoming-status.status-confirmed{border-color:#345a3e;background:#203b28;color:#b9d9be}body.dark .room-upcoming-status.status-tentative{border-color:#65562f;background:#352f20;color:#e7d79f}body.dark .room-current-guest-copy>span,body.dark .room-stay-facts dt,body.dark .room-profile-facts dt,body.dark .room-activity-copy>span,body.dark .room-activity-copy small{color:#acbcaf}body.dark .room-guest-vip{background:#4a3d22;color:#f0d89c}body.dark .room-outages-panel{border-color:#625333;background:#282b22}body.dark .room-outage-row{border-color:#66552f;background:#302c21}body.dark .room-outage-row.is-active{border-color:#67453f;background:#332522}body.dark .room-outage-copy span,body.dark .room-outage-copy small{color:#d2c59f}body.dark .room-outage-row.is-active .room-outage-copy span,body.dark .room-outage-row.is-active .room-outage-copy small{color:#e1bdb5}body.dark .room-outage-marker{background:#514321;color:#f0d69a}body.dark .room-outage-row.is-active .room-outage-marker,body.dark .room-activity-mark.maintenance-mark{background:#57302b;color:#f4c0b8}body.dark .room-activity-status{border-color:#46584a;background:#253429;color:#d1ded3}body.dark .room-activity-status.status-completed,body.dark .room-activity-status.status-resolved,body.dark .room-activity-status.status-closed{border-color:#345a3e;background:#203b28;color:#b9d9be}body.dark .room-activity-status.status-open,body.dark .room-activity-status.status-pending{border-color:#65562f;background:#352f20;color:#e7d79f}body.dark .room-activity-status.status-in-progress{border-color:#3d5268;background:#25313c;color:#c1d5e8}body.dark .room-activity-status.status-urgent{border-color:#68443f;background:#382724;color:#f0c1ba}body.dark .room-history-table th,body.dark .room-history-table td{border-color:#38483c}body.dark .room-history-table th{color:#aebdaf}body.dark .room-history-table a,body.dark .room-panel-link,body.dark .room-action-text-link,body.dark .room-profile-footer a{color:#9cc9a7}body.dark .room-action-card>p,body.dark .room-action-heading p{color:#b1c0b3}body.dark .room-action-icon.icon-green{background:#243a29;color:#c2dcc5}body.dark .room-action-icon.icon-amber{background:#3d3320;color:#e7d79f}body.dark .room-action-icon.icon-blue{background:#263643;color:#c1d5e8}body.dark .room-action-form label{color:#bccabd}body.dark .room-action-form input:not([type=checkbox]),body.dark .room-action-form input[type=file],body.dark .room-action-form select{border-color:#46584a;background:#19251d;color:#e2ece3}body.dark .room-action-quote,body.dark .room-action-checkbox{border-color:#38483c;background:#1b281f;color:#b7c6b9}body.dark .room-action-checkbox input{accent-color:#78af84}
@media print{body.dark .room-profile-hero,body.dark .room-profile-summary,body.dark .room-profile-panel,body.dark .room-action-card,body.dark .room-action-area{background:#fff!important;color:#20372a!important;border-color:#bbb!important}body.dark .room-profile-copy h1,body.dark .room-panel-heading h2,body.dark .room-action-heading h2,body.dark .room-summary-item strong,body.dark .room-activity-copy strong,body.dark .room-history-table td,body.dark .room-current-guest-name,body.dark .room-amenity-list li{color:#20372a!important}body.dark .room-profile-facts dd,body.dark .room-stay-facts dd,body.dark .room-summary-item>span,body.dark .room-summary-item small,body.dark .room-activity-copy>span,body.dark .room-activity-copy small,body.dark .room-history-table th{color:#46564a!important}}
@media(prefers-reduced-motion:reduce){.room-detail-page *{scroll-behavior:auto!important}}
</style>
@endpush

@push('scripts')
<script>
(() => {
  document.querySelector('[data-print-room]')?.addEventListener('click', () => window.print());
  const hero = document.getElementById('roomHeroPhoto');
  document.querySelectorAll('[data-room-photo]').forEach(button => button.addEventListener('click', () => {
    if (hero) {
      hero.src = button.dataset.roomPhoto;
      hero.alt = button.dataset.roomAlt || 'Room photo';
    }
    document.querySelectorAll('[data-room-photo]').forEach(item => {
      const selected = item === button;
      item.classList.toggle('is-selected', selected);
      item.setAttribute('aria-pressed', selected ? 'true' : 'false');
    });
  }));

  const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
  const keyOf = form => {
    const data = new FormData(form);
    return ['starts_on', 'ends_on', 'reason', 'room_id'].map(key => data.get(key) || '').join('|');
  };
  document.querySelectorAll('.room-action-form[data-preview]').forEach(form => {
    const button = form.querySelector('[data-quote]');
    const quote = form.querySelector('.room-action-quote');
    if (!button) return;
    button.addEventListener('click', async () => {
      button.disabled = true;
      if (quote) quote.textContent = 'Checking dates and room availability…';
      try {
        const response = await fetch(form.dataset.preview, {
          method: 'POST',
          headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: new FormData(form)
        });
        const result = await response.json();
        if (quote) quote.textContent = (result.message || 'No preview is available.') + (response.ok ? ' Review the dates, then confirm to save.' : '');
        if (response.ok) form.dataset.seen = keyOf(form);
        else delete form.dataset.seen;
      } catch (error) {
        if (quote) quote.textContent = 'Could not check the block right now. Check your connection and try again.';
        delete form.dataset.seen;
      } finally {
        button.disabled = false;
      }
    });
    form.addEventListener('submit', event => {
      if (form.dataset.seen === keyOf(form)) return;
      event.preventDefault();
      button.click();
    });
  });
})();
</script>
@endpush