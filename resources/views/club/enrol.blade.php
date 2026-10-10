@extends('layouts.app')
@section('title', 'Enrol Member')
@section('crumbs') <a href="{{ route('club.index') }}">Health Club</a> / <a href="{{ route('club.members') }}">Members</a> / Enrol @endsection
@section('content')
<form method="post" action="{{ route('club.members.store') }}" id="enrolForm">@csrf
<div class="fs-split">
  <div>
    <div class="card"><div class="card-head"><h3>1 · Plan</h3></div><div class="card-body">
      <div class="field"><label for="f_club_plan_id">Membership plan <span class="req">*</span></label><select name="club_plan_id" id="f_club_plan_id" required><option value="">— Select —</option>
        @foreach($plans->groupBy('category') as $cat => $ps)<optgroup label="{{ \App\Models\ClubPlan::CATEGORIES[$cat] ?? $cat }}">@foreach($ps as $p)
          <option value="{{ $p->id }}" data-price="{{ $p->price }}" data-join="{{ $p->joining_fee }}" data-max="{{ $p->max_persons }}" data-term="{{ $p->term_months }}" data-cat="{{ $p->category }}" data-info="{{ $p->facilityNames() }}{{ $p->hoursLabel() ? ' · '.$p->hoursLabel().' only' : '' }}{{ $p->guest_passes ? ' · '.$p->guest_passes.' guest passes/month' : '' }}{{ (float) $p->fnb_discount_pct ? ' · '.(float) $p->fnb_discount_pct.'% F&B' : '' }}{{ (float) $p->spa_discount_pct ? ' · '.(float) $p->spa_discount_pct.'% spa' : '' }}" @selected(old('club_plan_id') == $p->id)>{{ $p->name }} – {{ money($p->price, false) }} / {{ strtolower($p->termLabel()) }}</option>
        @endforeach</optgroup>@endforeach</select><span class="hint" id="planInfo"></span></div>
      <div class="form-grid">
        <x-field name="start_date" label="Start date" type="date" :value="bdate()->toDateString()" required/>
        <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="auto_renew" value="1" checked> <span>Auto-renew at the end of the term</span></label></div>
        <div class="field" id="companyField" hidden><label for="f_company_id">Paying company</label><select name="company_id" id="f_company_id"><option value="">—</option>@foreach($companies as $c)<option value="{{ $c->id }}" @selected(old('company_id') == $c->id)>{{ $c->name }}</option>@endforeach</select></div>
        <x-field name="source" label="Source" type="select" :options="\App\Models\ClubMembership::SOURCES" :placeholder="false"/>
      </div>
    </div></div>

    <div class="card mt-1"><div class="card-head"><h3>2 · Membership holder</h3></div><div class="card-body"><div class="form-grid">
      <x-field name="holder_name" label="Holder / account name" required class="span-2"/>
      <x-field name="phone" label="Phone" placeholder="024 000 0000"/>
      <x-field name="email" label="Email" type="email"/>
      <x-field name="notes" label="Notes" class="span-2"/>
    </div></div></div>

    <div class="card mt-1"><div class="card-head"><h3>3 · Card holders</h3><span class="small muted" id="maxInfo"></span></div><div class="card-body">
      <div class="table-wrap"><table class="table table-compact club-persons" id="personsTable"><thead><tr><th>Name</th><th>Relation</th><th>Sex</th><th>Date of birth</th><th>Phone</th><th title="Health declaration (PAR-Q) signed">PAR-Q</th></tr></thead><tbody>
        @for($i = 0; $i < 4; $i++)
          <tr data-person="{{ $i }}" @if($i > 0) hidden @endif><td><input name="persons[{{ $i }}][name]" value="{{ old("persons.$i.name") }}" placeholder="{{ $i === 0 ? 'Same as holder if blank' : '' }}" aria-label="Name {{ $i + 1 }}"></td>
            <td>@if($i === 0)<span class="small">Primary</span><input type="hidden" name="persons[0][relation]" value="primary">@else<select name="persons[{{ $i }}][relation]" aria-label="Relation {{ $i + 1 }}">@foreach(\App\Models\ClubMember::RELATIONS as $k => $l)@continue($k === 'primary')<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>@endif</td>
            <td><select name="persons[{{ $i }}][gender]" aria-label="Sex {{ $i + 1 }}"><option value="">—</option><option value="M">M</option><option value="F">F</option></select></td>
            <td><input type="date" name="persons[{{ $i }}][dob]" max="{{ bdate()->copy()->subDay()->toDateString() }}" aria-label="Date of birth {{ $i + 1 }}"></td>
            <td><input name="persons[{{ $i }}][phone]" aria-label="Phone {{ $i + 1 }}"></td>
            <td><input type="checkbox" name="persons[{{ $i }}][health]" value="1" aria-label="PAR-Q {{ $i + 1 }}" {{ $i === 0 ? 'checked' : '' }}></td></tr>
        @endfor
      </tbody></table></div>
      <p class="tiny muted">Each person gets a card with a QR code. Photos can be added after enrolment. Under-16s may not use the gym.</p>
    </div></div>
  </div>

  <div>
    <div class="card sticky-side" id="enrolSummary"><div class="card-head"><h3>Summary</h3></div><div class="card-body">
      <ul class="list">
        <li><span>Subscription</span><b id="sSub">—</b></li>
        <li><span>Joining fee</span><b id="sJoin">—</b></li>
        <li><span>Tax ({{ rtrim(rtrim(number_format($tax, 2), '0'), '.') }}% VAT, NHIL, GETFund, Tourism Levy)</span><b id="sTax">—</b></li>
        <li><span class="bold">Total due</span><b id="sTotal">—</b></li>
      </ul>
      @if(can_do('club.manage'))<label class="check mt-1"><input type="checkbox" name="waive_joining" value="1" id="waive"> <span>Waive the joining fee (promotion)</span></label>@endif
      <div class="form-section mt-1"><b class="small">Payment now (optional)</b>
        <div class="form-grid">
          <x-field name="pay_amount" label="Amount" type="number" step="0.01" min="0"/>
          <div class="field"><label for="f_mode">Mode</label><select name="payment_mode_id" id="f_mode"><option value="">—</option>@foreach($modes as $pm)<option value="{{ $pm->id }}">{{ $pm->name }}</option>@endforeach</select></div>
          <x-field name="reference" label="Reference" class="span-2" placeholder="MoMo txn ID / card slip"/>
        </div>
      </div>
      <button class="btn btn-primary btn-block mt-1" id="enrolSave"><x-icon name="check"/> Create membership</button>
      <p class="tiny muted">The subscription is invoiced now and earned day by day over the term; the joining fee is income today.</p>
    </div></div>
  </div>
