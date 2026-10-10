@extends('layouts.app')
@section('title', 'KOT Print Station')
@section('crumbs') POS / Print station @endsection
@section('actions')
  <a class="btn btn-sm" href="{{ route('pos.printers') }}">Printers</a>
  <a class="btn btn-sm" href="{{ route('kitchen.index') }}">Kitchen display</a>
@endsection
@section('content')
<div class="card" style="max-width:720px">
  <div class="card-head"><h3>This screen prints for one kitchen printer</h3></div>
  <div class="card-body">
    <form method="get" class="flex gap-1 items-end">
      <div class="field" style="flex:1"><label>Printer on this PC</label>
        <select name="printer" class="input" onchange="this.form.submit()">
          @foreach($printers as $p)<option value="{{ $p->id }}" @selected($printer?->id === $p->id)>{{ $p->label() }}{{ $p->connection === 'network' ? ' (network)' : '' }}</option>@endforeach
        </select>
      </div>
    </form>
    @if(!$printer)
      <p class="muted">Add a printer under POS → KOT Printers, then leave this page open on the PC next to it.</p>
    @else
      <p class="small muted">Leave this page open. New tickets for <b>{{ $printer->name }}</b> print on this computer’s printer. In the print dialog, choose that printer once — the browser remembers it. @if($printer->connection === 'network')This printer is also sent over the network ({{ $printer->host }}:{{ $printer->port }}) when the KOT is fired; this screen is the backup if it is offline.@endif</p>
      <div id="stWait" class="alert alert-info" style="margin-top:1rem"><x-icon name="print"/><div>Waiting for KOTs… <b id="stAt">—</b></div></div>
      <div id="stNow"></div>
      <h4 class="mt-2">Recent</h4>
      <ul class="list" id="stRecent"><li class="muted small">None yet.</li></ul>
    @endif
  </div>
</div>
@if($printer)
<iframe id="stFrame" style="position:absolute;width:0;height:0;border:0" title="KOT slip"></iframe>
@endif
@endsection
@push('scripts')
@if($printer)
<script>
(() => {
  const jobsUrl = @json(route('kitchen.station.jobs', ['printer' => $printer->id]));
  const claimUrl = @json(route('kitchen.station.claim'));
  const ackUrl = @json(route('kitchen.station.ack'));
  const token = document.querySelector('meta[name=csrf-token]').content;
  const seen = new Set();
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  async function post(url, body) {
    const r = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token}, body: JSON.stringify(body)});
    return r.ok ? r.json() : null;
  }
  function beep() { try { const a = new (window.AudioContext || window.webkitAudioContext)(), o = a.createOscillator(), g = a.createGain(); o.frequency.value = 880; o.connect(g); g.connect(a.destination); g.gain.setValueAtTime(.15, a.currentTime); o.start(); o.stop(a.currentTime + .18); } catch (e) {} }
  async function poll() {
    try {
      const r = await fetch(jobsUrl, {headers: {Accept: 'application/json'}});
      if (!r.ok) return;
      const d = await r.json();
      document.getElementById('stAt').textContent = d.at;
      document.getElementById('stRecent').innerHTML = (d.recent || []).map(j => `<li><span>KOT #${j.kot} · ${esc(j.where)} · ${j.items} item(s)</span><span>${esc(j.status)}${j.error ? ' — ' + esc(j.error) : ''}</span></li>`).join('') || '<li class="muted small">None yet.</li>';
      const next = (d.waiting || []).find(j => !seen.has(j.id));
      if (!next) return;
      seen.add(next.id);
      const claim = await post(claimUrl, {job: next.id});
      if (!claim?.ok) return;
      beep();
      document.getElementById('stNow').innerHTML = `<div class="alert alert-success"><div>Printing KOT #${next.kot} · ${esc(next.where)} (${next.items} item(s))</div></div>`;
      const frame = document.getElementById('stFrame');
      frame.src = claim.url;
      setTimeout(() => post(ackUrl, {job: next.id}), 1500);
    } catch (e) {}
  }
  poll(); setInterval(poll, 3000);
})();
</script>
@endif
@endpush
