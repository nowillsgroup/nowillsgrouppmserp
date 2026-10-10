@extends('layouts.app')
@section('title', 'Laundry Batches')
@section('crumbs') <a href="{{ route('linen.index') }}">Linen</a> / Laundry batches @endsection
@section('actions')
@if(can_do('laundry.linen'))<button class="btn btn-primary" data-modal="sendModal" id="sendBtn"><x-icon name="swap"/> Send soiled linen</button>@endif
@endsection
@section('content')
<div class="tabs">@foreach(['' => 'All', 'open' => 'At the laundry', 'closed' => 'Closed'] as $k => $v)<a href="?status={{ $k }}" class="{{ (string) $status === $k ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table" id="batchTable">
<thead><tr><th>Batch</th><th>Laundry</th><th>Sent</th><th>Expected</th><th class="num">Pieces sent</th><th class="num">Returned</th><th class="num">Outstanding</th><th class="num">Charge</th><th>Status</th><th>Bill</th></tr></thead><tbody>
@forelse($list as $b)
<tr class="{{ $b->isOverdue() ? 'row-warn' : '' }}"><td><a class="bold" href="{{ route('linen.batch', $b) }}">{{ $b->batch_no }}</a></td>
<td>{{ $b->supplier?->name ?? 'In-house laundry' }}</td><td class="small">{{ $b->sent_date->format('d M Y') }}</td>
<td class="small {{ $b->isOverdue() ? 'text-red bold' : '' }}">{{ $b->expected_date?->format('d M Y') ?? '—' }}</td>
<td class="num">{{ $b->sent() }}</td><td class="num">{{ $b->lines->sum('qty_returned') }}@if($d = $b->lines->sum('qty_damaged'))<div class="tiny text-red">{{ $d }} damaged</div>@endif</td>
<td class="num {{ $b->outstanding() ? 'bold' : 'muted' }}">{{ $b->outstanding() ?: '—' }}@if($l = $b->lines->sum('qty_lost'))<div class="tiny text-red">{{ $l }} lost</div>@endif</td>
<td class="num">{{ $b->supplier_id ? money($b->charge()) : '—' }}</td>
<td><span class="badge badge-{{ ['sent' => 'blue', 'partial' => 'amber', 'closed' => 'green'][$b->status] }}">{{ ['sent' => 'At laundry', 'partial' => 'Part returned', 'closed' => 'Closed'][$b->status] }}</span>@if($b->isOverdue()) <span class="badge badge-red">Late</span>@endif</td>
<td class="small">@if($b->bill)<a href="{{ route('ap.bills.show', $b->bill) }}">{{ $b->bill->doc_no }}</a>@elseif($b->supplier_id && $b->status === 'closed')<span class="text-amber">to bill</span>@else — @endif</td></tr>
@empty<tr><td colspan="10"><x-empty text="No laundry batches yet." icon="swap"/></td></tr>@endforelse
</tbody></table></div></div>
{{ $list->links() }}

<div class="modal" id="sendModal"><div class="modal-box lg"><form method="post" action="{{ route('linen.send') }}">@csrf
  <div class="modal-head"><h3>Send linen to the laundry</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div class="form-grid">
      <div class="field span-2"><label>Laundry</label><select name="supplier_id" class="input"><option value="">In-house laundry (no charge)</option>@foreach($suppliers as $s)<option value="{{ $s->id }}" @selected($vendorId === $s->id)>{{ $s->name }}</option>@endforeach</select></div>
      <div class="field"><label>Sent</label><input type="date" name="sent_date" class="input" value="{{ bdate()->toDateString() }}" required></div>
      <div class="field"><label>Expected back</label><input type="date" name="expected_date" class="input" value="{{ bdate()->copy()->addDay()->toDateString() }}"></div>
    </div>
    <table class="table table-compact mt-1" id="sendTable"><thead><tr><th>Linen</th><th class="num">Soiled</th><th class="num">On floors</th><th class="num">Rate / pc</th><th class="num" style="width:110px">Send</th></tr></thead><tbody>
    @foreach($types as $t)<tr><td>{{ $t->code }} – {{ $t->name }}</td><td class="num">{{ $t->qty_soiled }}</td><td class="num muted">{{ $t->qty_floor }}</td><td class="num small">{{ money($t->wash_rate, false) }}</td>
      <td class="num"><input type="number" name="qty[{{ $t->id }}]" min="0" max="{{ $t->qty_soiled + $t->qty_floor }}" class="input input-sm" value="{{ $t->qty_soiled ?: '' }}" style="width:80px"></td></tr>@endforeach
    </tbody></table>
    <p class="small muted m-0">Pre-filled with everything soiled. Sending more than the soiled count collects the rest from the floors first.</p>
    <div class="field mt-1"><label>Notes</label><input name="notes" class="input" maxlength="255"></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Send batch</button></div>
</form></div></div>
@endsection
