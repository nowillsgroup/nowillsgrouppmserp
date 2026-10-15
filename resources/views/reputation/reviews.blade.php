@extends('layouts.app')
@section('title', 'Online Reviews')
@section('crumbs') Guests &amp; CRM / Online reviews @endsection
@section('actions')
@if(can_do('reports.view'))<a class="btn" href="{{ route('reports.show', 'online_reviews') }}"><x-icon name="chart"/> Review register</a>@endif
@if(can_do('feedback.manage'))
<a class="btn" href="#" data-modal="webhookModal" id="webhookBtn"><x-icon name="globe"/> Review webhook</a>
<form method="post" action="{{ route('rep.reviews.pull') }}" class="inline" data-confirm="Pull new reviews from the configured platform feeds (Booking.com / Google / TripAdvisor …)?">@csrf<button class="btn" id="pullFeedBtn"><x-icon name="refresh"/> Pull from feeds</button></form>
<a class="btn" href="#" data-modal="importModal" id="importBtn"><x-icon name="download"/> Import CSV</a>
<a class="btn btn-primary" href="#" data-modal="addReview" id="addReviewBtn"><x-icon name="plus"/> Add review</a>
@endif
@endsection
@section('content')
@include('reputation._nav')
<div class="grid g-4 mb-2" id="sourceCards">
@forelse($sources as $s)
  <div class="card" style="margin:0"><div class="card-body">
    <div class="small muted">{{ \App\Models\OnlineReview::SOURCES[$s->source] ?? $s->source }}</div>
    <div class="flex justify-between" style="align-items:baseline"><b style="font-size:1.5rem">{{ number_format($s->a, 1) }}</b>{!! stars($s->a) !!}</div>
    <div class="tiny muted">{{ $s->n }} reviews · latest {{ \Illuminate\Support\Carbon::parse($s->last)->format('d M Y') }}</div>
  </div></div>
@empty
  <div class="card span-all"><div class="card-body"><x-empty text="No online reviews yet – import a CSV export or add reviews by hand." icon="globe"/></div></div>
@endforelse
</div>
<div class="card flush">
  <div class="card-head"><div class="tabs" style="margin:0;border:0">
    @foreach(\App\Http\Controllers\ReviewController::TABS as $k => $l)<a href="{{ route('rep.reviews.index', array_filter(['tab' => $k, 'source' => request('source'), 'stars' => request('stars')])) }}" class="{{ $tab === $k ? 'active' : '' }}">{{ $l }} <span class="muted">{{ $counts[$k] }}</span></a>@endforeach
  </div>
  <form class="filters" style="margin:0"><input type="hidden" name="tab" value="{{ $tab }}">
    <select name="source" onchange="this.form.submit()"><option value="">All sites</option>@foreach(\App\Models\OnlineReview::SOURCES as $k => $l)<option value="{{ $k }}" @selected(request('source') === $k)>{{ $l }}</option>@endforeach</select>
    <select name="stars" onchange="this.form.submit()"><option value="">Any rating</option><option value="low" @selected(request('stars') === 'low')>3★ and below</option><option value="high" @selected(request('stars') === 'high')>4★ and above</option></select>
    <input name="q" value="{{ request('q') }}" placeholder="Reviewer or text"></form></div>
  <div class="card-body table-wrap"><table class="table" id="reviewTable">
    <thead><tr><th>Date</th><th>Site</th><th>Reviewer</th><th>Rating</th><th>Review</th><th>Sentiment</th><th>Status</th></tr></thead><tbody>
    @forelse($items as $r)
    <tr class="{{ in_array($r->status, ['new', 'draft']) && $r->created_at->lt(now()->subHours(48)) ? 'row-alert' : '' }}">
      <td class="small nowrap">{{ $r->review_date->format('d M Y') }}</td><td class="small">{{ $r->sourceName() }}</td>
      <td class="small"><b>{{ $r->reviewer_name }}</b>@if($r->guest)<div class="tiny"><a href="{{ route('guests.show', $r->guest) }}">stayed · G-{{ $r->guest_id }}</a></div>@elseif($r->reviewer_location)<div class="tiny muted">{{ $r->reviewer_location }}</div>@endif</td>
      <td class="nowrap">{!! stars($r->rating5) !!}<div class="tiny muted">{{ rtrim(rtrim(number_format((float) $r->rating, 1), '0'), '.') }} / {{ $r->rating_scale }}</div></td>
      <td class="small" style="max-width:420px"><a href="{{ route('rep.reviews.show', $r) }}" class="bold">{{ $r->title ?: \Illuminate\Support\Str::limit($r->body, 60) }}</a><div class="muted">{{ \Illuminate\Support\Str::limit($r->body, 130) }}</div></td>
      <td>{!! sentiment_badge($r->sentiment) !!}@if($r->recoveryCase)<div class="tiny mt-1">{{ $r->recoveryCase->case_no }}</div>@endif</td>
      <td>{!! status_badge($r->status) !!}<div class="tiny muted">{{ \App\Models\OnlineReview::STATUSES[$r->status] }}</div></td></tr>
    @empty<tr><td colspan="7"><x-empty text="Nothing here." icon="check"/></td></tr>@endforelse
  </tbody></table>{{ $items->links() }}</div>
