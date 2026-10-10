<div class="modal" id="resolveModal"><div class="modal-box"><form method="post" id="resolveForm" action="">@csrf
  <div class="modal-head"><h3 id="resolveTitle">Resolve</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><p class="small muted">Record what was done – e.g. "Upgraded to Executive Suite, room 301" or "Guest relocated to partner hotel, OTA informed".</p>
    <x-field name="note" label="Resolution" required placeholder="What was done?"/></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="resolveSubmit">Mark resolved</button></div>
</form></div></div>
<script>
document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('[data-resolve]').forEach(b => b.addEventListener('click', () => {
  document.getElementById('resolveForm').action = b.dataset.resolve;
  document.getElementById('resolveTitle').textContent = b.dataset.title || 'Resolve';
  openModal('resolveModal');
})));
</script>
