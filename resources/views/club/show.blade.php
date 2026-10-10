@extends('layouts.app')
@section('title', $m->membership_no.' – '.$m->holder_name)
@section('crumbs') <a href="{{ route('club.index') }}">Health Club</a> / <a href="{{ route('club.members') }}">Members</a> / {{ $m->membership_no }} @endsection
@section('actions')
<a class="btn" href="{{ route('club.members.cards', $m) }}" target="_blank" id="printCards"><x-icon name="id"/> Print cards</a>
<a class="btn" href="{{ route('club.members.statement', $m) }}" target="_blank" id="stmtBtn"><x-icon name="print"/> Statement</a>
@if(can_do('club.members'))
  @if(in_array($m->status, ['active', 'frozen', 'expired']) && ! $m->cancel_mode)<button class="btn" data-modal="renewModal" id="renewBtn"><x-icon name="refresh"/> Renew</button>@endif
  <button class="btn btn-primary" data-modal="payModal" id="payBtn" data-f-amount="{{ $balance > 0 ? number_format($balance, 2, '.', '') : '' }}"><x-icon name="money"/> Take payment</button>
@endif
@endsection
@section('content')
@php $today = bdate()->toDateString(); $frozenNow = $m->frozen_to && $m->frozen_to->toDateString() >= $today; @endphp
<div class="stats" id="memberStats">
  <x-stat :label="$m->plan->name" :value="\App\Models\ClubMembership::STATUSES[$m->status]" icon="dumbbell" :tone="['active' => 'green', 'frozen' => 'blue', 'expired' => 'amber', 'cancelled' => 'slate'][$m->status]" :sub="money($m->plan->price, false).' / '.strtolower($m->plan->termLabel()).($m->cancel_mode === 'term_end' && $m->status !== 'cancelled' ? ' · leaving at term end' : ($m->auto_renew ? ' · auto-renews' : ' · does not auto-renew'))"/>
  <x-stat label="Membership term" :value="dmy($m->end_date)" icon="calendar" :sub="'since '.dmy($m->start_date).($frozenNow ? ' · frozen '.dmy($m->frozen_from, 'd/m').'–'.dmy($m->frozen_to, 'd/m') : '').' · '.$m->frozen_days.'/'.$m->plan->freeze_days_max.' freeze days used'"/>
  <x-stat label="Account balance" :value="money(abs($balance))" icon="wallet" :tone="$balance > 0.004 ? ($overdue[0] > 0 ? 'red' : 'amber') : 'green'" :sub="$balance > 0.004 ? ($overdue[0] > 0 ? money($overdue[0]).' overdue since '.dmy($overdue[1]).' – entry blocked' : 'due') : ($balance < -0.004 ? 'in credit' : 'settled')"/>
  <x-stat label="Visits – last 30 days" :value="$visits30" icon="chart" tone="purple" :sub="$passesLeft.' guest pass(es) left this month'"/>
</div>

@if($m->status !== 'cancelled' && can_do('club.members'))
<div class="flex gap-1 mb-1" style="flex-wrap:wrap" id="lifecycle">
  @if($frozenNow)<form method="post" action="{{ route('club.members.unfreeze', $m) }}">@csrf<button class="btn btn-sm" id="unfreezeBtn">{{ $m->frozen_from->toDateString() > $today ? 'Withdraw booked freeze' : 'End freeze now' }}</button></form>
  @elseif($m->status === 'active')<button class="btn btn-sm" data-modal="freezeModal" id="freezeBtn"><x-icon name="clock"/> Freeze</button>@endif
  @if($m->cancel_mode === 'term_end')<form method="post" action="{{ route('club.members.withdraw', $m) }}">@csrf<button class="btn btn-sm" id="withdrawBtn">Withdraw cancellation notice</button></form>@endif
  @if(can_do('club.manage'))<button class="btn btn-sm btn-danger" data-modal="cancelModal" id="cancelBtn"><x-icon name="x"/> Cancel membership</button>@endif
  @if(can_do('club.members'))<button class="btn btn-sm" data-modal="chargeModal" id="chargeBtn"><x-icon name="plus"/> Add charge</button>@endif
  @if($balance < -0.004 && can_do('club.manage'))<button class="btn btn-sm" data-modal="refundModal" id="refundBtn" data-f-amount="{{ number_format(-$balance, 2, '.', '') }}">Refund credit</button>@endif
