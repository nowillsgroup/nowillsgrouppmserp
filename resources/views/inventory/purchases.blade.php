@extends('layouts.app')
@section('title', 'Purchase Orders')
@section('actions')
@if(can_do('settings.manage'))<button class="btn" data-modal="procSettings" id="procSettingsBtn"><x-icon name="shield"/> Approval matrix</button>@endif
@if(can_do('purchasing.rfq'))<a class="btn" href="{{ route('rfq.create') }}"><x-icon name="mail"/> New RFQ</a>@endif
@if(can_do('inventory.manage'))<a class="btn btn-primary" href="{{ route('purchases.create') }}"><x-icon name="plus"/> New PO</a>@endif
@endsection
@section('content')
@php $mine = \App\Services\ProcurementService::awaitingMyApproval(); $late = \App\Services\ProcurementService::overdueDeliveries(); [$l1, $l2] = \App\Services\ProcurementService::limits(); @endphp
@if($mine->count() || $late->count())
<div class="grid g-2">
  @if($mine->count())<div class="card" id="poMyApprovals"><div class="card-head"><h3>Waiting for your approval</h3><span class="badge badge-amber">{{ $mine->count() }}</span></div><div class="card-body table-wrap"><table class="table table-compact"><tbody>
    @foreach($mine->take(8) as $po)<tr><td><a class="bold" href="{{ route('purchases.show', $po) }}">{{ $po->po_no }}</a></td><td>{{ $po->supplier->name }}</td><td class="num">{{ money($po->total) }}</td><td class="small muted">L{{ \App\Services\ProcurementService::requiredLevel($po) }}</td></tr>@endforeach
  </tbody></table></div></div>@endif
  @if($late->count())<div class="card" id="poOverdueList"><div class="card-head"><h3>Overdue deliveries</h3><span class="badge badge-red">{{ $late->count() }}</span></div><div class="card-body table-wrap"><table class="table table-compact"><tbody>
    @foreach($late->take(8) as $po)<tr><td><a class="bold" href="{{ route('purchases.show', $po) }}">{{ $po->po_no }}</a></td><td>{{ $po->supplier->name }}</td><td class="small">due {{ $po->expected_date->format('d M') }}</td><td class="text-red small">{{ $po->expected_date->diffInDays(bdate()) }} d late</td></tr>@endforeach
  </tbody></table></div></div>@endif
</div>
@endif
<div class="tabs">@foreach([''=>'All','draft'=>'Draft','approved'=>'Approved','partial'=>'Part received','received'=>'Received','closed'=>'Short-closed','cancelled'=>'Cancelled'] as $k=>$v)<a href="?status={{ $k }}" class="{{ request('status','')===$k ? 'active' : '' }}">{{ $v }}</a>@endforeach</div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>PO No</th><th>Date</th><th>Supplier</th><th>Store</th><th>Expected</th><th class="num">Total</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($orders as $po)<tr><td><a href="{{ route('purchases.show', $po) }}" class="bold">{{ $po->po_no }}</a>@if($po->rfq_id)<div class="tiny muted">from RFQ</div>@endif</td><td>{{ $po->po_date->format('d M Y') }}</td><td>{{ $po->supplier->name }}</td><td>{{ $po->store->name }}</td><td>{{ dmy($po->expected_date) }}@if($po->isOverdue()) <span class="badge badge-red">late</span>@endif</td><td class="num">{{ money($po->total) }}</td><td>{!! status_badge($po->status) !!}</td><td><a class="btn btn-sm" href="{{ route('purchases.show', $po) }}"><x-icon name="eye" size="sm"/></a></td></tr>
@empty<tr><td colspan="8"><x-empty text="No purchase orders." icon="box"/></td></tr>@endforelse</tbody></table>{{ $orders->links() }}</div></div>
@if(can_do('settings.manage'))
<div class="modal" id="procSettings"><div class="modal-box"><form method="post" action="{{ route('proc.settings') }}">@csrf
<div class="modal-head"><h3>Purchasing controls</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
<div class="modal-body"><div class="form-grid">
  <x-field name="po_limit_1" label="Level 1 limit – store (GH₵)" type="number" step="0.01" min="0" :value="$l1" required hint="inventory.manage approves up to this PO total"/>
  <x-field name="po_limit_2" label="Level 2 limit – purchasing manager (GH₵)" type="number" step="0.01" min="0" :value="$l2" required hint="purchasing.approve; above → purchasing.approve_high"/>
  <x-field name="po_price_tolerance_pct" label="Price tolerance vs agreed rate (%)" type="number" step="0.1" min="0" :value="setting('po_price_tolerance_pct', '2')" required/>
  <x-field name="po_over_receipt_pct" label="Over-delivery tolerance (%)" type="number" step="0.1" min="0" :value="setting('po_over_receipt_pct', '0')" required/>
  <x-field name="rfq_min_quotes" label="Minimum quotations per RFQ" type="number" min="1" :value="setting('rfq_min_quotes', '3')" required hint="fewer needs a written justification at award"/>
</div>
<label class="check mt-1"><input type="checkbox" name="po_segregation" value="1" @checked(setting('po_segregation', '0') === '1')> <span>Segregation of duties – the person who raised a PO cannot approve it</span></label></div>
<div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div></form></div></div>
@endif
@endsection
