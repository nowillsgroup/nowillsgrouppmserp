@extends('layouts.app')
@section('title', 'Ledger – '.$company->name)
@section('crumbs') <a href="{{ route('accounts.cityledger') }}">City Ledger</a> / {{ $company->name }} @endsection
@section('actions')<button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>@if(can_do('accounts.manage'))<button class="btn btn-success" data-modal="rcModal"><x-icon name="money"/> Receive Payment</button>@endif @endsection
@section('content')
@php $bal = 0; @endphp
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Date</th><th>Due</th><th>Ref</th><th>Particulars</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead><tbody>
@forelse($rows as $r)@php $bal += $r['debit'] - $r['credit']; @endphp
<tr><td>{{ dmy($r['date']) }}</td><td>{{ $r['due'] ? dmy($r['due']) : '' }}</td><td>@if($r['folio'])<a href="{{ route('folios.show', $r['folio']) }}">{{ $r['ref'] }}</a>@else{{ $r['ref'] }}@endif</td><td>{{ $r['desc'] }}</td><td class="num">{{ $r['debit'] ? money($r['debit'], false) : '' }}</td><td class="num">{{ $r['credit'] ? money($r['credit'], false) : '' }}</td><td class="num bold">{{ money($bal, false) }}</td></tr>
@empty<tr><td colspan="7"><x-empty text="No city ledger entries." icon="receipt"/></td></tr>@endforelse
</tbody><tfoot><tr><td colspan="4" class="right">Closing balance</td><td class="num">{{ money($rows->sum('debit'), false) }}</td><td class="num">{{ money($rows->sum('credit'), false) }}</td><td class="num">{{ money($bal) }}</td></tr></tfoot></table></div></div>
<div class="modal" id="rcModal"><div class="modal-box"><form method="post" action="{{ route('accounts.company.receipt', $company) }}">@csrf
<div class="modal-head"><h3>Receive Payment – {{ $company->name }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body form-grid"><x-field name="amount" label="Amount" type="number" step="0.01" min="0.01" :value="max(0,$bal)" required/><x-field name="payment_mode_id" label="Mode" type="select" :options="$paymentModes" required/><x-field name="date" label="Date" type="date" :value="bdate()->toDateString()" required/><x-field name="reference" label="Cheque / UTR no."/></div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-success">Save Receipt</button></div></form></div></div>
@endsection