</div>
@elseif($balance < -0.004 && can_do('club.manage'))
<div class="mb-1"><button class="btn btn-sm" data-modal="refundModal" id="refundBtn" data-f-amount="{{ number_format(-$balance, 2, '.', '') }}">Refund credit balance {{ money(-$balance) }}</button></div>
@endif

<div class="tabs" data-tabs>
  <button class="active" data-tab="account">Account</button>
  <button data-tab="people">Card holders ({{ $m->members->where('is_active', true)->count() }}/{{ $m->plan->max_persons }})</button>
  <button data-tab="details">Details</button>
  <button data-tab="activity">Visits &amp; classes</button>
</div>

<div class="tab-pane active" id="account">
  <div class="card flush"><div class="card-head"><h3>Charges</h3></div><div class="card-body table-wrap"><table class="table" id="chargesTable">
    <thead><tr><th>No.</th><th>Date</th><th>Description</th><th>Due</th><th class="num">Net</th><th class="num">Tax</th><th class="num">Total</th><th class="num">Settled</th><th></th></tr></thead>
    <tbody>@forelse($charges as $c)@php $st = $c->state(); @endphp
      <tr data-charge="{{ $c->charge_no }}" class="{{ $c->cancelled_at ? 'muted' : '' }}"><td class="bold"><a href="{{ route('club.charges.invoice', $c) }}" target="_blank">{{ $c->charge_no }}</a></td><td class="small">{{ dmy($c->date) }}</td>
        <td>{{ $c->description }}<div class="tiny muted">{{ \App\Models\ClubCharge::TYPES[$c->type] }}{{ $c->user ? ' · '.$c->user->name : '' }}</div>@if($c->cancelled_at)<div class="tiny text-red">cancelled: {{ $c->cancel_reason }}</div>@endif</td>
        <td class="small">{{ $c->type === 'credit' ? '' : dmy($c->due_date) }}</td><td class="num">{{ money($c->amount, false) }}</td><td class="num">{{ money($c->tax, false) }}</td><td class="num bold">{{ money($c->total, false) }}</td>
        <td class="num">@if($st === 'paid')<span class="badge badge-green">paid</span>@elseif($st === 'part')<span class="badge badge-amber">{{ money($c->paid, false) }}</span>@elseif($st === 'open')<span class="badge {{ $c->due_date->copy()->addDays((int) \App\Services\ClubService::cfg('club_grace_days'))->toDateString() < $today ? 'badge-red' : 'badge-slate' }}">open</span>@elseif($st === 'credit')<span class="badge badge-blue">credit</span>@endif</td>
        <td class="num" style="white-space:nowrap">@if(! $c->cancelled_at && can_do('club.manage'))
          @if($c->type !== 'credit' && \App\Services\ClubBillingService::creditable($c) > 0.004)<button class="btn btn-sm" data-modal="creditModal" data-title="Credit note on {{ $c->charge_no }}" data-action="{{ route('club.charges.credit', $c) }}" data-f-amount="{{ number_format(\App\Services\ClubBillingService::creditable($c), 2, '.', '') }}">Credit</button>@endif
          <button class="btn btn-sm btn-ghost" data-modal="cancelChargeModal" data-title="Cancel {{ $c->charge_no }}" data-action="{{ route('club.charges.cancel', $c) }}" title="Cancel">✕</button>@endif</td></tr>
    @empty <tr><td colspan="9"><x-empty text="No charges." icon="receipt"/></td></tr> @endforelse</tbody></table></div></div>

  <div class="card flush mt-1"><div class="card-head"><h3>Receipts &amp; refunds</h3></div><div class="card-body table-wrap"><table class="table" id="receiptsTable">
    <thead><tr><th>No.</th><th>Date</th><th>Mode</th><th>Reference</th><th>Settled</th><th class="num">Amount</th><th></th></tr></thead>
    <tbody>@forelse($payments as $p)
      <tr data-receipt="{{ $p->receipt_no }}" class="{{ $p->voided_at ? 'muted' : '' }}"><td class="bold"><a href="{{ route('club.receipts.show', $p) }}" target="_blank">{{ $p->receipt_no }}</a>@if($p->kind === 'refund') <span class="badge badge-amber">refund</span>@endif</td><td class="small">{{ dmy($p->date) }}</td>
        <td class="small">{{ $p->paymentMode?->name }}</td><td class="small">{{ $p->reference }}@if($p->voided_at)<div class="tiny text-red">void: {{ $p->void_reason }}</div>@endif</td>
        <td class="tiny">{{ collect($p->allocations ?? [])->map(fn ($a) => $a['charge_no'].' '.money($a['amount'], false))->implode(', ') }}</td>
        <td class="num bold">{{ $p->kind === 'refund' ? '−' : '' }}{{ money($p->amount, false) }}</td>
        <td class="num">@if(! $p->voided_at && can_do('club.manage'))<button class="btn btn-sm btn-ghost" data-modal="voidModal" data-title="Void {{ $p->receipt_no }}" data-action="{{ route('club.receipts.void', $p) }}">Void</button>@endif</td></tr>
    @empty <tr><td colspan="7"><x-empty text="No receipts yet." icon="money"/></td></tr> @endforelse</tbody></table></div></div>
  <p class="tiny muted">Receipts and credit notes settle the oldest charge first. Subscriptions are held as "Membership fees received in advance" (2130) and released to membership revenue (4042) day by day; joining fees and guest passes go to 4043 when charged.</p>
