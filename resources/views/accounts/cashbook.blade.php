@extends('layouts.app')
@section('title', 'Cash Book / Daily Collection')
@section('actions')<form class="flex gap-1"><input class="input" type="date" name="date" value="{{ $date }}" data-autosubmit></form><button class="btn" onclick="window.print()"><x-icon name="print"/> Print</button>@endsection
@section('content')
<div class="stats">@foreach($byMode as $mode=>$amt)<x-stat :label="$mode" :value="money($amt)" icon="money" tone="{{ $amt < 0 ? 'red' : 'green' }}"/>@endforeach<x-stat label="Net collection" :value="money($rows->sum('in') - $rows->sum('out'))" icon="wallet" tone="blue"/></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Source</th><th>Ref</th><th>Particulars</th><th>Mode</th><th class="num">Receipt</th><th class="num">Payment</th></tr></thead><tbody>
@forelse($rows as $r)<tr><td><span class="badge badge-slate">{{ $r['src'] }}</span></td><td>{{ $r['ref'] }}</td><td>{{ $r['desc'] }}</td><td>{{ $r['mode'] }}</td><td class="num">{{ $r['in'] ? money($r['in'], false) : '' }}</td><td class="num">{{ $r['out'] ? money($r['out'], false) : '' }}</td></tr>
@empty<tr><td colspan="6"><x-empty text="No transactions on this date." icon="money"/></td></tr>@endforelse</tbody>
<tfoot><tr><td colspan="4" class="right">Total</td><td class="num">{{ money($rows->sum('in')) }}</td><td class="num">{{ money($rows->sum('out')) }}</td></tr></tfoot></table></div></div>
@endsection
