{{-- Guest capture block with live lookup of existing profiles --}}
<input type="hidden" name="guest_id" id="guest_id" value="{{ old('guest_id', $guest->id) }}">
<div class="form-grid">
  <div class="field span-all" style="position:relative">
    <label>Find existing guest (name / phone / email / ID)</label>
    <input type="text" id="guestLookup" placeholder="Start typing to search guest history…" autocomplete="off">
    <div class="dropdown" id="guestResults" style="left:0;right:auto;width:100%;max-height:260px;overflow:auto"></div>
  </div>
  <x-field name="guest[title]" label="Title" type="select" :options="['Mr'=>'Mr','Mrs'=>'Mrs','Ms'=>'Ms','Dr'=>'Dr','Prof'=>'Prof']" :value="$guest->title" placeholder="—"/>
  <x-field name="guest[first_name]" label="First name" :value="$guest->first_name" required/>
  <x-field name="guest[last_name]" label="Last name" :value="$guest->last_name"/>
  <x-field name="guest[phone]" label="Mobile" :value="$guest->phone" required/>
  <x-field name="guest[email]" label="Email" type="email" :value="$guest->email"/>
  <x-field name="guest[nationality]" label="Nationality" :value="$guest->nationality ?? 'Ghana'"/>
  <x-field name="guest[city]" label="City" :value="$guest->city"/>
  <x-field name="guest[id_type]" label="ID type" type="select" :options="config('pms.id_types')" :value="$guest->id_type"/>
  <x-field name="guest[id_number]" label="ID number" :value="$guest->id_number"/>
  @isset($fullGuest)
  <x-field name="guest[gender]" label="Gender" type="select" :options="['Male'=>'Male','Female'=>'Female','Other'=>'Other']" :value="$guest->gender"/>
  <x-field name="guest[dob]" label="Date of birth" type="date" :value="optional($guest->dob)->toDateString()"/>
  <x-field name="guest[address]" label="Address" :value="$guest->address" class="span-2"/>
  <x-field name="guest[passport_no]" label="Passport no. (foreigners)" :value="$guest->passport_no"/>
  <x-field name="guest[visa_expiry]" label="Visa expiry" type="date" :value="optional($guest->visa_expiry)->toDateString()"/>
  @endisset
</div>
@push('scripts')
<script>
(function(){
  const inp = document.getElementById('guestLookup'), box = document.getElementById('guestResults'); let t;
  inp.addEventListener('input', () => { clearTimeout(t); const q = inp.value.trim(); if (q.length < 2) { box.classList.remove('show'); return; }
    t = setTimeout(() => getJSON('{{ route('reservations.api.guests') }}?q=' + encodeURIComponent(q)).then(list => {
      box.innerHTML = list.length ? '' : '<div class="small muted" style="padding:.5rem">No match – fill details below to create a new profile.</div>';
      list.forEach(g => { const b = document.createElement('button'); b.type = 'button';
        b.innerHTML = '<b>' + [g.title, g.first_name, g.last_name].filter(Boolean).join(' ') + '</b>&nbsp;<span class="small muted">' + (g.phone || '') + ' ' + (g.email || '') + '</span>' + (g.is_blacklisted ? ' <span class="badge badge-red">Blacklisted</span>' : '') + (g.is_vip ? ' <span class="badge badge-purple">VIP</span>' : '');
        b.onclick = () => { document.getElementById('guest_id').value = g.id; ['title','first_name','last_name','phone','email','nationality','city','id_type','id_number'].forEach(k => { const el = document.querySelector('[name="guest[' + k + ']"]'); if (el) el.value = g[k] || ''; }); box.classList.remove('show'); inp.value = ''; };
        box.appendChild(b); }); box.classList.add('show'); }), 250); });
  document.querySelector('[name="guest[first_name]"]').addEventListener('input', () => {}); 
})();
</script>
@endpush
