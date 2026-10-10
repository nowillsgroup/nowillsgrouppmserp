@extends('layouts.app')
@section('title', 'New statement')
@section('crumbs') Accounts / <a href="{{ route('bankrec.index') }}">Bank reconciliation</a> / New statement @endsection
@section('content')
<div class="card" style="max-width:980px"><div class="card-head"><h3><x-icon name="download"/> Load a bank or Mobile Money statement</h3></div>
<div class="card-body">
<form method="post" action="{{ route('bankrec.store') }}" enctype="multipart/form-data" class="form-grid" id="stmtForm">@csrf
  <div class="field span-2"><label for="f_account_head_id">Account <span class="req">*</span></label>
    <select name="account_head_id" id="f_account_head_id" required>
      @foreach($accounts as $a)<option value="{{ $a->id }}" @selected((int) old('account_head_id', $account) === $a->id) data-from="{{ $starts[$a->id][0] }}" data-opening="{{ number_format($starts[$a->id][1], 2, '.', '') }}" data-fixed="{{ $starts[$a->id][2] ? 1 : 0 }}" data-busy="{{ $busy[$a->id] ?? '' }}">{{ $a->code }} {{ $a->name }}</option>@endforeach
    </select>
    <span class="hint" id="accHint"></span>
  </div>
  <x-field name="period_from" label="From" type="date" required :value="old('period_from')"/>
  <x-field name="period_to" label="To" type="date" required :value="old('period_to', bdate()->copy()->subDay()->toDateString())"/>
  <x-field name="opening_balance" label="Opening balance (per the statement)" type="number" step="0.01" required :value="old('opening_balance')"/>
  <x-field name="closing_balance" label="Closing balance (per the statement)" type="number" step="0.01" :value="old('closing_balance')" hint="Leave blank to take it from the lines."/>

  <div class="field span-2"><label>Lines</label>
    <label class="check"><input type="radio" name="mode" value="csv" @checked(old('mode', 'csv') === 'csv')> Import a CSV file (internet banking / MoMo merchant statement export)</label>
    <label class="check"><input type="radio" name="mode" value="manual" @checked(old('mode') === 'manual')> Type the lines in on the next screen</label>
  </div>
  <div class="span-2" id="csvBox">
    <div class="form-grid">
      <div class="field"><label for="f_file">CSV file</label><input type="file" name="file" id="f_file" accept=".csv,.txt,text/csv"></div>
      <x-field name="date_format" label="Dates in the file" type="select" :options="\App\Services\BankRecService::DATE_FORMATS" :placeholder="false" :value="old('date_format', 'auto')"/>
      <div class="field span-2"><label class="check"><input type="checkbox" name="invert" value="1" @checked(old('invert'))> Amounts are the bank’s view (money you receive shown as negative / debit)</label></div>
    </div>
    <details class="mt-1"><summary class="small">Column names (only if they are not detected)</summary>
      <div class="form-grid mt-1">
        @foreach(['date' => 'Date', 'description' => 'Description / narration', 'reference' => 'Reference / transaction ID', 'amount' => 'Signed amount', 'credit' => 'Money in (credit)', 'debit' => 'Money out (debit)', 'balance' => 'Running balance', 'fee' => 'Fee (MoMo)'] as $k => $l)
        <div class="field"><label for="f_col_{{ $k }}">{{ $l }}</label><input name="col[{{ $k }}]" id="f_col_{{ $k }}" value="{{ old('col.'.$k) }}" maxlength="60" placeholder="auto"></div>
        @endforeach
      </div>
    </details>
    <p class="tiny muted mt-1">Recognised headings include Date / Transaction Date / Value Date, Description / Narration / Details, Reference / Transaction ID, Debit / Withdrawal / Money Out, Credit / Deposit / Money In, Amount, Balance and Fee. Rows before the heading row, blank rows and opening / closing balance rows are skipped; lines already imported for this account are left out.</p>
  </div>
  <x-field name="notes" label="Notes" type="textarea" :value="old('notes')" class="span-2" maxlength="500"/>
  <div class="span-2"><button class="btn btn-primary" id="stmtSave"><x-icon name="check"/> Create statement</button> <a class="btn" href="{{ route('bankrec.index') }}">Cancel</a></div>
</form>
</div></div>
<script>
(function () {
  const sel = document.getElementById('f_account_head_id'), from = document.getElementById('f_period_from'), op = document.getElementById('f_opening_balance'), hint = document.getElementById('accHint');
  const csv = document.getElementById('csvBox');
  function acc(init) {
    const o = sel.options[sel.selectedIndex];
    if (!init || !from.value) from.value = o.dataset.from;
    if (!init || !op.value) op.value = o.dataset.opening;
    op.readOnly = o.dataset.fixed === '1';
    hint.textContent = o.dataset.busy ? 'Statement ' + o.dataset.busy + ' is still in progress for this account – finish it first.' : (o.dataset.fixed === '1' ? 'Follows on from the last statement: starts ' + o.dataset.from + ' with its closing balance.' : 'First statement: the opening balance is suggested from the ledger – use the figure on the statement.');
  }
  function mode() { csv.style.display = document.querySelector('[name=mode]:checked').value === 'csv' ? '' : 'none'; }
  sel.addEventListener('change', () => acc(false));
  document.querySelectorAll('[name=mode]').forEach(r => r.addEventListener('change', mode));
  acc(true); mode();
})();
</script>
@endsection
