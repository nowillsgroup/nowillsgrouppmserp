@props(['class' => 'span-all'])
<div {{ $attributes->merge(['class' => 'form-grid '.$class]) }} data-cheque-fields hidden>
  <div class="field span-all"><strong>Cheque details</strong><span class="hint">Complete these details when the payment mode is Cheque.</span></div>
  <x-field name="cheque_bank_name" label="Bank name" maxlength="100" autocomplete="organization"/>
  <x-field name="cheque_number" label="Cheque number" maxlength="20"/>
  <x-field name="cheque_bank_branch" label="Bank branch" maxlength="100"/>
</div>
@once
@push('scripts')
<script>
  document.querySelectorAll('[data-payment-mode]').forEach(function (mode) {
    const form = mode.closest('form');
    const details = form?.querySelector('[data-cheque-fields]');
    if (!details) return;
    const sync = function () {
      const label = mode.selectedOptions?.[0]?.textContent?.trim().toLowerCase() || '';
      const cheque = label.includes('cheque');
      details.hidden = !cheque;
      details.querySelectorAll('input').forEach(function (input) { input.required = cheque; });
    };
    mode.addEventListener('change', sync);
    sync();
  });
</script>
@endpush
@endonce
