@extends('layouts.app')
@section('title', 'Manual Journal Entry')
@section('actions')<a class="btn" href="{{ route('gl.journals') }}"><x-icon name="book"/> Journals</a>@endsection
@section('content')
@php
  $rows = old('lines') ?: ($copy ? $copy->lines->map(fn ($l) => ['account_head_id' => $l->account_head_id, 'debit' => (float) $l->debit ?: '', 'credit' => (float) $l->credit ?: '', 'memo' => $l->memo])->all() : array_fill(0, 4, []));
  $opts = $accounts->groupBy('type');
@endphp
@if($errors->any())<div class="alert alert-error">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
<form method="post" action="{{ route('gl.journals.store') }}" id="jvForm">@csrf
<div class="card"><div class="card-body form-grid">
<div class="field"><label>Date <span class="req">*</span></label><input type="date" name="date" value="{{ old('date', max($minDate, bdate()->copy()->subDay()->toDateString())) }}" min="{{ $minDate }}" max="{{ $maxDate }}" required></div>
<div class="field"><label>Reference</label><input name="reference" maxlength="80" value="{{ old('reference', $copy?->reference) }}" placeholder="Bill / document no."></div>
<div class="field span-2"><label>Narration <span class="req">*</span></label><input name="narration" maxlength="255" required value="{{ old('narration', $copy?->narration) }}" placeholder="e.g. Depreciation for September"></div>
</div></div>
<div class="card flush"><div class="card-head"><h3>Lines</h3><button type="button" class="btn btn-sm" id="addLine"><x-icon name="plus" size="sm"/> Add line</button></div>
<div class="card-body table-wrap"><table class="table gl-lines" id="jvLines">
<thead><tr><th style="width:42%">Account</th><th>Memo</th><th class="num" style="width:14%">Debit</th><th class="num" style="width:14%">Credit</th><th style="width:40px"></th></tr></thead><tbody>
@foreach($rows as $i => $r)
<tr><td><select class="input input-sm" name="lines[{{ $i }}][account_head_id]"><option value="">— Select account —</option>@foreach($opts as $type => $as)<optgroup label="{{ \App\Models\AccountHead::TYPES[$type] ?? $type }}">@foreach($as as $a)<option value="{{ $a->id }}" @selected(($r['account_head_id'] ?? null) == $a->id)>{{ $a->label }}</option>@endforeach</optgroup>@endforeach</select></td>
<td><input class="input input-sm" name="lines[{{ $i }}][memo]" maxlength="150" value="{{ $r['memo'] ?? '' }}"></td>
<td><input type="number" step="0.01" min="0" class="input input-sm num dr" name="lines[{{ $i }}][debit]" value="{{ $r['debit'] ?? '' }}"></td>
<td><input type="number" step="0.01" min="0" class="input input-sm num cr" name="lines[{{ $i }}][credit]" value="{{ $r['credit'] ?? '' }}"></td>
<td><button type="button" class="btn btn-ghost btn-sm del" title="Remove line"><x-icon name="trash" size="sm"/></button></td></tr>
@endforeach
</tbody></table>
<div class="gl-balance" style="padding-right:1rem"><span>Debits <span id="tDr">0.00</span></span><span>Credits <span id="tCr">0.00</span></span><span id="tDiff" class="off">Difference 0.00</span></div>
</div></div>
<div class="flex gap-2" style="justify-content:flex-end"><a class="btn" href="{{ route('gl.journals') }}">Cancel</a><button class="btn btn-primary" id="postJv" disabled><x-icon name="check"/> Post journal</button></div>
</form>
<p class="small muted">Each line carries either a debit or a credit. The journal can be dated after the lock date ({{ \App\Services\LedgerService::lockDate() ?: 'none' }}) and up to the current business date. Posted journals are never edited – use Reverse.</p>
@endsection
@push('scripts')<script>
(() => {
  const body = document.querySelector('#jvLines tbody'), post = document.getElementById('postJv');
  const f = v => (Math.round((parseFloat(v) || 0) * 100) / 100);
  function total() {
    let dr = 0, cr = 0, used = 0;
    body.querySelectorAll('tr').forEach(tr => { const d = f(tr.querySelector('.dr').value), c = f(tr.querySelector('.cr').value); dr += d; cr += c; if (d || c) used++; });
    const diff = Math.round((dr - cr) * 100) / 100;
    document.getElementById('tDr').textContent = dr.toFixed(2); document.getElementById('tCr').textContent = cr.toFixed(2);
    const el = document.getElementById('tDiff'); el.textContent = diff === 0 ? 'Balanced' : 'Difference ' + diff.toFixed(2); el.className = diff === 0 ? 'ok' : 'off';
    post.disabled = !(diff === 0 && dr > 0 && used >= 2);
  }
  body.addEventListener('input', e => {
    const tr = e.target.closest('tr'); if (!tr) return;
    if (e.target.classList.contains('dr') && e.target.value) tr.querySelector('.cr').value = '';
    if (e.target.classList.contains('cr') && e.target.value) tr.querySelector('.dr').value = '';
    total();
  });
  body.addEventListener('click', e => { const b = e.target.closest('.del'); if (b && body.rows.length > 2) { b.closest('tr').remove(); total(); } });
  document.getElementById('addLine').addEventListener('click', () => {
    const tr = body.rows[body.rows.length - 1].cloneNode(true), n = Date.now();
    tr.querySelectorAll('[name]').forEach(el => { el.name = el.name.replace(/lines\[\d+\]/, 'lines[' + n + ']'); el.value = ''; });
    body.appendChild(tr); total();
  });
  total();
})();
</script>@endpush
