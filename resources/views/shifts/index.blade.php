@extends('layouts.app')
@section('title', 'Cashiering')
@section('crumbs') Finance / Cashiering @endsection
@section('content')
@if($required && !$current)<div class="alert alert-warn"><x-icon name="alert"/><div>This hotel requires an open shift before you can receive payments or settle POS bills.</div></div>@endif
<div class="grid g-2">
  <div class="card">
    @if($current)
      <div class="card-head"><h3>My open shift</h3><span class="badge badge-green">Open since {{ $current->opened_at->format('d M H:i') }}</span></div>
      <div class="card-body">
        <table class="table table-compact"><thead><tr><th>Payment mode</th><th class="num">Txns</th><th class="num">Received</th><th class="num">Refunded</th><th class="num">Net</th></tr></thead><tbody>
        @forelse($summary['modes'] as $name => $m)<tr><td>{{ $name }} @if($m['is_cash'])<span class="badge badge-green">cash</span>@endif</td><td class="num">{{ $m['count'] }}</td><td class="num">{{ money($m['in'], false) }}</td><td class="num">{{ money($m['out'], false) }}</td><td class="num bold">{{ money($m['net'], false) }}</td></tr>
        @empty<tr><td colspan="5" class="muted">No collections yet on this shift.</td></tr>@endforelse
        </tbody><tfoot><tr><td class="bold">Total</td><td class="num">{{ $summary['count'] }}</td><td class="num bold">{{ money($summary['total_in'], false) }}</td><td class="num">{{ money($summary['total_out'], false) }}</td><td class="num bold">{{ money($summary['total_in'] - $summary['total_out'], false) }}</td></tr></tfoot></table>
        <ul class="list mt-1">
          <li><span>Opening float</span><span>{{ money($current->opening_float) }}</span></li>
          <li><span>+ Cash received</span><span>{{ money($summary['cash_in']) }}</span></li>
          <li><span>− Cash refunded</span><span>{{ money($summary['cash_out']) }}</span></li>
          <li style="font-size:1.1rem"><b>Expected cash in drawer</b><b>{{ money($summary['expected_cash']) }}</b></li>
          @if(! empty($summary['forex']))<li id="shiftForex"><span>Foreign currency bought (hand over)<div class="tiny muted">cedis paid for it are in “cash refunded”</div></span><span>{{ collect($summary['forex'])->map(fn ($v, $k) => $k.' '.number_format($v, 2))->implode(' · ') }}</span></li>@endif
        </ul>
      </div>
      <div class="card-foot"><form method="post" action="{{ route('shifts.close', $current) }}" id="closeShiftForm" data-confirm="Close your shift? The report is final once closed.">@csrf
        <div class="flex gap-1 wrap items-end">
          <div class="field" style="flex:1;min-width:160px"><label>Counted cash <span class="req">*</span></label><input name="counted_cash" id="shiftCountedCash" type="number" step="0.01" min="0" required placeholder="Count the drawer"></div>
          <div class="field" style="flex:2;min-width:200px"><label>Handover notes</label><input name="notes" maxlength="250"></div>
          <button class="btn btn-warning">Close shift</button>
        </div>
        <details class="mt-1"><summary class="small bold">Count by denomination (optional)</summary>
          <div class="form-grid mt-1">
            @foreach(\App\Services\ShiftService::DENOMINATIONS as $denom)
              <div class="field"><label>{{ number_format($denom, $denom < 1 ? 1 : 0) }} ×</label><input type="number" min="0" max="100000" step="1" name="denominations[{{ $denom }}]" data-denom="{{ $denom }}" value="0" aria-label="{{ $denom }} denomination count"></div>
            @endforeach
          </div>
          <span class="hint">The denomination total fills Counted cash and is checked again before the shift is closed.</span>
        </details>
      </form></div>
    @else
      <div class="card-head"><h3>Open a shift</h3></div>
      <form method="post" action="{{ route('shifts.open') }}" class="card-body form-grid">@csrf
        <x-field name="opening_float" label="Opening cash float" type="number" step="0.01" min="0" value="0" required/>
        <x-field name="notes" label="Notes" placeholder="Counter / drawer no."/>
        <div class="span-all"><button class="btn btn-primary"><x-icon name="money"/> Open shift</button></div>
      </form>
    @endif
  </div>
  <div class="card">
    <div class="card-head"><h3>How it works</h3></div>
    <div class="card-body small text-2">
      <p>Every payment and refund you post on a folio, and every POS bill you settle, is added to your open shift automatically, grouped by payment mode.</p>
      <p class="mt-1">At the end of the shift, count the drawer and enter the amount. The system compares it with <b>float + cash received − cash refunded</b> and records any variance. The closed shift report is fixed and can be printed for handover.</p>
      @if($openShifts->isNotEmpty())<div class="alert alert-info mt-1"><x-icon name="users"/><div><b>Other open shifts:</b> @foreach($openShifts as $s)<a href="{{ route('shifts.show', $s) }}">{{ $s->user->name }}</a> (since {{ $s->opened_at->format('d M H:i') }})@if(!$loop->last), @endif @endforeach</div></div>@endif
    </div>
  </div>