</div>
</form>
@endsection
@push('scripts')
<script>
(() => { const sel = document.getElementById('f_club_plan_id'), waive = document.getElementById('waive'), rate = {{ $tax }} / 100, fmt = v => 'GH₵ ' + v.toLocaleString('en-GH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  const sync = () => { const o = sel.selectedOptions[0], ok = o && o.value; const price = ok ? +o.dataset.price : 0, join = ok && !(waive && waive.checked) ? +o.dataset.join : 0, max = ok ? +o.dataset.max : 1;
    document.getElementById('planInfo').textContent = ok ? o.dataset.info : ''; document.getElementById('maxInfo').textContent = ok ? 'up to ' + max + ' person(s)' : '';
    document.querySelectorAll('[data-person]').forEach(r => { r.hidden = +r.dataset.person >= max; if (r.hidden) r.querySelector('input').value = ''; });
    document.getElementById('companyField').hidden = !(ok && o.dataset.cat === 'corporate');
    const tax = Math.round((price + join) * rate * 100) / 100;
    document.getElementById('sSub').textContent = ok ? fmt(price) : '—'; document.getElementById('sJoin').textContent = ok ? fmt(join) : '—';
    document.getElementById('sTax').textContent = ok ? fmt(tax) : '—'; document.getElementById('sTotal').textContent = ok ? fmt(price + join + tax) : '—'; };
  sel.addEventListener('change', sync); waive && waive.addEventListener('change', sync); sync(); })();
</script>
@endpush
