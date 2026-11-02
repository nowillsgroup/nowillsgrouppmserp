@php $activityRows = $activities ?? collect(); @endphp
@if($activityRows->isNotEmpty())
  <section class="shift-session-activity" aria-label="Recent activity recorded during this shift">
    <div class="shift-session-activity-head"><h3>Recent activity during this shift</h3><span>Latest {{ $activityRows->count() }}</span></div>
    <div class="shift-session-activity-list">
      @foreach($activityRows as $event)
        @php $moduleLabel = ['logbook' => 'Shift log book', 'wakeup' => 'Wake-up calls', 'messages' => 'Messages & parcels', 'traces' => 'Traces', 'lostfound' => 'Lost & found', 'frontdesk' => 'Front desk'][$event->module] ?? ucfirst($event->module); @endphp
        <article class="shift-session-activity-row">
          <time datetime="{{ $event->created_at->toIso8601String() }}">{{ $event->created_at->format('H:i') }}</time>
          <div><b>{{ $moduleLabel }} · {{ ucfirst(str_replace('_', ' ', $event->action)) }}</b><p>{{ $event->description }}</p></div>
        </article>
      @endforeach
    </div>
    <p class="shift-session-activity-foot">Only activity in modules your role can view is shown.</p>
  </section>
@endif
