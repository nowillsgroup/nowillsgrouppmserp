@extends('partner.layout')
@section('title', 'Book a stay')
@section('crumbs') Partner portal / Book @endsection
@section('content')
@if($errors->has('book'))<div class="alert alert-error" id="ptnBookError"><x-icon name="alert"/><div>{{ $errors->first('book') }}</div></div>@endif
<div class="card mb-2"><div class="card-body">
  <form method="get" action="{{ route('partner.book') }}" class="ptn-search" id="ptnSearch">
    <div class="field"><label for="arrival">Arrival</label><input class="input" type="date" id="arrival" name="arrival" value="{{ $q['arrival'] }}" min="{{ bdate()->toDateString() }}" required></div>
    <div class="field"><label for="departure">Departure</label><input class="input" type="date" id="departure" name="departure" value="{{ $q['departure'] }}" required></div>
    <div class="field"><label for="rooms">Rooms</label><input class="input" type="number" id="rooms" name="rooms" value="{{ $q['rooms'] }}" min="1" max="{{ $company->portal_max_rooms }}"></div>
    <div class="field"><label for="adults">Adults (total)</label><input class="input" type="number" id="adults" name="adults" value="{{ $q['adults'] }}" min="1" max="{{ 4 * $company->portal_max_rooms }}"></div>
    <button class="btn btn-primary" type="submit"><x-icon name="search"/> Check rates</button>
  </form>
  <p class="tiny muted mt-1 m-0">Up to {{ $company->portal_max_rooms }} rooms per booking · for more, <a href="#group">request a group quote</a>.</p>
</div></div>

@if($error)<div class="alert alert-warn" id="ptnSearchError"><x-icon name="alert"/><div>{{ $error }}</div></div>@endif

