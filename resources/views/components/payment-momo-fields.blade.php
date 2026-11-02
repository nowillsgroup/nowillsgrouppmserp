@props(['modeName' => 'payment_mode_id', 'networkName' => 'momo_network', 'phoneName' => 'momo_phone', 'class' => 'form-grid span-all'])
<div class="{{ $class }}" data-momo-fields data-momo-mode-name="{{ $modeName }}" hidden>
  <x-field :name="$networkName" label="MoMo Network" type="select" :options="['MTN' => 'MTN MoMo', 'Telecel' => 'Telecel Cash', 'AirtelTigo' => 'AT Money']" placeholder="Select network" disabled/>
  <x-field :name="$phoneName" label="MoMo number" type="tel" maxlength="30" inputmode="tel" autocomplete="tel" placeholder="024 123 4567" disabled/>
</div>
@once
@push('scripts')
<script>
(() => {
  document.querySelectorAll('[data-momo-fields]').forEach(fields => {
    const modeName = fields.dataset.momoModeName;
    const form = fields.closest('form');
    const mode = [...(form?.querySelectorAll('select') || [])].find(select => select.name === modeName);
    if (!mode) return;
    const controls = [...fields.querySelectorAll('select, input')];
    const sync = () => {
      const label = (mode.selectedOptions?.[0]?.textContent || '').trim().toLowerCase();
      const isMomo = label.includes('momo') || label.includes('mobile money');
      fields.hidden = !isMomo;
      controls.forEach(control => {
        control.disabled = !isMomo;
        control.required = isMomo;
      });
    };
    mode.addEventListener('change', sync);
    sync();
  });
})();
</script>
@endpush
@endonce