</div>

@if(can_do('feedback.manage'))
<div class="modal" id="importModal"><div class="modal-box"><form method="post" action="{{ route('rep.reviews.import') }}" enctype="multipart/form-data">@csrf
  <div class="modal-head"><h3>Import reviews (CSV)</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <x-field name="source" label="Site (when the file has no source column)" type="select" :options="\App\Models\OnlineReview::SOURCES" required/>
    <div class="field mt-1"><label for="f_file">CSV file</label><input type="file" name="file" id="f_file" accept=".csv,text/csv" required></div>
    <p class="tiny muted mt-1">Header row with any of: <code>source, review_id, reviewer, location, date, rating, scale, title, review, url, trip_type</code>. Required: reviewer, date, rating, review. Booking.com ratings are read out of 10 unless a scale column says otherwise. Reviews already in the inbox are skipped.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="doImport">Import</button></div>
</form></div></div>
<div class="modal" id="addReview"><div class="modal-box lg"><form method="post" action="{{ route('rep.reviews.store') }}">@csrf
  <div class="modal-head"><h3>Add an online review</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <x-field name="source" label="Site" type="select" :options="\App\Models\OnlineReview::SOURCES" required/>
    <x-field name="review_date" label="Review date" type="date" :value="now()->toDateString()" required/>
    <x-field name="reviewer_name" label="Reviewer" required/>
    <x-field name="reviewer_location" label="Reviewer location"/>
    <x-field name="rating" label="Rating" type="number" required/>
    <x-field name="rating_scale" label="Out of" type="select" :options="[5 => '5', 10 => '10']" required/>
    <x-field name="trip_type" label="Trip type" type="select" :options="['' => '—'] + \App\Models\OnlineReview::TRIP_TYPES"/>
    <x-field name="external_id" label="Review ID on the site" hint="prevents duplicates"/>
    <x-field name="title" label="Title" class="span-all"/>
    <x-field name="body" label="Review text" type="textarea" required class="span-all"/>
    <x-field name="url" label="Link to the review" class="span-all" placeholder="https://"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary" id="saveReview">Add review</button></div>
</form></div></div>
@endif
@endsection


<div class="modal" id="webhookModal"><div class="modal-box">
  <div class="modal-head"><h3>Review webhook</h3><button type="button" class="btn btn-sm" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="muted">Platforms (or your own extractors) can push reviews straight into this inbox. The endpoint is stateless — the token identifies this property.</p>
    <div class="x-field"><label>Endpoint URL</label>
      <input readonly value="{{ url('/api/reviews/'.\App\Services\ReviewService::webhookToken()) }}" onclick="this.select()" id="webhookUrl">
    </div>
    <p class="small muted">POST JSON — one review or a batch:</p>
    <pre class="small" style="white-space:pre-wrap;background:#0f172a0d;padding:8px;border-radius:6px">{{ '{"reviews": [{"source": "google", "external_id": "G-1001", "reviewer_name": "Ama Owusu", "review_date": "2026-10-12", "rating": 5, "rating_scale": 5, "title": "Great stay", "body": "…", "url": "https://…"}]}' }}</pre>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button></div>
</div></div>
