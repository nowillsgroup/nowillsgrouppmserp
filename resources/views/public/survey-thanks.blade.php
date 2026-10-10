@extends('public.layout')
@section('title', 'Thank you')
@section('content')
<div class="card" style="max-width:640px;margin:0 auto" id="surveyThanks"><div class="card-body center" style="padding:2.2rem 1.5rem">
  <div style="font-size:2.6rem">🙏</div>
  <h2 class="mt-1">Thank you{{ $inv->guest ? ', '.$inv->guest->first_name : '' }}!</h2>
  <p class="muted mt-1">{{ $inv->survey->thank_you ?: 'Your feedback has been received.' }}</p>
  @if($links)
    <div class="mt-2" id="reviewAsk"><p><b>We're so glad you enjoyed your stay.</b> Would you share your experience with other travellers? It helps a lot.</p>
      <div class="flex gap-1 mt-1" style="justify-content:center;flex-wrap:wrap">
        @foreach($links as $site => $url)<a class="btn btn-primary" href="{{ route('survey.review', [$token, $site]) }}" rel="noopener" target="_blank" id="review_{{ $site }}">Review us on {{ ['google' => 'Google', 'tripadvisor' => 'TripAdvisor'][$site] }}</a>@endforeach
      </div></div>
  @elseif($fb && $fb->nps_group === 'detractor' || ($fb && $fb->sentiment === 'negative'))
    <p class="mt-2" id="sorryNote">We're sorry your stay didn't meet your expectations. A manager will review your comments personally @if($inv->guest?->email) and may contact you at {{ \Illuminate\Support\Str::mask($inv->guest->email, '*', 2, max(0, strpos($inv->guest->email, '@') - 2)) }}@endif.</p>
  @endif
</div></div>
@endsection
