{{--
  The Create City Ledger / Company dialog, shared by every desk that takes a booking.

  The walk-in desk and the booking desk both need to open an account without losing
  the form in front of them, and the two must offer exactly the same fields. Only the
  ids change, so each page can keep its own: pass $prefix (e.g. 'walkinCompanyCreate')
  and $action (the route the dialog posts to). The dialog announces the new account as
  a 'city-ledger-created' event on the document; the page that included it decides what
  to do with the account.

  Include it outside the booking form — it carries its own <form>.
--}}
@if(can_do('credit.manage'))
  @php $prefix = $prefix ?? 'cityLedgerCreate'; @endphp
  <div class="modal" id="{{ $prefix }}Modal" role="dialog" aria-modal="true" aria-labelledby="{{ $prefix }}Title">
    <div class="modal-box" style="max-width:42rem">
      <form method="post" action="{{ $action }}" id="{{ $prefix }}Form" data-reset data-no-lock>
        @csrf
        <div class="modal-head"><h2 id="{{ $prefix }}Title">Create City Ledger / Company</h2><button type="button" class="btn btn-ghost btn-icon" data-close aria-label="Close dialog" title="Close dialog"><x-icon name="x"/></button></div>
        <div class="modal-body">
          <div class="alert alert-warn" id="{{ $prefix }}Errors" role="alert" hidden></div>
          <div class="form-grid">
            <x-field name="name" label="Company / account name" maxlength="255" required class="span-all" placeholder="e.g. Acme Ghana Ltd"/>
            <x-field name="type" label="Account type" type="select" :options="['corporate' => 'Corporate','travel_agent' => 'Travel agent / tour operator']" :value="'corporate'" :placeholder="false"/>
            <x-field name="contact_person" label="Primary contact" maxlength="255"/>
            <x-field name="phone" label="Phone" maxlength="30" placeholder="+233 …"/>
            <x-field name="email" label="Email" type="email" maxlength="150"/>
            <x-field name="city" label="City" maxlength="80" :value="'Accra'"/>
            <x-field name="address" label="Address" maxlength="255" class="span-all"/>
            <x-field name="credit_limit" label="Credit limit" type="number" min="0" step="0.01" value="0" required/>
            <x-field name="credit_days" label="Payment terms (days)" type="number" min="0" max="365" value="30" required/>
            <p class="hint span-all">This creates an active direct-billing account. A credit limit of 0 means no limit. The saved account will appear in Finance → City Ledger and be selected on this booking.</p>
          </div>
        </div>
        <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button type="submit" class="btn btn-primary" id="{{ $prefix }}Save">Save City Ledger / Company</button></div>
      </form>
    </div>
  </div>

  <script>
  (() => {
    const form = document.getElementById('{{ $prefix }}Form');
    const modal = document.getElementById('{{ $prefix }}Modal');
    const errors = document.getElementById('{{ $prefix }}Errors');
    const save = document.getElementById('{{ $prefix }}Save');
    if (!form || !modal || !errors || !save) return;

    const showErrors = messages => {
      const list = document.createElement('ul');
      list.style.margin = '.25rem 0 0';
      list.style.paddingLeft = '1.1rem';
      messages.forEach(message => {
        const item = document.createElement('li');
        item.textContent = message;
        list.appendChild(item);
      });
      errors.replaceChildren(document.createTextNode('Please review the company details.'), list);
      errors.hidden = false;
    };

    form.addEventListener('submit', async event => {
      event.preventDefault();
      errors.hidden = true;
      errors.replaceChildren();
      save.disabled = true;
      save.textContent = 'Saving…';
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          headers: {'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'},
          body: new FormData(form),
          credentials: 'same-origin',
        });
        const payload = await response.json().catch(() => null);
        if (response.status === 422 && payload?.errors) {
          showErrors(Object.values(payload.errors).flat());
          return;
        }
        if (!response.ok || !payload?.company) throw new Error('The City Ledger / Company could not be saved. Please try again.');
        document.dispatchEvent(new CustomEvent('city-ledger-created', {detail: payload.company}));
        form.reset();
        modal.classList.remove('show');
      } catch (error) {
        showErrors([error instanceof Error ? error.message : 'The City Ledger / Company could not be saved. Please try again.']);
      } finally {
        save.disabled = false;
        save.textContent = 'Save City Ledger / Company';
      }
    });
  })();
  </script>
@endif
