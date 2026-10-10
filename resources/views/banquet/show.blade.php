@extends('layouts.app')
@section('title', $e->event_no.' · '.($e->title ?: $e->contact_name))
@section('crumbs') <a href="{{ route('banquet.index') }}">Banquet</a> / {{ $e->event_no }} @endsection
@section('actions')
<span id="evStatus">{!! $e->statusBadge() !!}</span>
<a class="btn" href="{{ route('banquet.quote', $e) }}" target="_blank" id="quoteBtn"><x-icon name="receipt"/> Proposal</a>
<a class="btn" href="{{ route('banquet.print', $e) }}" target="_blank" id="beoBtn"><x-icon name="print"/> BEO</a>
@if(can_do('banquet.manage'))
  @if($e->isEditable())<a class="btn" href="{{ route('banquet.edit', $e) }}" id="editEvent"><x-icon name="edit"/> Edit</a>@endif
  @if(in_array($e->status, ['enquiry', 'tentative']))
    <button class="btn" data-modal="holdModal" id="holdBtn"><x-icon name="clock"/> {{ $e->status === 'tentative' ? 'Extend option' : 'Hold tentative' }}</button>
    <form method="post" action="{{ route('banquet.confirm', $e) }}" class="inline">@csrf<button class="btn btn-primary" id="confirmBtn"><x-icon name="check"/> Confirm definite</button></form>
    <button class="btn btn-ghost text-red" data-modal="lostModal" id="lostBtn">Lost</button>
  @endif
  @if($e->status === 'lost')<form method="post" action="{{ route('banquet.reopen', $e) }}" class="inline">@csrf<button class="btn" id="reopenBtn"><x-icon name="refresh"/> Re-open</button></form>@endif
  @if($e->status === 'definite' && ! $e->billed_at)<button class="btn btn-ghost text-red" data-modal="cancelModal" id="cancelBtn">Cancel booking</button>@endif
@endif
@if($e->readyToBill() && can_do('banquet.billing'))<form method="post" action="{{ route('banquet.bill', $e) }}" class="inline">@csrf<button class="btn btn-primary" id="billBtn"><x-icon name="receipt"/> Bill event</button></form>@endif
@endsection
@section('content')
@php $pct = $e->deposit_due > 0 ? min(100, round($deposits / $e->deposit_due * 100)) : null; @endphp
@if($e->optionExpired())<div class="alert alert-error"><b>Option expired</b> on {{ $e->option_date->format('d M Y') }}. The hold will be released at the next night audit unless you extend it or confirm.</div>
@elseif($e->status === 'tentative')<div class="alert alert-warn">Venue held <b>until {{ $e->option_date->format('D d M Y') }}</b> ({{ (int) bdate()->diffInDays($e->option_date) }} day(s)). Confirm before then or it is released automatically.</div>@endif
@if($e->status === 'lost')<div class="alert alert-warn"><b>Lost</b>{{ $e->closed_at ? ' on '.$e->closed_at->format('d M Y') : '' }}: {{ $e->lost_reason }}</div>@endif
@if($e->status === 'cancelled')<div class="alert alert-error"><b>Cancelled</b> by {{ $e->closer?->name }} on {{ $e->closed_at?->format('d M Y') }}: {{ $e->cancel_reason }}. Cancellation fee {{ money($e->cancellation_fee) }}.</div>@endif
@if($e->portal_enabled && can_do('organiser.view'))<div class="alert alert-info" id="orgEvent"><x-icon name="globe"/><div><b>On the organiser portal</b> – the client follows this event online. @if($e->beo_approved_at)BEO signed by {{ $e->beo_sign_name }} on {{ $e->beo_approved_at->format('d M Y') }}.@elseif($e->beo_published_at)BEO published {{ $e->beo_published_at->format('d M Y') }}, awaiting signature.@else No BEO published yet.@endif <a href="{{ route('organiser-admin.event', $e->id) }}">Portal page</a></div></div>@endif
@if($e->billed_at)<div class="alert alert-success">Billed on {{ $e->billed_at->format('d M Y H:i') }} to folio @if(can_do('folio.view'))<a href="{{ route('folios.show', $folio) }}"><b>{{ $folio?->folio_no }}</b></a>@else<b>{{ $folio?->folio_no }}</b>@endif{{ $folio?->status === 'closed' ? ' – settled, invoice '.$folio->invoice_no : ' – balance '.money($folio?->balance()) }}.</div>@endif
@if($e->readyToBill())<div class="alert alert-info">All functions have taken place. Record the actual attendance, then <b>Bill event</b> – charges go onto folio {{ $folio?->folio_no ?? '(opened on billing)' }} with the deposits.</div>@endif

