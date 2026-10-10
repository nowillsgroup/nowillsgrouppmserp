@php $fa = $alerts; @endphp
@if($fa['rates_missing'] || $fa['carried'] || $fa['surrender_overdue'])
<div class="alert alert-warn" id="fxAlerts"><x-icon name="alert"/><div class="flex" style="gap:.9rem;flex-wrap:wrap">
  @if($fa['rates_missing'])<a href="{{ route('fg.rates') }}" class="text-red" id="alFxRates"><b>{{ $fa['rates_missing'] }}</b> currenc{{ $fa['rates_missing'] === 1 ? 'y has' : 'ies have' }} no rate for {{ bdate()->format('d M') }}</a>@endif
  @if($fa['carried'])<a href="{{ route('fg.rates') }}" id="alFxCarried"><b>{{ $fa['carried'] }}</b> rate(s) carried from yesterday – review against the bank</a>@endif
  @if($fa['surrender_overdue'])<a href="{{ route('fg.position') }}" class="text-red" id="alFxSurrender"><b>{{ $fa['surrender_overdue'] }}</b> currenc{{ $fa['surrender_overdue'] === 1 ? 'y' : 'ies' }} held over {{ $fa['surrender_days'] }} days – surrender to the bank</a>@endif
</div></div>
@endif