</div>

<div class="tab-pane" id="people">
  <div class="card flush"><div class="card-head"><h3>Card holders</h3>@if(can_do('club.members') && $m->members->where('is_active', true)->count() < $m->plan->max_persons && $m->status !== 'cancelled')<button class="btn btn-sm" data-modal="personModal" id="addPersonBtn"><x-icon name="plus"/> Add person</button>@endif</div>
    <div class="card-body table-wrap"><table class="table" id="peopleTable">
      <thead><tr><th></th><th>Name</th><th>Card</th><th>Relation</th><th>Age</th><th>PAR-Q</th><th></th></tr></thead>
      <tbody>@foreach($m->members as $p)
        <tr data-person="{{ $p->id }}" class="{{ $p->is_active ? '' : 'muted' }}"><td>@include('club._photo', ['p' => $p, 'size' => 'sm'])</td><td><b>{{ $p->name }}</b><div class="tiny muted">{{ $p->phone }}{{ $p->emergency_contact ? ' · ICE '.$p->emergency_contact : '' }}</div>@if(! $p->is_active)<div class="tiny text-red">card deactivated</div>@endif</td>
          <td class="small" style="font-family:ui-monospace,monospace">{{ $p->card_no }}</td><td class="small">{{ \App\Models\ClubMember::RELATIONS[$p->relation] }}</td><td class="small">{{ $p->age() ?? '—' }}</td>
          <td>{!! $p->health_declared_at ? '<span class="badge badge-green">'.e($p->health_declared_at->format('d/m/Y')).'</span>' : '<span class="badge badge-amber">missing</span>' !!}</td>
          <td class="num" style="white-space:nowrap"><a class="btn btn-sm" href="{{ route('club.members.cards', [$m, 'person' => $p->id]) }}" target="_blank">Card</a>
            @if(can_do('club.members'))<button class="btn btn-sm" data-modal="editPerson{{ $p->id }}">Edit</button><button class="btn btn-sm" data-modal="photoModal" data-title="Photo – {{ $p->name }}" data-action="{{ route('club.persons.photo', $p) }}">Photo</button>@endif</td></tr>
      @endforeach</tbody></table></div></div>
</div>

