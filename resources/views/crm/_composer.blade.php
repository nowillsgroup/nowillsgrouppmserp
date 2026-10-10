{{-- Shared message composer for campaigns and automations. Needs: $model (campaign / journey), $promos, $sample --}}
@php
  $sampleVars = $sample ? \App\Services\CrmService::vars($sample, ['reservation' => \App\Models\Reservation::with('rooms.roomType')->where('guest_id', $sample->id)->latest('arrival_date')->first()]) : [];
  $promoVars = \App\Models\PromoCode::whereIn('id', array_keys($promos->all()))->get()->mapWithKeys(fn ($p) => [$p->id => ['promo_code' => $p->code, 'promo_offer' => $p->offerLabel(), 'promo_expiry' => $p->book_to?->format('d M Y') ?? '']]);
@endphp
<div class="card"><div class="card-head"><h3>Message</h3></div><div class="card-body form-grid">
  <div class="field span-all"><label>Channel <span class="req">*</span></label>
    <div class="seg" id="chanPick">@foreach(\App\Services\CrmService::CHANNELS as $k => $l)<label class="check"><input type="radio" name="channel" value="{{ $k }}" @checked(old('channel', $model->channel) === $k)> {{ $l }}</label>@endforeach</div></div>
  <x-field name="subject" label="E-mail subject" :value="$model->subject" class="span-all" placeholder="{first_name}, a little thank-you from {hotel}"/>
  <div class="field span-all"><label for="f_body">Message <span class="req">*</span></label>
    <div class="flex" style="flex-wrap:wrap;gap:.3rem;margin-bottom:.4rem" id="tokenChips">@foreach(\App\Services\CrmService::TOKENS as $k => $l)<button type="button" class="btn btn-sm btn-soft" data-token="{{ $k }}" title="{{ $l }}">{{ '{'.$k.'}' }}</button>@endforeach</div>
    <textarea name="body" id="f_body" rows="10" required class="@error('body') is-invalid @enderror">{{ old('body', $model->body) }}</textarea>
    <span class="hint" id="bodyHint">Click a token to insert it. * booking tokens are filled for pre-arrival / post-stay automations only. Blank lines start a new paragraph; **text** is bold in e-mails.</span>
    @error('body')<span class="err">{{ $message }}</span>@enderror
  </div>
  <x-field name="cta_label" label="Button label" :value="$model->cta_label" placeholder="Book your stay" hint="E-mail: a button with the tracked booking link. SMS: appended as 'label: link'."/>
  <x-field name="promo_code_id" label="Promo code" type="select" :options="$promos" :value="$model->promo_code_id" placeholder="— No promo —" hint="Pre-filled on the booking engine when the guest follows the link."/>
</div></div>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const SAMPLE = @json($sampleVars), PROMOS = @json($promoVars);
  const body = document.getElementById('f_body'), subj = document.getElementById('f_subject'), cta = document.getElementById('f_cta_label'), promo = document.getElementById('f_promo_code_id');
  const pvS = document.getElementById('pvSubject'), pvB = document.getElementById('pvBody'), pvMeta = document.getElementById('pvMeta'), hint = document.getElementById('bodyHint');
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));
  const chan = () => (document.querySelector('#chanPick input:checked') || {}).value || 'email';
  function vars() { return Object.assign({}, SAMPLE, PROMOS[promo.value] || {promo_code: '', promo_offer: '', promo_expiry: ''}); }
  function render(t) { const v = vars(); return String(t || '').replace(/\{([a-z_]+)\}/g, (m, k) => v[k] ?? ''); }
  function draw() {
    const c = chan();
    subj.closest('.field').style.display = c === 'email' ? '' : 'none';
    let text = render(body.value).trim();
    if (c !== 'email') {
      if (cta.value && !body.value.includes('{book_link}')) text += `\n${cta.value}: ${SAMPLE.book_link}`;
      if (!body.value.includes('{unsubscribe_link}')) text += `\n${@json(setting('crm_sms_optout', \App\Services\CrmService::DEFAULTS['crm_sms_optout'])).replace('{unsubscribe_link}', SAMPLE.unsubscribe_link)}`;
    }
    pvS.textContent = c === 'email' ? render(subj.value) || '(no subject)' : '';
    pvS.style.display = c === 'email' ? '' : 'none';
    let html = esc(text).split(/\n\s*\n/).map(p => '<p style="margin:0 0 10px">' + p.replace(/\n/g, '<br>').replace(/\*\*(.+?)\*\*/g, '<b>$1</b>') + '</p>').join('');
    if (c === 'email' && cta.value) html += `<p><span class="btn btn-primary btn-sm">${esc(cta.value)}</span></p><p class="tiny muted">Unsubscribe or change your preferences</p>`;
    pvB.innerHTML = html;
    const len = text.length, parts = len <= 160 ? 1 : Math.ceil(len / 153);
    pvMeta.textContent = c === 'email' ? `E-mail · ${len} characters` : `${c === 'sms' ? 'SMS' : 'WhatsApp'} · ${len} characters${c === 'sms' ? ' · ' + parts + ' SMS part(s)' : ''}`;
    pvMeta.className = 'tiny ' + (c === 'sms' && parts > 3 ? 'text-red' : 'muted');
    body.style.minHeight = c === 'email' ? '240px' : '120px';
  }
  document.getElementById('tokenChips').addEventListener('click', e => {
    const b = e.target.closest('[data-token]'); if (!b) return;
    const target = document.activeElement === subj ? subj : body, tok = '{' + b.dataset.token + '}';
    const s = target.selectionStart ?? target.value.length, en = target.selectionEnd ?? s;
    target.value = target.value.slice(0, s) + tok + target.value.slice(en); target.focus(); target.selectionStart = target.selectionEnd = s + tok.length; draw();
  });
  let last = body; [body, subj].forEach(el => el.addEventListener('focus', () => last = el));
  document.getElementById('tokenChips').addEventListener('mousedown', e => { if (e.target.closest('[data-token]')) { e.preventDefault(); last.focus(); } });
  [body, subj, cta, promo].forEach(el => el.addEventListener('input', draw));
  promo.addEventListener('change', draw);
  document.querySelectorAll('#chanPick input').forEach(r => r.addEventListener('change', draw));
  draw();
});
</script>