@if($result)
  <div class="flex justify-between items-center mb-1"><h3 class="m-0">{{ $result['from']->format('D d M') }} – {{ $result['to']->format('D d M Y') }} · {{ $result['nights'] }} night(s) · {{ $result['rooms'] }} room(s)</h3>
    <span class="small muted">{{ $result['contract'] ? 'Contract '.$result['contract']->code.' rates' : ((float) $company->discount_pct > 0 ? 'Corporate discount applied' : 'Best available rates') }} · per room, taxes extra</span></div>
  <div class="ptn-rooms" id="ptnResults">
  @foreach($result['rows'] as $row)
    @php $qq = $row['quote']; $sel = $pick && $pick['type']->id === $row['type']->id; @endphp
    <div class="ptn-room {{ $row['why'] ? 'off' : '' }} {{ $sel ? 'sel' : '' }}" data-type="{{ $row['type']->id }}">
      <div>
        <h3>{{ $row['type']->name }}</h3>
        <div class="small muted">{{ \Illuminate\Support\Str::limit($row['type']->description, 140) }}</div>
        <div class="small mt-1"><span class="badge badge-blue">{{ $qq['label'] }}</span> @if($qq['meal_plan'])<span class="badge badge-slate">{{ $qq['meal_plan'] }}</span>@endif
          @if($row['why'])<span class="badge badge-red">{{ $row['why'] }}</span>@elseif($row['available'] <= 3)<span class="badge badge-amber">{{ $row['available'] }} left</span>@else<span class="badge badge-green">Available</span>@endif</div>
        @if($sel)<div class="ptn-nights">@foreach($qq['nights'] as $n)<span>{{ \Carbon\Carbon::parse($n['date'])->format('D d M') }} · {{ money($n['rate']) }}</span>@endforeach</div>@endif
      </div>
      <div class="ptn-price">
        <div class="big">{{ money($qq['average']) }}</div><div class="tiny muted">avg / night per room</div>
        @if($qq['saving'] > 0)<s>{{ money($qq['public_total'] * $result['rooms']) }}</s> <span class="ptn-save">save {{ money($qq['saving'] * $result['rooms']) }}</span>@endif
        <div class="small"><b>{{ money($row['total']) }}</b> total</div>
        @if(! $row['why'] && ! $sel)<a class="btn btn-primary btn-sm mt-1" href="{{ route('partner.book', $q + ['type' => $row['type']->id]) }}#book">Select</a>@endif
      </div>
    </div>
  @endforeach
  </div>

  @if($pick)
  @php $pq = $pick['quote']; @endphp
  <div class="card mt-2" id="book"><div class="card-head"><h3><x-icon name="user"/> Traveller &amp; billing – {{ $result['rooms'] }} × {{ $pick['type']->name }}, {{ money($pick['total']) }}</h3></div><div class="card-body">
    <form method="post" action="{{ route('partner.book.store') }}" id="ptnBookForm">
      @csrf
      <input type="hidden" name="room_type_id" value="{{ $pick['type']->id }}"><input type="hidden" name="arrival" value="{{ $result['from']->toDateString() }}">
      <input type="hidden" name="departure" value="{{ $result['to']->toDateString() }}"><input type="hidden" name="rooms" value="{{ $result['rooms'] }}"><input type="hidden" name="adults" value="{{ $result['adults'] }}">
      <div class="g-4">
        <div class="field"><label for="first_name">First name</label><input class="input" id="first_name" name="first_name" value="{{ old('first_name') }}" required maxlength="80"></div>
        <div class="field"><label for="last_name">Last name</label><input class="input" id="last_name" name="last_name" value="{{ old('last_name') }}" required maxlength="80"></div>
        <div class="field"><label for="gemail">Traveller e-mail <span class="muted">(confirmation)</span></label><input class="input" type="email" id="gemail" name="email" value="{{ old('email') }}" maxlength="190"></div>
        <div class="field"><label for="gphone">Mobile</label><input class="input" id="gphone" name="phone" value="{{ old('phone') }}" maxlength="30"></div>
      </div>
      <div class="field"><label>Who pays?</label>
        <div class="ptn-radio" id="ptnBillTo">
          @if($company->direct_billing)<label><input type="radio" name="bill_to" value="company" @checked(old('bill_to', 'company') === 'company')><span><b>Bill to {{ $company->name }}</b><br><span class="tiny muted">Room &amp; tax invoiced to your account ({{ $company->credit_days }} days credit); extras paid by the traveller.</span></span></label>@endif
          @if($company->portal_guest_pay)<label><input type="radio" name="bill_to" value="guest" @checked(old('bill_to', $company->direct_billing ? 'company' : 'guest') === 'guest')><span><b>Traveller pays at the hotel</b><br><span class="tiny muted">Card / mobile money / cash at check-out.</span></span></label>@endif
        </div>
      </div>
      <div class="g-2">
        <div class="field"><label for="partner_ref">{{ $company->type === 'travel_agent' ? 'Your file / voucher reference' : 'PO number / cost centre' }}</label><input class="input" id="partner_ref" name="partner_ref" value="{{ old('partner_ref') }}" maxlength="60"></div>
        <div class="field"><label for="special_request">Special requests</label><input class="input" id="special_request" name="special_request" value="{{ old('special_request') }}" maxlength="500" placeholder="Late arrival, airport pick-up, quiet room…"></div>
      </div>
      <div class="alert alert-info small"><x-icon name="shield"/><div>
        @if($pq['cancel_days'] !== null)Free cancellation until {{ $pq['cancel_days'] }} day(s) before arrival under contract {{ $pq['contract'] }}.@else Free cancellation until {{ setting('cancel_free_hours', 48) }} hours before arrival.@endif
        Later cancellations are charged per the hotel policy. Bookings above your approval threshold{{ $company->direct_billing ? ' or your credit limit' : '' }} are held until the hotel confirms.</div></div>
      <button class="btn btn-primary btn-lg" type="submit" id="ptnConfirm"><x-icon name="check"/> Confirm booking · {{ money($pick['total']) }}</button>
    </form>
  </div></div>
  @endif
@endif

<details class="card mt-2" id="group"><summary class="card-head" style="cursor:pointer"><h3><x-icon name="users"/> Group request (more than {{ $company->portal_max_rooms }} rooms)</h3></summary><div class="card-body">
  @if($errors->has('group'))<div class="alert alert-error"><x-icon name="alert"/><div>{{ $errors->first('group') }}</div></div>@endif
  <form method="post" action="{{ route('partner.requests.group') }}" id="ptnGroupForm">
    @csrf
    <div class="g-4">
      <div class="field"><label>Arrival</label><input class="input" type="date" name="arrival" value="{{ old('arrival', $q['arrival']) }}" required></div>
      <div class="field"><label>Departure</label><input class="input" type="date" name="departure" value="{{ old('departure', $q['departure']) }}" required></div>
      <div class="field"><label>Rooms</label><input class="input" type="number" name="rooms" value="{{ old('rooms', 10) }}" min="2" max="200" required></div>
      <div class="field"><label>Group name</label><input class="input" name="name" value="{{ old('name') }}" maxlength="100"></div>
    </div>
    <div class="field"><label>Details</label><textarea class="input" name="details" rows="2" maxlength="2000" placeholder="Room mix, meeting room, meals, budget…">{{ old('details') }}</textarea></div>
    <button class="btn" type="submit"><x-icon name="mail"/> Send group request</button>
  </form>
</div></details>
@endsection
