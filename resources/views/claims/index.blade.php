@extends('layouts.app')
@section('title', $mine ? 'My Expense Claims' : 'Expense Claims')
@section('crumbs') {{ $mine ? 'HR / My expense claims' : 'Accounts / Expense claims' }} @endsection
@section('actions')
@if($me || can_do('claims.approve'))<button class="btn btn-primary" data-modal="claimModal" id="newClaimBtn"><x-icon name="plus"/> New claim</button>@endif
@if(! $mine && can_do('reports.view'))<a class="btn" href="{{ route('reports.show', ['expense_claims']) }}"><x-icon name="chart"/> Claims register</a>@endif
@if(! $mine && can_do('claims.submit') && $me)<a class="btn" href="{{ route('claims.mine') }}"><x-icon name="user"/> My claims</a>@endif
@endsection
@section('content')
@if(! $mine)
<div class="stats" id="claimStats">
  @foreach(['submitted' => ['To approve', 'purple', 'clock'], 'approved' => ['To pay', 'blue', 'money'], 'rejected' => ['Sent back', 'red', 'x'], 'paid' => ['Paid', 'green', 'check']] as $k => [$l, $tone, $ic])
    <x-stat :label="$l" :value="(int) ($counts[$k]->n ?? 0)" :icon="$ic" :tone="$tone" :sub="money($counts[$k]->t ?? 0)" :href="route('claims.index', ['status' => $k])"/>
  @endforeach
</div>
@elseif(! $me)
<div class="alert alert-info"><x-icon name="alert"/><div>Your user is not linked to an employee record, so you cannot claim for yourself. Ask HR to link it.</div></div>
@endif

<div class="card flush mt-1">
  <div class="card-head"><h3>{{ $mine ? 'My claims' : 'Claims' }}</h3>
    @if(! $mine)<form class="filters" style="margin:0"><select name="status" class="input" data-autosubmit>@foreach(['submitted' => 'To approve', 'approved' => 'To pay', 'draft' => 'Draft', 'rejected' => 'Sent back', 'paid' => 'Paid', 'all' => 'All'] as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }}</option>@endforeach</select>
      <input type="search" name="q" value="{{ $q }}" class="input" placeholder="Claim no., title, staff"> <button class="btn btn-sm">Search</button></form>@endif
  </div>
  <div class="card-body table-wrap"><table class="table" id="claimTable">
    <thead><tr><th>Claim</th><th>Staff</th><th>Title</th><th>Items</th><th class="num">Total</th><th class="num">Input VAT</th><th>Status</th><th>Updated</th></tr></thead>
    <tbody>
    @forelse($claims as $c)
      <tr data-claim="{{ $c->claim_no }}"><td><a href="{{ route('claims.show', $c) }}" class="bold">{{ $c->claim_no }}</a></td><td>{{ $c->employee?->name }}<div class="tiny muted">{{ $c->employee?->department?->name }}</div></td>
        <td class="small">{{ $c->title }}@if($c->status === 'rejected' && $c->reject_reason)<div class="tiny text-red">{{ $c->reject_reason }}</div>@endif</td><td class="small">{{ $c->lines_count }}</td>
        <td class="num">{{ money($c->total, false) }}</td><td class="num">{{ (float) $c->vat_total ? money($c->vat_total, false) : '' }}</td><td>{!! status_badge($c->status) !!}</td><td class="small">{{ $c->updated_at->format('d M Y') }}</td></tr>
    @empty<tr><td colspan="8"><x-empty text="No claims here." icon="receipt"/></td></tr>@endforelse
    </tbody></table></div>
  @if($claims->hasPages())<div class="card-body">{{ $claims->links() }}</div>@endif
</div>
<p class="tiny muted">Claim back what you paid for the hotel from your own pocket: transport, mileage at {{ money($cfg['claims_mileage_rate']) }}/km, meals on duty, client entertainment, airtime, small purchases. Attach receipts above {{ money($cfg['claims_receipt_threshold']) }}. Expenses older than {{ $cfg['claims_max_age_days'] }} days cannot be claimed.</p>

@if(! $mine && can_do('claims.approve'))
<div class="card mt-2"><div class="card-head"><h3><x-icon name="tool"/> Settings</h3></div><div class="card-body">
  <form method="post" action="{{ route('claims.settings') }}" class="form-grid" id="claimSettings">@csrf
    <div class="field"><label for="f_claims_mileage_rate">Mileage rate (GH₵ per km)</label><input type="number" step="0.01" min="0" name="claims_mileage_rate" id="f_claims_mileage_rate" value="{{ $cfg['claims_mileage_rate'] }}" required></div>
    <div class="field"><label for="f_claims_receipt_threshold">Receipt required above (GH₵)</label><input type="number" step="0.01" min="0" name="claims_receipt_threshold" id="f_claims_receipt_threshold" value="{{ $cfg['claims_receipt_threshold'] }}" required></div>
    <div class="field"><label for="f_claims_max_age_days">Claim within (days)</label><input type="number" min="7" max="365" name="claims_max_age_days" id="f_claims_max_age_days" value="{{ $cfg['claims_max_age_days'] }}" required></div>
    <div><button class="btn btn-primary">Save</button></div>
  </form>
</div></div>
@endif

<div class="modal" id="claimModal"><div class="modal-box"><form method="post" action="{{ route('claims.store') }}">@csrf
  <div class="modal-head"><h3>New expense claim</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <x-field name="title" label="Title" required maxlength="150" placeholder="e.g. Kumasi supplier visit, 14–15 Sep"/>
    @if(! $mine && can_do('claims.approve'))<x-field name="employee_id" label="For staff member" type="select" :options="$employees" :placeholder="$me ? '— myself —' : '— Select —'" :value="$me?->id"/>@endif
    <x-field name="notes" label="Notes" type="textarea" maxlength="500"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="claimSave">Start claim</button></div>
</form></div></div>
@endsection
