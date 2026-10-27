{{-- Guest capture block with live lookup of existing profiles --}}
<input type="hidden" name="guest_id" id="guest_id" value="{{ old('guest_id', $guest->id) }}">
<div class="form-grid">
  <div class="field span-all" style="position:relative">
    <label for="guestLookup">Find existing guest (name / phone / email / ID)</label>
    <input type="text" id="guestLookup" maxlength="100" placeholder="Start typing to search guest history…" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="guestResults" aria-expanded="false">
    <div class="dropdown" id="guestResults" role="listbox" aria-label="Matching guest profiles" style="left:0;right:auto;width:100%;max-height:260px;overflow:auto"></div>
  </div>
  <x-field name="guest[title]" label="Title" type="select" :options="['Mr'=>'Mr','Mrs'=>'Mrs','Ms'=>'Ms','Dr'=>'Dr','Prof'=>'Prof']" :value="$guest->title" placeholder="—"/>
  <x-field name="guest[first_name]" label="First name" :value="$guest->first_name" required/>
  <x-field name="guest[last_name]" label="Last name" :value="$guest->last_name"/>
  <x-field name="guest[phone]" label="Mobile" :value="$guest->phone" required/>
  <x-field name="guest[email]" label="Email" type="email" :value="$guest->email"/>
  <x-field name="guest[nationality]" label="Nationality" :value="$guest->nationality ?? 'Ghana'"/>
  <x-field name="guest[city]" label="City" :value="$guest->city"/>
  <x-field name="guest[id_type]" label="ID type" type="select" :options="config('pms.id_types')" :value="$guest->id_type ?? (($guest->nationality ?? 'Ghana') === 'Ghana' ? 'ghana_card' : 'passport')"/>
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
  const inp = document.getElementById('guestLookup');
  const box = document.getElementById('guestResults');
  const field = inp?.closest('.field');
  if (!inp || !box || !field) return;

  let timer;
  let requestVersion = 0;
  const close = () => {
    requestVersion++;
    clearTimeout(timer);
    box.classList.remove('show');
    inp.setAttribute('aria-expanded', 'false');
  };
  const message = (text) => {
    const item = document.createElement('div');
    item.className = 'small muted';
    item.setAttribute('role', 'status');
    item.style.padding = '.5rem';
    item.textContent = text;
    box.replaceChildren(item);
  };
  const badge = (text, tone) => {
    const item = document.createElement('span');
    item.className = 'badge badge-' + tone;
    item.textContent = text;
    return item;
  };
  const render = (guests) => {
    box.replaceChildren();
    if (!Array.isArray(guests) || guests.length === 0) {
      message('No match – fill details below to create a new profile.');
      return;
    }

    guests.forEach((guest) => {
      const option = document.createElement('button');
      option.type = 'button';
      option.setAttribute('role', 'option');
      option.setAttribute('aria-selected', 'false');
      const name = document.createElement('b');
      name.textContent = [guest.title, guest.first_name, guest.last_name].filter(Boolean).join(' ');
      const details = document.createElement('span');
      details.className = 'small muted';
      details.textContent = [guest.phone, guest.email].filter(Boolean).join(' · ');
      option.append(name, document.createTextNode(' '), details);
      if (guest.is_blacklisted) option.append(' ', badge('Blacklisted', 'red'));
      if (guest.is_vip) option.append(' ', badge('VIP', 'purple'));
      option.addEventListener('click', () => {
        document.getElementById('guest_id').value = guest.id;
        ['title','first_name','last_name','phone','email','nationality','city','id_type','id_number'].forEach((key) => {
          const input = document.querySelector('[name="guest[' + key + ']"]');
          if (input) input.value = guest[key] || '';
        });
        inp.value = '';
        close();
      });
      box.appendChild(option);
    });
  };

  inp.addEventListener('input', () => {
    clearTimeout(timer);
    const query = inp.value.trim();
    const version = ++requestVersion;
    if (query.length < 2) {
      close();
      return;
    }

    message('Searching guest profiles…');
    box.classList.add('show');
    inp.setAttribute('aria-expanded', 'true');
    timer = setTimeout(() => {
      getJSON('{{ route('reservations.api.guests') }}?q=' + encodeURIComponent(query))
        .then((guests) => {
          if (version !== requestVersion || inp.value.trim() !== query) return;
          if (!Array.isArray(guests)) throw new Error('Invalid guest lookup response');
          render(guests);
        })
        .catch(() => {
          if (version !== requestVersion || inp.value.trim() !== query) return;
          message('Guest search is unavailable. Enter the guest details below and try again later.');
        });
    }, 250);
  });

  inp.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') close();
    if (event.key === 'ArrowDown' && box.classList.contains('show')) {
      const firstOption = box.querySelector('[role="option"]');
      if (firstOption) { event.preventDefault(); firstOption.focus(); }
    }
  });
  box.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') { close(); inp.focus(); return; }
    if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
    const options = [...box.querySelectorAll('[role="option"]')];
    const index = options.indexOf(document.activeElement);
    const next = event.key === 'ArrowDown' ? Math.min(index + 1, options.length - 1) : index - 1;
    event.preventDefault();
    if (next < 0) inp.focus();
    else options[next]?.focus();
  });
  document.addEventListener('click', (event) => { if (!field.contains(event.target)) close(); });
})();
</script>
@endpush
