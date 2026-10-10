{{-- v2.7: company picker for city-ledger billing with live credit standing and (for credit.override) an override reason --}}
@php
  $amount = (float) ($amount ?? 0);
  $pos = \App\Services\CreditService::positions(array_map('intval', array_keys($companies instanceof \Illuminate\Support\Collection ? $companies->all() : $companies)));
  $info = $pos->map(fn ($p) => [
      'hold' => $p['company']->onHold() ? ($p['company']->credit_hold_reason ?: 'on hold') : null,
      'direct' => (bool) $p['company']->direct_billing,
      'over' => $p['limit'] > 0 && max(0, $p['outstanding']) + $amount > $p['limit'] + 0.004,
      'text' => 'City ledger '.money(max(0, $p['outstanding'])).($p['limit'] > 0 ? ' of '.money($p['limit']).' limit' : ' · no limit set')
          .($p['overdue'] > 0 ? ' · '.money($p['overdue']).' overdue' : '').' · '.$p['company']->credit_days.'-day terms',
  ]);
@endphp
<x-field name="{{ $name ?? 'company_id' }}" label="Company" type="select" :options="$companies" :value="$value ?? null" :required="$required ?? false"/>
<div id="creditInfo" class="small mt-1" data-info='@json($info)'></div>
@if(can_do('credit.override'))
<div id="overrideBox" class="hidden mt-1"><x-field name="override_reason" label="Credit override reason" placeholder="e.g. GM approved – PO received from the client" hint="Needed to bill a company on hold or over its limit. Logged in credit control."/></div>
@endif
@push('scripts')<script>
document.addEventListener('DOMContentLoaded', () => {
  const sel = document.getElementById('f_{{ $name ?? 'company_id' }}'), box = document.getElementById('creditInfo'), ov = document.getElementById('overrideBox');
  if (!sel || !box) return;
  const info = JSON.parse(box.dataset.info || '{}');
  const esc = t => String(t).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const show = () => { const i = info[sel.value]; let html = '', need = false;
    if (i) {
      if (!i.direct) html = `<span class="badge badge-red">Not approved for direct billing</span> Settle with the guest.`;
      else { html = `<span class="muted">${esc(i.text)}</span>`;
        if (i.hold) { html = `<span class="badge badge-red">Credit hold</span> ${esc(i.hold)}<br>` + html; need = true; }
        if (i.over) { html += `<br><span class="badge badge-amber">Over limit</span> This bill takes the balance over the credit limit.`; need = true; } }
    }
    box.innerHTML = html; if (ov) ov.classList.toggle('hidden', !need); };
  sel.addEventListener('change', show); show();
});
</script>@endpush
