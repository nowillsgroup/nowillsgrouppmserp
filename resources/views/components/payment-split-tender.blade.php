@props(['widgetId', 'singleId', 'modes', 'label' => 'payment', 'oldLines' => null, 'showCheque' => true])
<div id="{{ $widgetId }}" data-split-tender-widget data-single-id="{{ $singleId }}"
     data-old-lines="{{ json_encode($oldLines ?? old('payments', []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}" data-label="{{ $label }}" class="mt-1">
  <button type="button" class="btn btn-sm" data-split-toggle>Split {{ $label }} across methods</button>
  <div class="mt-1" data-split-panel hidden>
    <p class="tiny muted">Add two or more tenders. Total allocated: <b data-split-total>0.00</b>.</p>
    <div data-split-rows></div>
    <button type="button" class="btn btn-sm mt-1" data-split-add>Add payment method</button>
  </div>
  <template data-split-row-template>
    <div class="form-grid mt-1" data-split-row style="border:1px solid var(--c-border);padding:.6rem;border-radius:var(--radius)">
      <div class="field"><label>Payment method</label><select name="payments[__INDEX__][payment_mode_id]" required>
        @foreach($modes as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
      </select></div>
      <div class="field"><label>Amount</label><input name="payments[__INDEX__][amount]" type="number" min="0.01" step="0.01" required></div>
      <div class="field"><label>MoMo network</label><input name="payments[__INDEX__][momo_network]" maxlength="20"></div>
      <div class="field"><label>MoMo phone</label><input name="payments[__INDEX__][momo_phone]" maxlength="30"></div>
      <div class="field span-all"><label>Reference / transaction ID</label><input name="payments[__INDEX__][reference]" maxlength="80"></div>
      @if($showCheque)<details class="span-all"><summary class="tiny bold">Cheque details</summary><div class="form-grid mt-1">
        <div class="field"><label>Bank name</label><input name="payments[__INDEX__][cheque_bank_name]" maxlength="100"></div>
        <div class="field"><label>Cheque number</label><input name="payments[__INDEX__][cheque_number]" maxlength="20"></div>
        <div class="field"><label>Bank branch</label><input name="payments[__INDEX__][cheque_bank_branch]" maxlength="100"></div>
      </div></details>@endif
      <button type="button" class="btn btn-sm btn-ghost" data-split-remove>Remove tender</button>
    </div>
  </template>
</div>
@once
@push('scripts')
<script>
(() => {
  document.querySelectorAll('[data-split-tender-widget]').forEach(widget => {
    const form = widget.closest('form');
    const single = document.getElementById(widget.dataset.singleId);
    const toggle = widget.querySelector('[data-split-toggle]');
    const panel = widget.querySelector('[data-split-panel]');
    const rows = widget.querySelector('[data-split-rows]');
    const template = widget.querySelector('[data-split-row-template]');
    const total = widget.querySelector('[data-split-total]');
    if (!form || !single || !toggle || !panel || !rows || !template) return;
    let oldLines = [];
    try { oldLines = JSON.parse(widget.dataset.oldLines || '[]'); } catch (_) {}
    let index = 0, restored = false;
    const updateTotal = () => {
      const sum = [...rows.querySelectorAll('input[name$="[amount]"]')].reduce((n, input) => n + (Number(input.value) || 0), 0);
      if (total) total.textContent = sum.toFixed(2);
    };
    const syncCheque = () => rows.querySelectorAll('[data-split-row]').forEach(row => {
      const mode = row.querySelector('select[name$="[payment_mode_id]"]');
      const cheque = (mode?.selectedOptions?.[0]?.textContent || '').toLowerCase().includes('cheque');
      row.querySelectorAll('input[name$="[cheque_bank_name]"],input[name$="[cheque_number]"],input[name$="[cheque_bank_branch]"]').forEach(input => { input.required = cheque; });
    });
    const addRow = (line = {}) => {
      if (!line || typeof line !== 'object') line = {};
      const i = index++;
      const html = template.innerHTML.replaceAll('__INDEX__', String(i));
      rows.insertAdjacentHTML('beforeend', html);
      const row = rows.lastElementChild;
      for (const key of ['payment_mode_id','amount','reference','momo_network','momo_phone','cheque_bank_name','cheque_number','cheque_bank_branch']) {
        const input = row.querySelector(`[name="payments[${i}][${key}]"]`);
        if (input && line[key] !== undefined && line[key] !== null) input.value = String(line[key]);
      }
      syncCheque(); updateTotal();
    };
    const setSplit = enabled => {
      panel.hidden = !enabled;
      single.querySelectorAll('input,select,textarea').forEach(input => { input.disabled = enabled; });
      toggle.textContent = enabled ? 'Use one payment method' : `Split ${widget.dataset.label || 'payment'} across methods`;
      if (enabled && !rows.children.length) {
        if (!restored && Array.isArray(oldLines) && oldLines.length) { oldLines.slice(0,10).forEach(addRow); restored = true; }
        while (rows.children.length < 2) addRow();
      }
      if (!enabled) rows.replaceChildren();
      updateTotal();
    };
    toggle.addEventListener('click', () => setSplit(panel.hidden));
    widget.querySelector('[data-split-add]')?.addEventListener('click', () => addRow());
    rows.addEventListener('input', updateTotal);
    rows.addEventListener('change', event => { updateTotal(); if (event.target.matches('select[name$="[payment_mode_id]"]')) syncCheque(); });
    rows.addEventListener('click', event => {
      if (event.target.closest('[data-split-remove]')) { event.target.closest('[data-split-row]')?.remove(); updateTotal(); }
    });
    form.addEventListener('submit', event => {
      if (!panel.hidden && rows.querySelectorAll('[data-split-row]').length < 2) {
        event.preventDefault(); alert('Add at least two payment methods, or switch back to one method.');
      }
    });
    if (Array.isArray(oldLines) && oldLines.length) setSplit(true);
  });
})();
</script>
@endpush
@endonce