<div class="stats req5">
  <x-stat label="Client" :value="$e->company?->name ?? $e->contact_name" icon="users" tone="blue" :sub="\App\Models\BanquetEvent::TYPES[$e->event_type] ?? $e->event_type"/>
  <x-stat label="Date(s)" :value="$e->event_date->format('d M')" icon="calendar" tone="purple" :sub="($e->end_date && ! $e->end_date->eq($e->event_date) ? 'to '.$e->end_date->format('d M Y') : $e->event_date->format('l Y')).' · '.$e->functions->count().' function(s)'"/>
  <x-stat label="Guests" :value="$e->pax()" icon="user" tone="slate" :sub="'peak billable pax'"/>
  <x-stat label="Value" :value="money($e->net())" icon="money" tone="green" :sub="'+ tax '.money($e->tax(), false).' = '.money($e->total(), false)"/>
  <x-stat label="Deposits" :value="money($deposits)" icon="wallet" :tone="$pct === null ? 'slate' : ($pct >= 100 ? 'green' : 'amber')" :sub="$e->deposit_due > 0 ? $pct.'% of '.money($e->deposit_due, false).' required' : 'none required'"/>
</div>

<div class="grid g-2-1">
<div>
  @foreach($e->functions as $f)
  <div class="card fn-show" id="fn{{ $f->id }}">
    <div class="card-head"><h3>{{ $f->name }}</h3><span class="small muted">{{ $f->date->format('D d M Y') }} · {{ $f->timeRange() }} · <b>{{ $f->hall->name }}</b> · {{ \App\Models\BanquetFunction::SETUPS[$f->setup_style] ?? $f->setup_style }}</span></div>
    <div class="card-body">
      <div class="fn-pax">
        <div><span>Expected</span><b>{{ $f->expected_pax }}</b></div>
        <div><span>Guaranteed</span><b>{{ $f->guaranteed_pax ?? '—' }}</b>@if($e->isOpen())<em class="{{ $f->guaranteeLocked() ? 'text-amber' : '' }}">{{ $f->guaranteeLocked() ? 'final since' : 'due' }} {{ $f->guaranteeCutoff()->format('d M') }}</em>@endif</div>
        <div><span>Actual</span><b>{{ $f->actual_pax ?? '—' }}</b></div>
        <div><span>Billable</span><b class="text-blue">{{ $f->billablePax() }}</b></div>
      </div>
      <table class="table mt-1"><tbody>
        @if($f->rate_per_pax > 0 || $f->package)<tr><td>{{ $f->package?->name ?? 'Food & beverage' }}<div class="tiny muted">{{ $f->billablePax() }} × {{ money($f->rate_per_pax, false) }} · tax {{ rtrim(rtrim(number_format($f->fnb_tax_rate, 2), '0'), '.') }}%</div></td><td class="num">{{ money($f->fnbAmount(), false) }}</td></tr>@endif
        <tr><td>Venue – {{ $f->hall->name }}<div class="tiny muted">{{ $f->hours() }} h</div></td><td class="num">{{ money($f->hall_rate, false) }}</td></tr>
        @foreach($f->items as $it)<tr><td>{{ $it->description }}<div class="tiny muted">{{ rtrim(rtrim(number_format($it->qty, 2), '0'), '.') }} × {{ money($it->unit_price, false) }}{{ $it->service?->department ? ' · '.$it->service->department->name : '' }}</div></td><td class="num">{{ money($it->amount(), false) }}</td></tr>@endforeach
        <tr class="bold"><td>Function total <span class="tiny muted">(tax {{ money($f->tax(), false) }})</span></td><td class="num">{{ money($f->net(), false) }}</td></tr>
      </tbody></table>
      @if($f->package && $f->package->itemList())<div class="menu-list"><b class="tiny muted">MENU</b> {{ implode(' · ', $f->package->itemList()) }}</div>@endif
      @if($f->notes)<div class="small mt-1" style="white-space:pre-line"><b class="tiny muted">NOTES</b> {{ $f->notes }}</div>@endif
    </div>
  </div>
  @endforeach

  @if($e->status === 'definite' && ! $e->billed_at && $e->functions->contains(fn ($f) => $f->date->lte(bdate())) && can_do('banquet.manage'))
  <div class="card" id="actualsCard"><div class="card-head"><h3>Actual attendance</h3><span class="tiny muted">charged on the higher of guarantee and actual</span></div>
    <form method="post" action="{{ route('banquet.actuals', $e) }}" class="card-body flex gap-1 items-end" style="flex-wrap:wrap">@csrf
      @foreach($e->functions->filter(fn ($f) => $f->date->lte(bdate())) as $f)
        <div class="field"><label>{{ $f->name }} <span class="tiny muted">guar. {{ $f->guaranteed_pax ?? $f->expected_pax }}</span></label><input type="number" min="0" name="actual[{{ $f->id }}]" value="{{ $f->actual_pax }}" class="input" style="width:120px"></div>
      @endforeach
      <button class="btn btn-primary" id="saveActuals"><x-icon name="check"/> Save attendance</button>
    </form>
  </div>
  @endif
