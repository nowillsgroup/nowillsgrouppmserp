@extends('public.layout')
@section('title', 'Feedback')
@section('content')
<form method="post" action="{{ route('feedback.submit', $hotel->code) }}" class="card" style="max-width:640px;margin:0 auto">@csrf
<div class="card-head"><h3>How was your stay?</h3></div>
<div class="card-body form-grid">
  <x-field name="guest_name" label="Your name"/><x-field name="contact" label="Room no. / Email"/>
  @foreach(['rating_overall'=>'Overall experience','rating_room'=>'Room comfort','rating_service'=>'Staff & service','rating_food'=>'Food & beverage','rating_cleanliness'=>'Cleanliness'] as $k=>$l)
    <x-field :name="$k" :label="$l" type="select" :options="[5=>'★★★★★ Excellent',4=>'★★★★ Good',3=>'★★★ Average',2=>'★★ Poor',1=>'★ Very poor']" :required="$k==='rating_overall'"/>
  @endforeach
  <x-field name="comments" label="Comments" type="textarea" class="span-all"/>
</div>
<div class="card-foot"><button class="btn btn-primary">Submit feedback</button></div>
</form>
@endsection
