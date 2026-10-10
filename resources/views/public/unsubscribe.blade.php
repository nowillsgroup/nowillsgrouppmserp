@extends('public.layout')
@section('title', 'Communication preferences')
@section('content')
<div class="card" style="max-width:560px;margin:0 auto" id="prefCard">
  <div class="card-head"><h3>Your communication preferences</h3></div>
  @if(! $guest)
    <div class="card-body"><p class="muted m-0">This is a preview of the page guests reach from the unsubscribe link in your campaigns. Real links are personal to each guest.</p></div>
  @else
  <form method="post" action="{{ route('crm.unsubscribe.save', $token) }}">@csrf
    <div class="card-body">
      <p class="mt-0">Hello {{ $guest->first_name }}, choose which offers and news from <b>{{ $hotel->name }}</b> you would like to keep receiving.
        Messages about your own bookings (confirmations, receipts) are not affected.</p>
      @php $any = false; @endphp
      @foreach(\App\Services\CrmService::CHANNELS as $ch => $label)
        @if($guest->{$ch.'_opt_in'}) @php $any = true; @endphp
        <label class="check" style="display:flex;margin:.5rem 0"><input type="checkbox" name="keep[]" value="{{ $ch }}" checked> {{ $label }} offers
          <span class="muted small">({{ $ch === 'email' ? $guest->email : $guest->phone }})</span></label>
        @endif
      @endforeach
      @unless($any)<p class="muted" id="allOff">You are not subscribed to any marketing messages.</p>@endunless
    </div>
    @if($any)
    <div class="card-foot flex" style="justify-content:space-between">
      <button class="btn" name="action" value="save" id="savePrefs">Save preferences</button>
      <button class="btn btn-danger" name="action" value="all" id="unsubAll">Unsubscribe from everything</button>
    </div>
    @endif
  </form>
  @endif
</div>
@endsection
