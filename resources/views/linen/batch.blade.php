@extends('layouts.app')
@section('title', 'Laundry batch '.$b->batch_no)
@section('crumbs') <a href="{{ route('linen.batches') }}">Laundry batches</a> / {{ $b->batch_no }} @endsection
@section('actions')
<span class="badge badge-{{ ['sent' => 'blue', 'partial' => 'amber', 'closed' => 'green'][$b->status] }}" id="bStatus">{{ ['sent' => 'At laundry', 'partial' => 'Part returned', 'closed' => 'Closed'][$b->status] }}</span>
@if($b->status !== 'closed' && can_do('laundry.linen'))<button class="btn btn-danger" data-modal="closeModal"><x-icon name="x"/> Close short</button>@endif
@if($b->status === 'closed' && $b->supplier_id && ! $b->supplier_invoice_id && can_do('ap.manage') && $b->charge() > 0)<button class="btn btn-primary" data-modal="billModal" id="billBtn"><x-icon name="receipt"/> Create supplier bill</button>@endif
@if($b->bill)<a class="btn" href="{{ route('ap.bills.show', $b->bill) }}"><x-icon name="receipt"/> Bill {{ $b->bill->doc_no }}</a>@endif
@endsection
@section('content')
<div class="stats wide">
  <x-stat label="Laundry" :value="$b->supplier ? \Illuminate\Support\Str::limit(\Illuminate\Support\Str::before($b->supplier->name, ' Laundry'), 14) : 'In-house'" icon="building" tone="blue" :sub="($b->supplier ? $b->supplier->name.' · ' : '').'Sent '.$b->sent_date->format('d M Y').($b->expected_date ? ' · due '.$b->expected_date->format('d M') : '')"/>
  <x-stat label="Pieces sent" :value="$b->sent()" icon="swap" tone="purple"/>
  <x-stat label="Returned" :value="$b->lines->sum('qty_returned')" icon="check" tone="green" :sub="$b->lines->sum('qty_damaged').' damaged'"/>
  <x-stat label="Outstanding" :value="$b->outstanding()" icon="clock" :tone="$b->outstanding() ? ($b->isOverdue() ? 'red' : 'amber') : 'green'" :sub="$b->lines->sum('qty_lost') ? $b->lines->sum('qty_lost').' written off as lost' : null"/>
  <x-stat label="Laundry charge" :value="money($b->charge())" icon="money" tone="slate" sub="returned + damaged pcs × rate"/>
</div>
<form method="post" action="{{ route('linen.receive', $b) }}" class="card flush">@csrf
<div class="card-head"><h3>Lines</h3>@if($b->status !== 'closed' && can_do('laundry.linen'))<div class="flex gap-1 items-center"><input type="date" name="date" class="input input-sm" value="{{ bdate()->toDateString() }}" required><button class="btn btn-primary btn-sm" id="receiveBtn"><x-icon name="check"/> Receive</button></div>@endif</div>
<div class="card-body table-wrap"><table class="table" id="lineTable">
<thead><tr><th>Linen</th><th class="num">Sent</th><th class="num">Returned</th><th class="num">Damaged</th><th class="num">Lost</th><th class="num">Outstanding</th><th class="num">Rate</th><th class="num">Charge</th>@if($b->status !== 'closed')<th class="num">Back clean now</th><th class="num">Damaged now</th>@endif</tr></thead><tbody>
@foreach($b->lines as $l)<tr><td>{{ $l->linen?->code }} – {{ $l->linen?->name }}</td><td class="num">{{ $l->qty_sent }}</td><td class="num">{{ $l->qty_returned }}</td><td class="num {{ $l->qty_damaged ? 'text-red' : '' }}">{{ $l->qty_damaged }}</td><td class="num {{ $l->qty_lost ? 'text-red' : '' }}">{{ $l->qty_lost }}</td>
<td class="num bold">{{ $l->outstanding() ?: '—' }}</td><td class="num small">{{ money($l->rate, false) }}</td><td class="num">{{ money(($l->qty_returned + $l->qty_damaged) * (float) $l->rate, false) }}</td>
@if($b->status !== 'closed')<td class="num">@if($l->outstanding())<input type="number" name="ret[{{ $l->id }}]" min="0" max="{{ $l->outstanding() }}" class="input input-sm" style="width:76px" value="{{ $l->outstanding() }}" @disabled(! can_do('laundry.linen'))>@endif</td>
<td class="num">@if($l->outstanding())<input type="number" name="dmg[{{ $l->id }}]" min="0" max="{{ $l->outstanding() }}" class="input input-sm" style="width:66px" @disabled(! can_do('laundry.linen'))>@endif</td>@endif</tr>@endforeach
</tbody></table></div>
@if($b->status !== 'closed')<div class="card-body small muted" style="border-top:1px solid var(--c-border)">Pre-filled with everything outstanding – adjust to what actually came back. Damaged pieces are discarded at their unit cost.</div>@endif
</form>
@if($b->notes)<div class="card"><div class="card-body small"><b>Notes:</b> {{ $b->notes }}</div></div>@endif
<div class="card flush"><div class="card-head"><h3>Movements</h3></div><div class="card-body table-wrap"><table class="table table-compact">
<thead><tr><th>Date</th><th>Linen</th><th>Movement</th><th class="num">Qty</th><th class="num">Value</th><th>By</th></tr></thead><tbody>
@foreach($moves as $m)<tr><td class="small">{{ $m->date->format('d M Y') }}</td><td>{{ $m->linen?->code }}</td><td>{{ \App\Models\LinenMovement::TYPES[$m->type] ?? $m->type }}@if($m->reason)<div class="tiny muted">{{ $m->reason }}</div>@endif</td><td class="num">{{ $m->qty }}</td><td class="num small">{{ (float) $m->value ? money($m->value, false) : '' }}</td><td class="small muted">{{ $m->user?->name }}</td></tr>@endforeach
</tbody></table></div></div>

<div class="modal" id="closeModal"><div class="modal-box"><form method="post" action="{{ route('linen.close', $b) }}">@csrf
  <div class="modal-head"><h3>Close {{ $b->batch_no }} short</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="m-0 mb-1">{{ $b->outstanding() }} piece(s) never came back and will be <b>written off as lost</b> at their unit cost.</p>
    <div class="field"><label>Reason</label><input name="reason" class="input" required maxlength="190" placeholder="e.g. laundry confirmed items missing"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger">Close & write off</button></div>
</form></div></div>
<div class="modal" id="billModal"><div class="modal-box"><form method="post" action="{{ route('linen.bill', $b) }}">@csrf
  <div class="modal-head"><h3>Supplier bill for {{ $b->batch_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <p class="span-all m-0">{{ $b->supplier?->name }} · <b>{{ money($b->charge()) }}</b> before tax, posted to <i>6130 Laundry &amp; Linen</i>.</p>
    <div class="field"><label>Their invoice no.</label><input name="supplier_ref" class="input" maxlength="60"></div>
    <div class="field"><label>Invoice date</label><input type="date" name="doc_date" class="input" value="{{ ($b->closed_date ?? bdate())->toDateString() }}"></div>
    <div class="field"><label>Tax %</label><input type="number" name="tax_rate" min="0" max="100" step="0.01" class="input" value="20"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Create bill</button></div>
</form></div></div>
@endsection
