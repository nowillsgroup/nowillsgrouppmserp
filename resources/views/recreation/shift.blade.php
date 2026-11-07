@extends('layouts.app')
@section('title', 'Outlet Shift')
@section('crumbs') <a href="{{ route('recreation.outlet') }}">Recreation outlet</a> / Shift @endsection
@section('actions')
<a class="btn" href="{{ route('recreation.outlet') }}"><x-icon name="receipt"/> Back to the till</a>
<a class="btn" href="{{ route('recreation.gate') }}"><x-icon name="check"/> Gate check</a>
<a class="btn" href="{{ route('recreation.day') }}"><x-icon name="chart"/> Day report</a>
@endsection
@section('content')
<div class="stats">
  <x-stat label="Sales on this shift" :value="$summary['sales'] ?? 0" icon="receipt" tone="blue" :sub="($summary['items'] ?? 0).' item(s) · '.($summary['tickets'] ?? 0).' ticket(s)'"/>
  <x-stat label="Takings" :value="money($summary['total'] ?? 0)" icon="money" tone="green" :sub="'net '.money($summary['net'] ?? 0).' + tax '.money($summary['tax'] ?? 0)"/>
  <x-stat label="Expected in the drawer" :value="money($summary['expected_cash'] ?? 0)" icon="wallet" tone="amber" :sub="'float '.money($summary['opening_float'] ?? 0).' + cash '.money($summary['cash_in'] ?? 0).' − change '.money($summary['change'] ?? 0)"/>
  <x-stat label="Charged to rooms" :value="money($summary['room_total'] ?? 0)" icon="bed" tone="purple" sub="settled on the guest folio"/>
</div>

<div class="grid g-2-1">
@if($shift)
  <div class="card" id="shiftOpen">
    <div class="card-head"><h3>Shift in progress</h3><span class="badge badge-green">Open {{ $shift->opened_at->format('d M H:i') }}</span></div>
    @if((int) $shift->user_id !== (int) auth()->id())
      <div class="card-body" style="padding-bottom:0"><p class="small text-amber m-0">You are closing <b>{{ $shift->user?->name }}</b>'s shift as the duty manager. The count and variance are recorded against it exactly as if the cashier had closed it.</p></div>
    @endif
    <div class="card-body">
      <p class="small m-0 mb-1">Opened by <b>{{ $shift->user?->name ?? auth()->user()->name }}</b> on the {{ $shift->shift }} shift{{ $shift->opening_note ? ' — “'.$shift->opening_note.'”' : '' }}.
        This is the outlet's X report: it moves as you sell and closes with the cash count.</p>

      <div class="table-wrap"><table class="table table-compact" id="shiftTenders">
        <thead><tr><th>Tender</th><th class="num">Rows</th><th class="num">Taken</th></tr></thead>
        <tbody>
        @forelse($summary['tenders'] as $name => $row)
          <tr><td>{{ $name }}@if($row['is_cash']) <span class="badge badge-green">drawer</span>@endif</td><td class="num">{{ $row['count'] }}</td><td class="num">{{ money($row['amount'], false) }}</td></tr>
        @empty<tr><td colspan="3" class="muted center">Nothing taken on this shift yet.</td></tr>@endforelse
        </tbody>
        <tfoot><tr><td class="right bold" colspan="2">Collections</td><td class="num bold">{{ money($summary['total']) }}</td></tr></tfoot>
      </table></div>

      <div class="kv-grid mt-2">
        <div><span>Net revenue</span><b>{{ money($summary['net']) }}</b></div>
        <div><span>Tax (VAT, NHIL, GETFund, levy)</span><b>{{ money($summary['tax']) }}</b></div>
        <div><span>Cash taken</span><b>{{ money($summary['cash_in']) }}</b></div>
        <div><span>Change given</span><b>{{ money($summary['change']) }}</b></div>
        <div><span>Voids</span><b>{{ $summary['void_sales'] }} · {{ money($summary['void_total']) }}</b></div>
        <div><span>Opening float</span><b>{{ money($summary['opening_float']) }}</b></div>
      </div>

      <form method="post" action="{{ route('recreation.shift.close') }}" class="mt-2" id="closeShiftForm">@csrf
        @if((int) $shift->user_id !== (int) auth()->id())<input type="hidden" name="shift_id" value="{{ $shift->id }}">@endif
        <h4 class="m-0 mb-1">Count the drawer</h4>
        <div class="table-wrap"><table class="table table-compact" id="denomTable">
          <thead><tr><th>Note / coin</th><th class="num">Count</th><th class="num">Value</th></tr></thead>
          <tbody>
          @foreach($denominations as $d)
            <tr><td>{{ money($d, false) }}</td>
              <td class="num"><input type="number" class="input input-sm" style="width:84px;text-align:right" name="denominations[{{ $d }}]" data-denom="{{ $d }}" min="0" max="9999" step="1" placeholder="0"></td>
              <td class="num" data-denom-value="{{ $d }}">0.00</td></tr>
          @endforeach
          </tbody>
          <tfoot><tr><td class="right bold" colspan="2">Counted</td><td class="num bold" id="denomTotal">0.00</td></tr></tfoot>
        </table></div>
        <div class="form-grid mt-2">
          <div class="field"><label for="counted_cash">Cash counted</label><input id="counted_cash" name="counted_cash" type="number" min="0" step="0.01" class="input" required value="{{ number_format((float) ($summary['expected_cash'] ?? 0), 2, '.', '') }}"></div>
          <div class="field"><label>Against the till</label><div class="bold" id="variancePreview">—</div><span class="tiny muted">Expected {{ money($summary['expected_cash']) }}</span></div>
          <div class="field span-all"><label for="note">Hand-over note</label><input id="note" name="note" class="input" maxlength="1000" placeholder="e.g. 50 cedi note left for change tomorrow"></div>
        </div>
        <div class="flex gap-1 mt-2" style="flex-wrap:wrap">
          <button class="btn btn-primary" name="print" value="0" data-confirm="Close this outlet shift and record the cash count?"><x-icon name="check"/> Close shift</button>
          <button class="btn" name="print" value="1" data-confirm="Close the shift, record the count and open the Z report to print?"><x-icon name="print"/> Close &amp; print the Z</button>
        </div>
      </form>
    </div>
  </div>

  <div>
    <div class="card"><div class="card-head"><h3>Open a shift</h3></div><div class="card-body small muted">A shift is already open. Close it before opening another — one drawer, one shift at a time.
      @if((int) $shift->user_id === (int) auth()->id())<div class="mt-1">Every ticket, rental and service you ring up at the till is tied to this shift, so keep it open until you hand over.</div>@endif</div></div>
