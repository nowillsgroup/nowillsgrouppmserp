@extends('public.layout')
@section('title', $survey->name)
@section('content')
<style>
.sv-q{padding:1.1rem 0;border-bottom:1px solid var(--line,#e2e8f0)}.sv-q:last-child{border-bottom:0}
.sv-label{font-weight:600;margin-bottom:.6rem;display:block}.sv-req{color:#dc2626}
.sv-nps{display:grid;grid-template-columns:repeat(11,1fr);gap:.3rem}.sv-nps input,.sv-pills input,.sv-stars input{position:absolute;opacity:0;pointer-events:none}
.sv-nps label{display:block;text-align:center;padding:.55rem 0;border:1px solid #cbd5e1;border-radius:8px;cursor:pointer;font-weight:600;background:#fff;user-select:none}
.sv-nps input:checked+label{color:#fff;border-color:transparent}.sv-nps .d input:checked+label{background:#dc2626}.sv-nps .p input:checked+label{background:#d97706}.sv-nps .h input:checked+label{background:#16a34a}
.sv-nps input:focus-visible+label,.sv-pills input:focus-visible+label{outline:2px solid #1d4ed8;outline-offset:2px}
.sv-scale{display:flex;justify-content:space-between;font-size:.78rem;color:#64748b;margin-top:.3rem}
.sv-stars{display:inline-flex;flex-direction:row-reverse;gap:.15rem}.sv-stars label{font-size:2rem;line-height:1;color:#cbd5e1;cursor:pointer;padding:0 .1rem}
.sv-stars label:hover,.sv-stars label:hover~label,.sv-stars input:checked~label{color:#f59e0b}.sv-stars input:focus-visible+label{outline:2px solid #1d4ed8}
.sv-pills{display:flex;flex-wrap:wrap;gap:.4rem}.sv-pills label{padding:.45rem 1rem;border:1px solid #cbd5e1;border-radius:999px;cursor:pointer;background:#fff}
.sv-pills input:checked+label{background:#1d4ed8;color:#fff;border-color:#1d4ed8}
@media(max-width:560px){.sv-nps{grid-template-columns:repeat(6,1fr)}}
</style>
@if($preview)<div class="alert alert-warn"><x-icon name="eye"/><div>Preview – this is what guests see. Answers can't be submitted from here.</div></div>@endif
<form method="post" action="{{ $preview ? '#' : route('survey.submit', $token) }}" class="card" style="max-width:720px;margin:0 auto" id="surveyForm">@csrf
<div class="card-head"><h3>{{ $survey->name }}</h3></div>
<div class="card-body">
  <p class="muted">@if($inv?->guest)Dear {{ $inv->guest->first_name }}, @endif{{ $survey->intro }}</p>
  @foreach($questions as $q)
    @php $n = 'q'.$q->id; $old = old($n, $q->type === 'nps' && $preNps !== null ? $preNps : null); @endphp
    <div class="sv-q" id="sq_{{ $q->id }}">
      <span class="sv-label">{{ $q->label }} @if($q->required)<span class="sv-req" title="Required">*</span>@endif</span>
      @switch($q->type)
        @case('nps')
          <div class="sv-nps" role="radiogroup">@for($i = 0; $i <= 10; $i++)<div class="{{ $i <= 6 ? 'd' : ($i <= 8 ? 'p' : 'h') }}"><input type="radio" name="{{ $n }}" id="{{ $n }}_{{ $i }}" value="{{ $i }}" @checked((string) $old === (string) $i)><label for="{{ $n }}_{{ $i }}">{{ $i }}</label></div>@endfor</div>
          <div class="sv-scale"><span>Not at all likely</span><span>Extremely likely</span></div>
          @break
        @case('rating')
          <div class="sv-stars" role="radiogroup">@for($i = 5; $i >= 1; $i--)<input type="radio" name="{{ $n }}" id="{{ $n }}_{{ $i }}" value="{{ $i }}" @checked((string) $old === (string) $i)><label for="{{ $n }}_{{ $i }}" title="{{ [1 => 'Very poor', 2 => 'Poor', 3 => 'Average', 4 => 'Good', 5 => 'Excellent'][$i] }}">★</label>@endfor</div>
          @break
        @case('yesno')
          <div class="sv-pills">@foreach([1 => 'Yes', 0 => 'No'] as $v => $l)<input type="radio" name="{{ $n }}" id="{{ $n }}_{{ $v }}" value="{{ $v }}" @checked((string) $old === (string) $v)><label for="{{ $n }}_{{ $v }}">{{ $l }}</label>@endforeach</div>
          @break
        @case('choice')
          <div class="sv-pills">@foreach($q->options ?? [] as $k => $o)<input type="radio" name="{{ $n }}" id="{{ $n }}_{{ $k }}" value="{{ str_replace(',', '', $o) }}" @checked($old === str_replace(',', '', $o))><label for="{{ $n }}_{{ $k }}">{{ $o }}</label>@endforeach</div>
          @break
        @default
          <textarea name="{{ $n }}" id="{{ $n }}" rows="4" maxlength="2000" class="input" style="width:100%" placeholder="Your comments">{{ $old }}</textarea>
      @endswitch
      @error($n)<div class="err">{{ $message }}</div>@enderror
    </div>
  @endforeach
  <label class="check mt-2"><input type="checkbox" name="consent_publish" value="1" @checked(old('consent_publish'))> You may quote my comments (first name only) on the hotel's website.</label>
</div>
<div class="card-foot"><button class="btn btn-primary" id="submitSurvey" @disabled($preview)>Send my feedback</button></div>
</form>
@endsection