</div>

<div>
  <div class="card" id="depositCard"><div class="card-head"><h3>Deposits &amp; folio</h3>@if($folio)@if(can_do('folio.view'))<a class="small" href="{{ route('folios.show', $folio) }}">{{ $folio->folio_no }}</a>@else<span class="small muted">{{ $folio->folio_no }}</span>@endif @endif</div>
    <div class="card-body">
      @if($e->deposit_due > 0)<div class="mb-1"><div class="bq-bar"><span style="width:{{ $pct }}%" class="{{ $pct >= 100 ? 'ok' : '' }}"></span></div><div class="tiny muted mt-1">{{ money($deposits) }} of {{ money($e->deposit_due) }} required{{ $e->deposit_due_date ? ' by '.$e->deposit_due_date->format('d M') : '' }}</div></div>@endif
      <ul class="bq-list">
        @forelse($txns as $t)
          <li><span>{{ $t->description }}<div class="tiny muted">{{ $t->business_date->format('d M Y') }}{{ $t->reference ? ' · '.$t->reference : '' }}</div></span><b class="{{ $t->type === 'charge' ? '' : ($t->type === 'refund' ? 'text-red' : 'text-green') }}">{{ $t->type === 'charge' ? '' : ($t->type === 'refund' ? '+' : '−') }}{{ money($t->type === 'charge' ? $t->amount + $t->tax_amount : $t->amount, false) }}</b></li>
        @empty<li class="muted small">No deposit taken yet.</li>@endforelse
        @if($folio)<li class="bold"><span>{{ $folio->balance() < 0 ? 'Credit (unused deposit)' : 'Balance due' }}</span><b>{{ money(abs($folio->balance()), false) }}</b></li>@endif
      </ul>
      @if(can_do('banquet.billing') && $e->isOpen() && ! $e->billed_at)
      <form method="post" action="{{ route('banquet.deposit', $e) }}" class="deposit-form mt-1" id="depositForm">@csrf
        <div class="flex gap-1"><input type="number" name="amount" step="0.01" min="0.01" class="input" required placeholder="Amount" value="{{ $e->deposit_due > $deposits ? round($e->deposit_due - $deposits, 2) : ($deposits ? '' : $suggestDeposit) }}">
        <select name="payment_mode_id" class="input" required data-payment-mode>@foreach($modes as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>
        <div class="flex gap-1 mt-1"><input name="reference" class="input grow" maxlength="60" placeholder="Reference (cheque / UTR / receipt)"><button class="btn btn-primary" id="takeDeposit"><x-icon name="wallet"/> Receive</button></div>
        <x-cheque-fields class="span-all"/>
      </form>
      @endif
      @if(can_do('banquet.billing') && $folio && $folio->status === 'open' && $folio->balance() < -0.009 && in_array($e->status, ['cancelled', 'lost', 'completed']))
      <form method="post" action="{{ route('banquet.refund', $e) }}" class="mt-1" id="refundForm">@csrf
        <div class="flex gap-1"><input type="number" name="amount" step="0.01" min="0.01" class="input" value="{{ round(-$folio->balance(), 2) }}" max="{{ round(-$folio->balance(), 2) }}">
        <select name="payment_mode_id" class="input" data-payment-mode>@foreach($modes as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select>
        <input name="reference" class="input" maxlength="60" placeholder="Reference">
        <button class="btn btn-danger" id="refundBtn">Refund</button></div>
        <x-cheque-fields class="span-all"/>
      </form>
      @endif
    </div>
  </div>
  <div class="card"><div class="card-head"><h3>Details</h3></div><div class="card-body"><dl class="kv">
    <dt>Contact</dt><dd>{{ $e->contact_name }}</dd>
    @if($e->contact_phone)<dt>Phone</dt><dd>{{ $e->contact_phone }}</dd>@endif
    @if($e->contact_email)<dt>E-mail</dt><dd>{{ $e->contact_email }}</dd>@endif
    <dt>Bill to</dt><dd>{{ $e->company?->name ?? 'Private client' }}</dd>
    <dt>Source</dt><dd>{{ \App\Models\BanquetEvent::SOURCES[$e->source] ?? '—' }}</dd>
    <dt>Sales person</dt><dd>{{ $e->salesUser?->name ?? '—' }}</dd>
    @if($e->roomBlock)<dt>Room block</dt><dd><a href="{{ route('blocks.show', $e->roomBlock) }}">{{ $e->roomBlock->block_no }}</a> <span class="tiny muted">{{ $e->roomBlock->name }}</span></dd>@endif
    @if($e->confirmed_at)<dt>Confirmed</dt><dd>{{ $e->confirmed_at->format('d M Y') }}</dd>@endif
    <dt>Created</dt><dd>{{ $e->created_at?->format('d M Y') }}</dd>
  </dl>
  @if($e->requirements)<div class="small mt-1" style="white-space:pre-line"><b class="tiny muted">REQUIREMENTS</b><br>{{ $e->requirements }}</div>@endif
  @if($e->internal_notes)<div class="small mt-1 text-amber" style="white-space:pre-line"><b class="tiny">INTERNAL</b><br>{{ $e->internal_notes }}</div>@endif
  </div></div>
</div>
</div>

@if(in_array($e->status, ['enquiry', 'tentative']))
<div class="modal" id="holdModal"><div class="modal-box"><form method="post" action="{{ route('banquet.tentative', $e) }}">@csrf
  <div class="modal-head"><h3>{{ $e->status === 'tentative' ? 'Extend the option on' : 'Hold venue for' }} {{ $e->event_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="m-0 mb-1 small">The venue(s) are blocked for this client until the option date. If they haven't confirmed by then, the hold is released automatically at the night audit.</p>
    <div class="field"><label>Option date</label><input type="date" name="option_date" class="input" required min="{{ bdate()->toDateString() }}" max="{{ $e->event_date->toDateString() }}" value="{{ ($e->option_date ?? bdate()->copy()->addDays(7))->min($e->event_date)->toDateString() }}"></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-primary" id="confirmHold">Hold</button></div>
</form></div></div>
<div class="modal" id="lostModal"><div class="modal-box"><form method="post" action="{{ route('banquet.lost', $e) }}">@csrf
  <div class="modal-head"><h3>Mark {{ $e->event_no }} as lost</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="field"><label>Reason</label><input name="lost_reason" class="input" required maxlength="255" list="lostReasons" placeholder="e.g. price – went to a competitor"><datalist id="lostReasons"><option value="Price – went to a competitor"><option value="Date not available"><option value="Venue too small / too large"><option value="Event postponed indefinitely"><option value="No response from client"></datalist></div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger" id="confirmLost">Mark lost</button></div>
</form></div></div>
@endif
@if($quote)
<div class="modal" id="cancelModal"><div class="modal-box"><form method="post" action="{{ route('banquet.cancel', $e) }}">@csrf
  <div class="modal-head"><h3>Cancel {{ $e->event_no }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body">
    <p class="m-0 mb-1">{{ $quote['days'] }} day(s) before the event: the policy charges <b>{{ rtrim(rtrim(number_format($quote['pct'], 2), '0'), '.') }}%</b> of {{ money($quote['base']) }} = <b id="cancelFee">{{ money($quote['fee']) }}</b> (+ tax). Deposits received: {{ money($deposits) }}.</p>
    <div class="field"><label>Reason</label><input name="cancel_reason" class="input" required maxlength="200" placeholder="e.g. client postponed the wedding"></div>
    @if(can_do('banquet.billing') && $quote['fee'] > 0)
      <label class="check mt-1"><input type="checkbox" name="waive" value="1" id="waiveFee"> Waive the cancellation fee</label>
      <div class="field"><input name="waive_reason" class="input" maxlength="200" placeholder="Why the fee is waived (required if waived)"></div>
    @endif
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Back</button><button class="btn btn-danger" id="confirmCancel">Cancel booking</button></div>
</form></div></div>
@endif
@endsection