<div class="tab-pane" id="details">
  <div class="g-2 grid" style="gap:1rem">
    <div class="card"><div class="card-head"><h3>Membership</h3></div><div class="card-body"><dl class="kv">
      <dt>Number</dt><dd>{{ $m->membership_no }}</dd><dt>Plan</dt><dd>{{ $m->plan->name }} ({{ $m->plan->code }})</dd><dt>Facilities</dt><dd>{{ $m->plan->facilityNames() }}</dd>
      @if($m->plan->hoursLabel())<dt>Access hours</dt><dd>{{ $m->plan->hoursLabel() }}</dd>@endif
      <dt>Member benefits</dt><dd>{{ $m->plan->guest_passes }} guest passes/month · {{ (float) $m->plan->fnb_discount_pct }}% F&amp;B · {{ (float) $m->plan->spa_discount_pct }}% spa</dd>
      <dt>Source</dt><dd>{{ \App\Models\ClubMembership::SOURCES[$m->source] ?? $m->source }}</dd><dt>Created</dt><dd>{{ $m->created_at->format('d M Y H:i') }}{{ $m->creator ? ' by '.$m->creator->name : '' }}</dd>
      @if($m->freeze_reason)<dt>Freeze</dt><dd>{{ dmy($m->frozen_from) }} – {{ dmy($m->frozen_to) }} · {{ $m->freeze_reason }}</dd>@endif
      @if($m->cancel_reason)<dt>Cancellation</dt><dd>{{ $m->cancel_mode === 'immediate' ? 'Immediate' : 'At term end' }}{{ $m->cancelled_on ? ' · '.dmy($m->cancelled_on) : '' }} – {{ $m->cancel_reason }}</dd>@endif
    </dl></div></div>
    <div class="card"><div class="card-head"><h3>Holder &amp; billing</h3></div><div class="card-body">
      @if(can_do('club.members'))
      <form method="post" action="{{ route('club.members.update', $m) }}" id="detailsForm">@csrf @method('PUT')<div class="form-grid">
        <x-field name="holder_name" label="Holder / account name" :value="$m->holder_name" required class="span-2"/>
        <x-field name="phone" label="Phone" :value="$m->phone"/><x-field name="email" label="Email" type="email" :value="$m->email"/>
        <div class="field span-2"><label for="d_company">Paying company</label><select name="company_id" id="d_company"><option value="">— (the holder pays)</option>@foreach($companies as $c)<option value="{{ $c->id }}" @selected($m->company_id == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <x-field name="notes" label="Notes" :value="$m->notes" class="span-2"/>
        <label class="check span-2"><input type="checkbox" name="auto_renew" value="1" @checked($m->auto_renew) @disabled($m->cancel_mode)> <span>Auto-renew</span></label>
      </div><button class="btn btn-primary mt-1">Save</button></form>
      @else<dl class="kv"><dt>Holder</dt><dd>{{ $m->holder_name }}</dd><dt>Phone</dt><dd>{{ $m->phone }}</dd><dt>Email</dt><dd>{{ $m->email }}</dd><dt>Company</dt><dd>{{ $m->company?->name ?? '—' }}</dd></dl>@endif
    </div></div>
  </div>
</div>

<div class="tab-pane" id="activity">
  <div class="g-2 grid" style="gap:1rem">
    <div class="card flush"><div class="card-head"><h3>Recent visits</h3></div><div class="card-body table-wrap"><table class="table">
      <tbody>@forelse($visits as $v)<tr class="{{ $v->denied ? 'row-alert' : '' }}"><td class="small">{{ $v->checked_in_at->format('d/m H:i') }}</td><td>{{ $v->name }}<div class="tiny muted">{{ \App\Models\ClubVisit::TYPES[$v->visitor_type] }}</div></td><td class="small">{{ \App\Models\ClubPlan::FACILITIES[$v->facility] ?? '' }}</td>
        <td class="small">{{ $v->denied ? 'Refused – '.$v->deny_reason : ($v->checked_out_at ? $v->checked_in_at->diffForHumans($v->checked_out_at, true) : 'in') }}</td></tr>
      @empty <tr><td><x-empty text="No visits yet." icon="chart"/></td></tr> @endforelse</tbody></table></div></div>
    <div class="card flush"><div class="card-head"><h3>Class bookings</h3></div><div class="card-body table-wrap"><table class="table">
      <tbody>@forelse($bookings as $b)<tr><td class="small">{{ dmy($b->date, 'D d/m') }}</td><td>{{ $b->klass?->name }}<div class="tiny muted">{{ $b->name }}</div></td><td><span class="badge badge-{{ ['booked' => 'blue', 'attended' => 'green', 'no_show' => 'red', 'waitlist' => 'amber', 'cancelled' => 'slate'][$b->status] }}">{{ \App\Models\ClubBooking::STATUSES[$b->status] }}</span></td></tr>
      @empty <tr><td><x-empty text="No class bookings." icon="clock"/></td></tr> @endforelse</tbody></table></div></div>
  </div>
</div>

{{-- ───── modals ───── --}}
@if(can_do('club.members'))
<div class="modal" id="payModal"><div class="modal-box"><form method="post" action="{{ route('club.members.pay', $m) }}" data-reset>@csrf
  <div class="modal-head"><h3>Take payment – {{ $m->membership_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="amount" label="Amount (GH₵)" type="number" step="0.01" min="0.01" required/>
    <div class="field"><label for="p_mode">Mode <span class="req">*</span></label><select name="payment_mode_id" id="p_mode" required>@foreach($modes as $pm)<option value="{{ $pm->id }}">{{ $pm->name }}</option>@endforeach</select></div>
    <x-field name="reference" label="Reference" class="span-2" placeholder="MoMo transaction ID / card slip no."/>
  </div><p class="small">Balance due: <b>{{ money(max(0, $balance)) }}</b></p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="paySave">Receive</button></div>
</form></div></div>

<div class="modal" id="renewModal"><div class="modal-box"><form method="post" action="{{ route('club.members.renew', $m) }}" data-reset>@csrf
  <div class="modal-head"><h3>Renew {{ $m->membership_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="small">{{ $m->status === 'expired' ? 'The membership lapsed on '.dmy($m->end_date).' – the new term starts today.' : 'The next term starts on '.dmy($m->end_date->copy()->addDay()).'.' }}</p>
    <div class="field"><label for="rn_plan">Plan</label><select name="club_plan_id" id="rn_plan">@foreach($plans as $p)<option value="{{ $p->id }}" @selected($p->id === $m->club_plan_id)>{{ $p->name }} – {{ money($p->price, false) }} / {{ strtolower($p->termLabel()) }}{{ $p->max_persons < $m->members->where('is_active', true)->count() ? ' (too few cards)' : '' }}</option>@endforeach</select></div>
    <div class="form-grid"><x-field name="pay_amount" label="Payment now (optional)" type="number" step="0.01" min="0"/>
      <div class="field"><label for="rn_mode">Mode</label><select name="payment_mode_id" id="rn_mode"><option value="">—</option>@foreach($modes as $pm)<option value="{{ $pm->id }}">{{ $pm->name }}</option>@endforeach</select></div>
      <x-field name="reference" label="Reference" class="span-2"/></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="renewSave">Raise renewal</button></div>
</form></div></div>

<div class="modal" id="chargeModal"><div class="modal-box"><form method="post" action="{{ route('club.members.charge', $m) }}" data-reset>@csrf
  <div class="modal-head"><h3>Add a charge</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="type" label="Type" type="select" :options="['other' => 'Other charge', 'guest_pass' => 'Guest pass']" :placeholder="false"/>
    <x-field name="amount" label="Amount, excl. tax (GH₵)" type="number" step="0.01" min="0.01" required/>
    <x-field name="description" label="Description" class="span-2" required placeholder="Personal training – 4 sessions"/>
  </div><p class="tiny muted">VAT, NHIL, GETFund and the Tourism Levy are added. Income goes to 4043 when charged.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="chargeSave">Add charge</button></div>
</form></div></div>

<div class="modal" id="freezeModal"><div class="modal-box"><form method="post" action="{{ route('club.members.freeze', $m) }}" data-reset>@csrf
  <div class="modal-head"><h3>Freeze membership</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="from" label="From" type="date" :value="$today" :min="$today" required/>
    <x-field name="to" label="To" type="date" :value="bdate()->copy()->addDays(13)->toDateString()" required/>
    <x-field name="reason" label="Reason" class="span-2" required placeholder="Travelling / medical"/>
  </div><p class="small">{{ $m->plan->freeze_days_max - $m->frozen_days }} of {{ $m->plan->freeze_days_max }} freeze days left this term (minimum 7 days). The end date moves out by the days frozen – no charge.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="freezeSave">Freeze</button></div>
</form></div></div>

<div class="modal" id="personModal"><div class="modal-box"><form method="post" action="{{ route('club.persons.store', $m) }}" data-reset>@csrf
  <div class="modal-head"><h3>Add card holder</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="name" label="Name" required class="span-2"/>
    <x-field name="relation" label="Relation" type="select" :options="collect(\App\Models\ClubMember::RELATIONS)->except('primary')->all()" :placeholder="false"/>
    <x-field name="gender" label="Sex" type="select" :options="['M' => 'Male', 'F' => 'Female']"/>
    <x-field name="dob" label="Date of birth" type="date"/><x-field name="phone" label="Phone"/>
    <x-field name="emergency_contact" label="Emergency contact" class="span-2"/>
    <label class="check span-2"><input type="checkbox" name="health" value="1"> <span>Health declaration (PAR-Q) signed</span></label>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="personSave">Issue card</button></div>
</form></div></div>

@foreach($m->members as $p)
<div class="modal" id="editPerson{{ $p->id }}"><div class="modal-box"><form method="post" action="{{ route('club.persons.update', $p) }}">@csrf @method('PUT')
  <div class="modal-head"><h3>{{ $p->name }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-2"><label for="ep{{ $p->id }}_n">Name</label><input name="name" id="ep{{ $p->id }}_n" value="{{ $p->name }}" required></div>
    <div class="field"><label for="ep{{ $p->id }}_r">Relation</label>@if($p->relation === 'primary')<input id="ep{{ $p->id }}_r" value="Primary member" disabled>@else<select name="relation" id="ep{{ $p->id }}_r">@foreach(\App\Models\ClubMember::RELATIONS as $k => $l)@continue($k === 'primary')<option value="{{ $k }}" @selected($p->relation === $k)>{{ $l }}</option>@endforeach</select>@endif</div>
    <div class="field"><label for="ep{{ $p->id }}_g">Sex</label><select name="gender" id="ep{{ $p->id }}_g"><option value="">—</option><option value="M" @selected($p->gender === 'M')>Male</option><option value="F" @selected($p->gender === 'F')>Female</option></select></div>
    <div class="field"><label for="ep{{ $p->id }}_d">Date of birth</label><input type="date" name="dob" id="ep{{ $p->id }}_d" value="{{ $p->dob?->toDateString() }}"></div>
    <div class="field"><label for="ep{{ $p->id }}_p">Phone</label><input name="phone" id="ep{{ $p->id }}_p" value="{{ $p->phone }}"></div>
    <div class="field span-2"><label for="ep{{ $p->id }}_e">Emergency contact</label><input name="emergency_contact" id="ep{{ $p->id }}_e" value="{{ $p->emergency_contact }}"></div>
    <label class="check"><input type="checkbox" name="health" value="1" @checked($p->health_declared_at)> <span>PAR-Q signed</span></label>
    <label class="check"><input type="checkbox" name="is_active" value="1" @checked($p->is_active)> <span>Card active</span></label>
  </div></div>
  <div class="modal-foot"><button type="submit" class="btn btn-ghost" form="newCard{{ $p->id }}">Lost card – new number</button><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form><form method="post" action="{{ route('club.persons.card', $p) }}" id="newCard{{ $p->id }}" data-confirm="Issue a new card number? The old card stops working.">@csrf</form></div></div>
@endforeach

<div class="modal" id="photoModal"><div class="modal-box"><form method="post" action="" enctype="multipart/form-data" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Photo</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label for="ph_file">Photo (JPG / PNG, max 2 MB)</label><input type="file" name="photo" id="ph_file" accept="image/jpeg,image/png,image/webp" capture="user" required></div>
    <p class="tiny muted">Stored privately and shown only to signed-in staff and on the printed card.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="photoSave">Upload</button></div>
</form></div></div>
@endif

@if(can_do('club.manage'))
<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('club.members.cancel', $m) }}" data-reset id="cancelForm">@csrf
  <div class="modal-head"><h3>Cancel {{ $m->membership_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <div class="field"><label>How does it end?</label>
      <label class="check"><input type="radio" name="mode" value="term_end" checked> <span>At the end of the paid term ({{ dmy($m->end_date) }}) – no renewal</span></label>
      <label class="check"><input type="radio" name="mode" value="immediate"> <span>Immediately – access stops today</span></label></div>
    <div class="field" id="creditField" hidden><label for="cn_credit">Credit note, excl. tax (GH₵)</label><input type="number" step="0.01" min="0" name="credit" id="cn_credit" value="{{ number_format($unused['amount'], 2, '.', '') }}">
      <span class="hint">Suggested: unused {{ $unused['days'] }} day(s) of {{ $unused['charge']?->charge_no ?? 'the current term' }}. Enter 0 for no refund (e.g. inside the minimum term).</span></div>
    <x-field name="reason" label="Reason" required placeholder="Relocating abroad"/>
    <p class="tiny muted">Renewal invoices for periods that have not started are cancelled either way.</p>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger" id="cancelSave">Cancel membership</button></div>
</form></div></div>

<div class="modal" id="refundModal"><div class="modal-box"><form method="post" action="{{ route('club.members.refund', $m) }}" data-reset>@csrf
  <div class="modal-head"><h3>Refund credit balance</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <x-field name="amount" label="Amount (GH₵)" type="number" step="0.01" min="0.01" required/>
    <div class="field"><label for="rf_mode">Paid out by</label><select name="payment_mode_id" id="rf_mode" required>@foreach($modes as $pm)<option value="{{ $pm->id }}">{{ $pm->name }}</option>@endforeach</select></div>
    <x-field name="reference" label="Reference" class="span-2"/>
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="refundSave">Refund</button></div>
</form></div></div>

<div class="modal" id="creditModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Credit note</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="amount" label="Credit, excl. tax (GH₵)" type="number" step="0.01" min="0.01" required/><x-field name="reason" label="Reason" required placeholder="Pool closed for repairs 5 days"/>
    <p class="tiny muted">Tax is credited at the charge's rate. The credit settles the account (oldest charge first); any excess can be refunded.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="creditSave">Issue credit note</button></div>
</form></div></div>

<div class="modal" id="cancelChargeModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Cancel charge</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required placeholder="Raised in error"/><p class="tiny muted">Allowed on the day it was raised, or before the service period starts. Otherwise issue a credit note. Money already received stays on the account as credit.</p></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger" id="cancelChargeSave">Cancel charge</button></div>
</form></div></div>

<div class="modal" id="voidModal"><div class="modal-box"><form method="post" action="" data-reset>@csrf
  <div class="modal-head"><h3 data-title>Void receipt</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><x-field name="reason" label="Reason" required placeholder="MoMo payment reversed"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-danger" id="voidSave">Void</button></div>
</form></div></div>
@endif
@endsection
@push('scripts')
<script>(() => { const f = document.getElementById('cancelForm'); if (!f) return; const s = () => document.getElementById('creditField').hidden = f.querySelector('[name=mode]:checked').value !== 'immediate'; f.querySelectorAll('[name=mode]').forEach(r => r.addEventListener('change', s)); s(); })();</script>
@endpush