</div>
@else
  <div class="card" id="shiftClosed">
    <div class="card-head"><h3>Open your outlet shift</h3></div>
    <div class="card-body">
      <p class="small m-0 mb-1">Count the float you are starting with. Every ticket, rental and service you sell is linked to this shift, and the close-out reconciles the drawer against it.</p>
      <form method="post" action="{{ route('recreation.shift.open') }}" class="form-grid">@csrf
        <div class="field"><label for="opening_float">Opening float</label><input id="opening_float" name="opening_float" type="number" min="0" step="0.01" class="input" required value="0.00"></div>
        <div class="field"><label for="open_note">Note (optional)</label><input id="open_note" name="note" class="input" maxlength="500" placeholder="e.g. float from the front office safe"></div>
        <div class="field span-all"><button class="btn btn-primary"><x-icon name="wallet"/> Open shift</button></div>
      </form>
    </div>
  </div>

  <div>
    <div class="card"><div class="card-head"><h3>Today so far</h3></div><div class="card-body">
      <div class="kv-grid">
        <div><span>Sales</span><b>{{ $dayTotal['sales'] }}</b></div>
        <div><span>Takings</span><b>{{ money($dayTotal['total']) }}</b></div>
        <div><span>Cash taken</span><b>{{ money($dayTotal['cash_in']) }}</b></div>
        <div><span>Voids</span><b>{{ $dayTotal['void_sales'] }}</b></div>
      </div>
      <p class="tiny muted m-0 mt-1">The day report reconciles every shift on {{ dmy($dayTotal['date']) }}.</p>
    </div></div>
  </div>
@endif
</div>

