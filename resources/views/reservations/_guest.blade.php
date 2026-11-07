{{-- Guest capture block with live lookup of existing profiles --}}
<input type="hidden" name="guest_id" id="guest_id" value="{{ old('guest_id', $guest->id) }}">
<div class="form-grid">
  @if(can_do('reservations.view'))
  <div class="field span-all" style="position:relative">
    <label for="guestLookup">Find existing guest (name / phone / email / ID)</label>
    <input type="text" id="guestLookup" maxlength="100" placeholder="Start typing to search guest history…" autocomplete="off" role="combobox" aria-autocomplete="list" aria-controls="guestResults" aria-expanded="false">
    <div class="dropdown" id="guestResults" role="listbox" aria-label="Matching guest profiles" style="left:0;right:auto;width:100%;max-height:260px;overflow:auto"></div>
  </div>
  @endif
  @php
    $allowCustomGuestTitle = $allowCustomGuestTitle ?? false;
    $guestTitleOptions = ['Mr' => 'Mr', 'Mrs' => 'Mrs', 'Ms' => 'Ms', 'Dr' => 'Dr', 'Prof' => 'Prof'];
    $currentGuestTitle = old('guest.title', $guest->title);
    if ($currentGuestTitle && ! array_key_exists($currentGuestTitle, $guestTitleOptions) && $currentGuestTitle !== '__other__') {
      $guestTitleOptions[$currentGuestTitle] = $currentGuestTitle;
    }
    if ($allowCustomGuestTitle) $guestTitleOptions['__other__'] = 'Others';
  @endphp
  <x-field name="guest[title]" label="Title" type="select" :options="$guestTitleOptions" :value="$guest->title" placeholder="—"/>
  @if($allowCustomGuestTitle)
    <div class="field" id="guestCustomTitleWrap" hidden>
      <label for="guestCustomTitle">New title</label>
      <div style="display:flex;gap:.45rem;align-items:start">
        <input type="text" id="guestCustomTitle" name="guest[custom_title]" value="{{ old('guest.custom_title') }}" maxlength="30" autocomplete="off" aria-describedby="guestCustomTitleHint">
        <button type="button" class="btn btn-sm btn-soft" id="guestCustomTitleSave">Save title</button>
      </div>
      <span class="hint" id="guestCustomTitleHint">Up to 30 characters. The title is saved on the guest profile when you save this form.</span>
      @error('guest.custom_title')<span class="err">{{ $message }}</span>@enderror
    </div>
  @endif
  <x-field name="guest[first_name]" label="First name" :value="$guest->first_name" required/>
  <x-field name="guest[last_name]" label="Last name" :value="$guest->last_name"/>
  <x-field name="guest[phone]" label="Mobile" :value="$guest->phone" required/>
  <x-field name="guest[email]" label="Email" type="email" :value="$guest->email"/>
  <x-field name="guest[nationality]" label="Nationality" :value="$guest->nationality ?? 'Ghana'"/>
  <x-field name="guest[city]" label="City" :value="$guest->city"/>
  <x-field name="guest[id_type]" label="ID type" type="select" :options="config('pms.id_types')" :value="$guest->id_type ?? (($guest->nationality ?? 'Ghana') === 'Ghana' ? 'ghana_card' : 'passport')" :required="isset($fullGuest)"/>
  <x-field name="guest[id_number]" label="ID number" :value="$guest->id_number" :required="isset($fullGuest)"/>
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
  @if($allowCustomGuestTitle)
  const titleSelect = document.querySelector('[name="guest[title]"]');
  const customTitleWrap = document.getElementById('guestCustomTitleWrap');
  const customTitleInput = document.getElementById('guestCustomTitle');
  const customTitleSave = document.getElementById('guestCustomTitleSave');
  if (titleSelect && customTitleWrap && customTitleInput && customTitleSave) {
    const syncCustomTitle = () => {
      const isOther = titleSelect.value === '__other__';
      customTitleWrap.hidden = !isOther;
      customTitleInput.disabled = !isOther;
      customTitleInput.required = isOther;
      customTitleSave.hidden = !isOther;
      customTitleSave.disabled = !customTitleInput.value.trim();
    };
    titleSelect.addEventListener('change', () => {
      syncCustomTitle();
      if (titleSelect.value === '__other__') customTitleInput.focus();
    });
    titleSelect.addEventListener('guest-title-loaded', syncCustomTitle);
    customTitleInput.addEventListener('input', () => {
      if (customTitleInput.value.trim().toLowerCase() !== '__other__') customTitleInput.setCustomValidity('');
      customTitleSave.disabled = !customTitleInput.value.trim();
    });
    customTitleSave.addEventListener('click', () => {
      const title = customTitleInput.value.trim();
      if (!title || title.length > 30) {
        customTitleInput.reportValidity();
        return;
      }
      if (title.toLowerCase() === '__other__') {
        customTitleInput.setCustomValidity('Please enter a title other than the reserved Others option.');
        customTitleInput.reportValidity();
        return;
      }
      customTitleInput.setCustomValidity('');
      let option = [...titleSelect.options].find(item => item.value === title);
      if (!option) {
        option = document.createElement('option');
        option.value = title;
        option.textContent = title;
        option.dataset.customGuestTitle = 'true';
        const otherOption = [...titleSelect.options].find(item => item.value === '__other__');
        titleSelect.add(option, otherOption || null);
      }
      titleSelect.value = title;
      titleSelect.dispatchEvent(new Event('change', {bubbles:true}));
    });
    syncCustomTitle();
  }
  @endif

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
      const idHint = guest.id_number ? `${guest.id_type || 'ID'} ends ${String(guest.id_number).slice(-4)}` : '';
      details.textContent = [guest.phone, guest.email, idHint].filter(Boolean).join(' · ') || 'No contact details on file';
      option.append(name, document.createTextNode(' '), details);
      if (guest.is_blacklisted) option.append(' ', badge('Blacklisted', 'red'));
      if (guest.is_vip) option.append(' ', badge('VIP', 'purple'));
      option.addEventListener('click', () => {
        document.getElementById('guest_id').value = guest.id;
        ['title','first_name','last_name','phone','email','nationality','city','id_type','id_number'].forEach((key) => {
          const input = document.querySelector('[name="guest[' + key + ']"]');
          if (!input) return;
          const value = guest[key] || '';
          if (key === 'title' && input instanceof HTMLSelectElement && value && ![...input.options].some(option => option.value === String(value))) {
            const customOption = document.createElement('option');
            customOption.value = String(value);
            customOption.textContent = String(value);
            customOption.dataset.customGuestTitle = 'true';
            const otherOption = [...input.options].find(option => option.value === '__other__');
            input.add(customOption, otherOption || null);
          }
          input.value = value;
          if (key === 'title') input.dispatchEvent(new Event('guest-title-loaded'));
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