</div>
@if($current)
<div class="grid g-2 mt-2">
  <div class="card"><div class="card-head"><h3><x-icon name="plus"/> Cash in</h3><span class="tiny muted">Add money to the drawer</span></div>
    <form method="post" action="{{ route('shifts.cash-in') }}" class="card-body form-grid">@csrf
      <x-field name="amount" label="Amount" type="number" step="0.01" min="0.01" required/>
      <x-field name="reason" label="Reason" required placeholder="Change received, float top-up…"/>
      <x-field name="reference" label="Reference" placeholder="Optional receipt / handover ref"/>
      <div class="span-all"><button class="btn btn-success"><x-icon name="plus"/> Record cash in</button></div>
    </form>
  </div>
  <div class="card"><div class="card-head"><h3><x-icon name="minus"/> Cash out</h3><span class="tiny muted">Remove money from the drawer</span></div>
    <form method="post" action="{{ route('shifts.cash-out') }}" class="card-body form-grid">@csrf
      <x-field name="amount" label="Amount" type="number" step="0.01" min="0.01" required/>
      <x-field name="reason" label="Reason" required placeholder="Petty cash, bank drop…"/>
      <x-field name="reference" label="Reference" placeholder="Optional voucher / slip ref"/>
      <div class="span-all"><button class="btn btn-warning"><x-icon name="minus"/> Record cash out</button></div>
    </form>
  </div>
</div>
<div class="card flush mt-2"><div class="card-head"><h3>Recent drawer movements</h3><span class="tiny muted">Cash in and out are included in expected cash</span></div>
  <div class="card-body table-wrap"><table class="table table-compact"><thead><tr><th>When</th><th>Type</th><th>Reason</th><th>Reference</th><th>By</th><th class="num">Amount</th></tr></thead><tbody>
    @forelse($movements as $movement)<tr><td class="small">{{ $movement->created_at->format('d M H:i') }}</td><td><span class="badge badge-{{ $movement->isIn() ? 'green' : 'amber' }}">{{ $movement->kindLabel() }}</span></td><td>{{ $movement->reason }}</td><td class="small">{{ $movement->reference ?: '—' }}</td><td class="small">{{ $movement->user?->name }}</td><td class="num {{ $movement->isIn() ? 'text-green' : 'text-red' }}">{{ $movement->isIn() ? '+' : '−' }}{{ money($movement->amount, false) }}</td></tr>
    @empty<tr><td colspan="6" class="muted">No manual drawer movements on this shift.</td></tr>@endforelse
  </tbody></table></div>
</div>
@endif
<div class="card flush mt-2"><div class="card-head"><h3>{{ $all ? 'All shifts' : 'My shifts' }}</h3>
  @if($all)<form method="get" class="filters"><div class="field"><select name="user" data-autosubmit><option value="">All cashiers</option>@foreach($users as $id => $n)<option value="{{ $id }}" @selected(request('user') == $id)>{{ $n }}</option>@endforeach</select></div></form>@endif</div>
<div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Cashier</th><th>Business date</th><th>Opened</th><th>Closed</th><th class="num">Float</th><th class="num">Expected</th><th class="num">Counted</th><th class="num">Variance</th><th></th></tr></thead><tbody>
  @forelse($history as $s)
    <tr><td>{{ $s->user->name }}</td><td>{{ dmy($s->business_date) }}</td><td class="small">{{ $s->opened_at->format('d M H:i') }}</td><td class="small">{!! $s->closed_at ? e($s->closed_at->format('d M H:i')) : '<span class="badge badge-green">Open</span>' !!}</td>
    <td class="num">{{ money($s->opening_float, false) }}</td><td class="num">{{ $s->expected_cash !== null ? money($s->expected_cash, false) : '—' }}</td><td class="num">{{ $s->counted_cash !== null ? money($s->counted_cash, false) : '—' }}</td>
    <td class="num bold {{ $s->variance === null ? '' : (abs($s->variance) < 0.01 ? 'text-green' : 'text-red') }}">{{ $s->variance !== null ? money($s->variance, false) : '—' }}</td>
    <td class="right"><a class="btn btn-sm" href="{{ route('shifts.show', $s) }}">Report</a></td></tr>
  @empty<tr><td colspan="9"><x-empty title="No shifts yet" text="Open a shift to start tracking your collections." icon="money"/></td></tr>@endforelse
  </tbody></table></div>
  @if($history->hasPages())<div class="card-foot">{{ $history->links() }}</div>@endif</div>
@push('scripts')
<script>
(() => {
  const form = document.getElementById('closeShiftForm');
  const counted = document.getElementById('shiftCountedCash');
  if (!form || !counted) return;
  const inputs = [...form.querySelectorAll('[data-denom]')];
  const sync = () => {
    const total = inputs.reduce((sum, input) => sum + (Number(input.dataset.denom) * Math.max(0, Number(input.value) || 0)), 0);
    if (inputs.some(input => Number(input.value) > 0)) counted.value = total.toFixed(2);
  };
  inputs.forEach(input => input.addEventListener('input', sync));
})();
</script>
@endpush
@endsection