@if(can_do('club.manage'))
<div class="card mt-2" id="counterSettings">
  <div class="card-head"><h3>Counter settings</h3><span class="small muted">applies to every cashier at this outlet</span></div>
  <div class="card-body">
    <form method="post" action="{{ route('recreation.settings') }}">@csrf
      <label class="check" style="display:flex;gap:.5rem;align-items:flex-start">
        <input type="checkbox" name="recreation_shift_required" value="1" @checked($shiftRequired)>
        <span><b>Require an open shift before taking money</b>
          <span class="tiny muted" style="display:block">On: the till refuses a sale unless the cashier has opened a shift, so every cedi is tied to a drawer. Off: sales still record who rang them up, but no drawer is reconciled.</span></span>
      </label>
      <button class="btn btn-sm btn-primary mt-1">Save counter settings</button>
    </form>
  </div>
</div>
@endif

<div class="card flush mt-2">
  <div class="card-head"><h3>Recent outlet shifts</h3><a class="small" href="{{ route('recreation.day') }}">Day report →</a></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="shiftHistory">
    <thead><tr><th>Opened</th><th>Cashier</th><th>Shift</th><th class="num">Sales</th><th class="num">Takings</th><th class="num">Expected</th><th class="num">Counted</th><th class="num">Variance</th><th>Status</th></tr></thead>
    <tbody>
    @forelse($recent as $s)
      <tr class="{{ $s->status === 'open' ? 'row-amber' : '' }}">
        <td class="nowrap">{{ $s->opened_at->format('d M H:i') }}@if($s->closed_at)<div class="tiny muted">closed {{ $s->closed_at->format('H:i') }}</div>@endif</td>
        <td>{{ $s->user?->name ?? '—' }}</td>
        <td class="small">{{ $s->shift }}</td>
        <td class="num">{{ ($s->close_snapshot ?: ($live[$s->id] ?? []))['sales'] ?? '—' }}</td>
        <td class="num">{{ isset(($s->close_snapshot ?: ($live[$s->id] ?? []))['total']) ? money(($s->close_snapshot ?: ($live[$s->id] ?? []))['total'], false) : '—' }}</td>
        <td class="num">{{ $s->expected_cash !== null ? money($s->expected_cash, false) : '—' }}</td>
        <td class="num">{{ $s->counted_cash !== null ? money($s->counted_cash, false) : '—' }}</td>
        <td class="num {{ $s->variance !== null && abs((float) $s->variance) > 0.009 ? ((float) $s->variance < 0 ? 'text-red' : 'text-amber') : '' }}">{{ $s->variance !== null ? money($s->variance, false) : '—' }}</td>
        <td class="nowrap"><span class="badge badge-{{ $s->status === 'open' ? 'green' : 'slate' }}">{{ $s->status === 'open' ? 'Open' : 'Closed' }}</span>
          @if($s->status === 'open' && (int) $s->user_id !== (int) auth()->id() && can_do('club.manage'))
            <a class="tiny" href="{{ route('recreation.shift', ['shift_id' => $s->id]) }}">close it</a>
          @endif</td>
      </tr>
    @empty
      <tr><td colspan="9"><x-empty text="No outlet shifts yet." icon="wallet"/></td></tr>
    @endforelse
    </tbody>
  </table></div>
</div>
@endsection

@if($shift)
@push('scripts')
<script>
(() => {
  const expected = {{ (float) ($summary['expected_cash'] ?? 0) }};
  const fmt = n => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const counted = document.getElementById('counted_cash');
  const total = document.getElementById('denomTotal');
  const variance = document.getElementById('variancePreview');
  const paint = () => {
    let sum = 0;
    document.querySelectorAll('[data-denom]').forEach(input => {
      const qty = parseInt(input.value || 0, 10) || 0;
      const value = qty * parseFloat(input.dataset.denom);
      sum += value;
      document.querySelector(`[data-denom-value="${input.dataset.denom}"]`).textContent = fmt(value);
    });
    if (sum > 0) counted.value = sum.toFixed(2);
    total.textContent = fmt(sum);
    const diff = Math.round((parseFloat(counted.value || 0) - expected) * 100) / 100;
    variance.textContent = (diff > 0 ? '+' : '') + 'GH₵ ' + fmt(Math.abs(diff)) + (diff === 0 ? ' · balanced' : diff > 0 ? ' over' : ' short');
    variance.className = 'bold ' + (diff === 0 ? 'text-green' : diff > 0 ? 'text-amber' : 'text-red');
  };
  document.querySelectorAll('[data-denom]').forEach(input => input.addEventListener('input', paint));
  counted.addEventListener('input', paint);
  paint();
})();
</script>
@endpush
@endif
