@extends('layouts.print')
@section('title', 'Bank reconciliation '.$s->statement_no)
@section('doctitle', 'BANK RECONCILIATION STATEMENT')
@section('docmeta')<div class="small">{{ $s->account->code }} {{ $s->account->name }} · {{ $s->statement_no }} · {{ $s->label() }}<br>Status: <b>{{ strtoupper($s->status) }}</b>@if($s->reconciled_at) · reconciled {{ $s->reconciled_at->format('d M Y') }} by {{ $s->reconciler?->name }}@endif</div>@endsection
@section('content')
@php $td = 'padding:5px 6px;border-bottom:1px solid #e2e8f0'; $num = $td.';text-align:right;font-variant-numeric:tabular-nums'; @endphp
<table style="width:100%;border-collapse:collapse;font-size:13px;margin-top:1rem" id="printBrs">
  <tr><td style="{{ $td }}"><b>Balance as per statement at {{ $s->period_to->format('d M Y') }}</b></td><td style="{{ $num }}"></td><td style="{{ $num }}"><b>{{ money($sum['closing'], false) }}</b></td></tr>
  <tr><td style="{{ $td }}" colspan="3"><b>Add: deposits not yet credited by the bank</b></td></tr>
  @foreach($sum['U']->filter(fn ($g) => (float) $g->debit - (float) $g->credit > 0) as $g)
  <tr><td style="{{ $td }};padding-left:22px">{{ $g->date->format('d M Y') }} · {{ $g->journal->journal_no }} · {{ \Illuminate\Support\Str::limit($g->journal->narration, 70) }}</td><td style="{{ $num }}">{{ money((float) $g->debit - (float) $g->credit, false) }}</td><td style="{{ $num }}"></td></tr>
  @endforeach
  <tr><td style="{{ $td }};padding-left:22px"><i>Total</i></td><td style="{{ $num }}"></td><td style="{{ $num }}">{{ money($sum['uncleared_in'], false) }}</td></tr>
  <tr><td style="{{ $td }}" colspan="3"><b>Less: payments not yet debited by the bank</b></td></tr>
  @foreach($sum['U']->filter(fn ($g) => (float) $g->debit - (float) $g->credit < 0) as $g)
  <tr><td style="{{ $td }};padding-left:22px">{{ $g->date->format('d M Y') }} · {{ $g->journal->journal_no }} · {{ \Illuminate\Support\Str::limit($g->journal->narration, 70) }}</td><td style="{{ $num }}">{{ money((float) $g->credit - (float) $g->debit, false) }}</td><td style="{{ $num }}"></td></tr>
  @endforeach
  <tr><td style="{{ $td }};padding-left:22px"><i>Total</i></td><td style="{{ $num }}"></td><td style="{{ $num }}">({{ money(-$sum['uncleared_out'], false) }})</td></tr>
  <tr><td style="{{ $td }}" colspan="3"><b>Less / add: statement items not in the books</b></td></tr>
  @foreach($sum['S'] as $l)
  <tr><td style="{{ $td }};padding-left:22px">{{ $l->date->format('d M Y') }} · {{ \Illuminate\Support\Str::limit($l->description, 60) }}{{ $l->reference ? ' · '.$l->reference : '' }}{{ $l->status === 'ignored' ? ' (ignored: '.$l->ignore_reason.')' : '' }}</td><td style="{{ $num }}">{{ money((float) $l->amount, false) }}</td><td style="{{ $num }}"></td></tr>
  @endforeach
  <tr><td style="{{ $td }};padding-left:22px"><i>Net</i></td><td style="{{ $num }}"></td><td style="{{ $num }}">{{ money(-$sum['bank_only'], false) }}</td></tr>
  <tr style="border-top:2px solid #0f172a"><td style="{{ $td }}"><b>Adjusted balance</b></td><td style="{{ $num }}"></td><td style="{{ $num }}"><b>{{ money($sum['adjusted'], false) }}</b></td></tr>
  @if(abs($sum['opening_diff']) >= 0.005)
  <tr><td style="{{ $td }}">Adjust: opening difference brought forward (statement vs books) at {{ \Illuminate\Support\Carbon::parse($sum['start'])->format('d M Y') }}</td><td style="{{ $num }}"></td><td style="{{ $num }}">{{ money(-$sum['opening_diff'], false) }}</td></tr>
  @endif
  <tr><td style="{{ $td }}"><b>Balance as per books ({{ $s->account->code }})</b></td><td style="{{ $num }}"></td><td style="{{ $num }}"><b>{{ money($sum['book'], false) }}</b></td></tr>
  <tr><td style="{{ $td }}"><b>Difference</b></td><td style="{{ $num }}"></td><td style="{{ $num }};color:{{ abs($sum['difference']) < 0.005 ? '#15803d' : '#b91c1c' }}" id="printDiff"><b>{{ money($sum['difference'], false) }}</b></td></tr>
</table>
<p class="small" style="margin-top:.8rem">Statement: opening {{ money($s->opening_balance) }}, {{ $sum['counts']['total'] }} line(s), closing {{ money($s->closing_balance) }} · {{ $sum['counts']['matched'] }} matched, {{ $sum['counts']['ignored'] }} ignored, {{ $sum['counts']['unmatched'] }} open.</p>
<table style="width:100%;margin-top:3rem;font-size:12px"><tr><td>Prepared by: ______________________</td><td>Reviewed by: ______________________</td><td>Date: ____________</td></tr></table>
@endsection
