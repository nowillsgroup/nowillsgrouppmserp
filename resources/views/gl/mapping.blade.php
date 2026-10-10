@extends('layouts.app')
@section('title', 'GL Account Mapping')
@section('actions')<a class="btn" href="{{ route('masters.index', 'account-heads') }}"><x-icon name="layers"/> Chart of accounts</a><a class="btn" href="{{ route('gl.index') }}"><x-icon name="book"/> General ledger</a>@endsection
@section('content')
@php
  $sel = function ($name, $current, $types = null) use ($accounts) {
      $h = '<select class="input input-sm" name="'.e($name).'">';
      foreach ($accounts->when($types, fn ($c) => $c->whereIn('type', $types))->groupBy('type') as $type => $as) {
          $h .= '<optgroup label="'.e(\App\Models\AccountHead::TYPES[$type] ?? $type).'">';
          foreach ($as as $a) { $h .= '<option value="'.$a->id.'"'.($a->id == $current ? ' selected' : '').'>'.e($a->label).'</option>'; }
          $h .= '</optgroup>';
      }
      return $h.'</select>';
  };
@endphp
<form method="post" action="{{ route('gl.mapping.save') }}">@csrf
<div class="grid g-2">
<div class="card flush"><div class="card-head"><h3>Folio charge categories → revenue account</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="map-cats">
<thead><tr><th>Charge category</th><th>Credited to</th></tr></thead><tbody>
@foreach($categories as $k => $label)<tr><td>{{ $label }}</td><td>{!! $sel("cat[$k]", $catDefaults[$k], ['income', 'liability']) !!}</td></tr>@endforeach
<tr><td class="bold">POS bills settled at the outlet</td><td>{!! $sel('pos_revenue', $posRevenue, ['income']) !!}</td></tr>
</tbody></table></div></div>
<div>
<div class="card flush"><div class="card-head"><h3>Payment modes → cash / bank / clearing account</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="map-modes">
<thead><tr><th>Payment mode</th><th>Debited to</th></tr></thead><tbody>
@foreach($modes as $m)<tr><td>{{ $m->name }} <span class="muted small">({{ $m->type }})</span></td><td>{!! $sel("mode[{$m->id}]", $m->account_head_id, ['asset']) !!}</td></tr>@endforeach
</tbody></table></div></div>
<div class="card"><div class="card-head"><h3>Purchases</h3></div><div class="card-body">
<label class="check"><input type="checkbox" name="input_tax" value="1" @checked(setting('gl_input_tax', '1') === '1')> <span>Tax on goods receipts is claimable (post to <em>Input Tax Credit</em>; otherwise it is added to inventory cost)</span></label>
</div></div>
<div class="card"><div class="card-head"><h3>Fixed postings</h3></div><div class="card-body small">
<table class="table table-compact"><tbody>
<tr><td>Folio charges / payments</td><td>Guest Ledger (1100)</td></tr><tr><td>Output tax on charges &amp; bills</td><td>Output Tax Payable (2100)</td></tr>
<tr><td>POS service charge</td><td>Service Charge Payable (2110)</td></tr><tr><td>Transfer to company billing</td><td>City Ledger Receivable (1110)</td></tr>
<tr><td>Discounts / allowances · loyalty redemptions</td><td>4900 · 5050</td></tr><tr><td>Goods received</td><td>Inventory (1200) / Accounts Payable (2010)</td></tr>
<tr><td>Issues &amp; recipe consumption</td><td>Kitchen &amp; bar → 5010, housekeeping → 5020, other stores → 5030</td></tr><tr><td>Wastage &amp; stock-take variance</td><td>5040</td></tr>
</tbody></table></div></div>
</div>
</div>
<div class="flex gap-2" style="justify-content:flex-end"><button class="btn btn-primary"><x-icon name="check"/> Save mapping</button></div>
</form>
@endsection
