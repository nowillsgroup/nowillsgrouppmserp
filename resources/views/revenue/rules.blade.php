@extends('layouts.app')
@section('title', 'Pricing Rules')
@section('crumbs') <a href="{{ route('revenue.index') }}">Revenue</a> / Pricing Rules @endsection
@section('actions')
<a class="btn btn-icon rvr-action" href="{{ route('revenue.pricing') }}" aria-label="Open Revenue Pricing Grid" title="Pricing Grid"><x-icon name="chart"/></a>
@if(can_do('revenue.manage'))<a class="btn btn-primary btn-icon rvr-action" href="{{ route('revenue.rules.create') }}" aria-label="Create pricing rule" title="New pricing rule"><x-icon name="plus"/></a>@endif
@endsection
@push('head')
<style>
.rvr-page{min-width:0}.rvr-action{min-width:36px;min-height:36px;justify-content:center}.rvr-hero{display:flex;align-items:center;justify-content:space-between;gap:1rem;padding:1.15rem 1.3rem;margin-bottom:1rem;border-radius:17px;background:linear-gradient(118deg,#103a23,#176239 68%,#247849);color:#f5fff7;box-shadow:0 12px 28px rgba(16,58,35,.14)}.rvr-hero-copy{min-width:0}.rvr-kicker{display:flex;align-items:center;gap:.4rem;color:#c7efd0;font-size:.67rem;font-weight:800;letter-spacing:.13em;text-transform:uppercase}.rvr-kicker svg{width:15px;height:15px}.rvr-hero-copy p{margin:.35rem 0 0;color:#e0f2e5;font-size:.86rem}.rvr-mode-pill{display:inline-flex;align-items:center;gap:.4rem;padding:.4rem .65rem;border:1px solid #ffffff30;border-radius:999px;background:#ffffff14;color:#f4fff6;font-size:.73rem;font-weight:750;white-space:nowrap}.rvr-mode-pill svg{width:15px;height:15px}
.rvr-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.7rem;margin-bottom:1rem}.rvr-stat{min-width:0;padding:.8rem .9rem;border:1px solid var(--c-border);border-radius:13px;background:var(--c-surface);box-shadow:var(--shadow-sm)}.rvr-stat-label{color:var(--c-text-2);font-size:.65rem;font-weight:750;letter-spacing:.055em;text-transform:uppercase}.rvr-stat-value{display:block;margin-top:.15rem;font-size:1.08rem;font-weight:800}.rvr-stat-note{display:block;margin-top:.12rem;color:var(--c-text-2);font-size:.7rem}
.rvr-top-grid{align-items:start}.rvr-card{min-width:0;overflow:hidden}.rvr-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:.8rem}.rvr-card-head h3{margin:0}.rvr-card-head p{margin:.22rem 0 0}.rvr-status{display:inline-flex;align-items:center;padding:.3rem .55rem;border-radius:999px;font-size:.68rem;font-weight:800;white-space:nowrap}.rvr-status.off{background:#f1f5f9;color:#475569}.rvr-status.recommend{background:#eaf6ed;color:#176239}.rvr-status.auto{background:#f1eafe;color:#5b21b6}.rvr-readonly{display:flex;gap:.5rem;align-items:flex-start;margin-bottom:.8rem;padding:.65rem .75rem;border:1px solid var(--c-border);border-radius:10px;background:var(--c-bg);color:var(--c-text-2);font-size:.78rem}.rvr-readonly svg{width:16px;height:16px;flex:none}
.rvr-modes{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.55rem;margin:0 0 .95rem;padding:0;border:0}.rvr-modes legend{grid-column:1/-1;margin-bottom:.45rem;font-size:.75rem;font-weight:750}.rvr-mode-choice{position:relative;display:block;min-width:0;padding:.7rem .75rem;border:1px solid var(--c-border);border-radius:11px;background:var(--c-surface);cursor:pointer}.rvr-mode-choice:has(input:checked){border-color:#4a9b62;background:#eef8f0;box-shadow:inset 0 0 0 1px #4a9b62}.rvr-mode-choice input{margin:0 .35rem .35rem 0;vertical-align:middle}.rvr-mode-choice strong{font-size:.77rem}.rvr-mode-choice p{margin:.3rem 0 0;color:var(--c-text-2);font-size:.7rem;line-height:1.4}.rvr-mode-choice:has(input:disabled){cursor:default}.rvr-setting-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem}.rvr-setting{display:grid;gap:.25rem}.rvr-setting label{font-size:.75rem;font-weight:700}.rvr-setting input{max-width:190px}.rvr-setting .hint,.rvr-setting .err{font-size:.7rem}.rvr-save-row{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-top:.8rem}.rvr-save-row .tiny{color:var(--c-text-2)}
.rvr-limits-wrap,.rvr-rules-wrap{max-width:100%;overflow:auto}.rvr-limits-table{min-width:540px}.rvr-limits-table th,.rvr-rules-table th{font-size:.68rem;white-space:nowrap}.rvr-limits-table td{vertical-align:middle}.rvr-limit-input{width:110px;max-width:100%;min-height:35px;text-align:right;font-variant-numeric:tabular-nums}.rvr-limit-input:disabled{opacity:.75}.rvr-limit-error{margin-top:.2rem;color:var(--c-red);font-size:.68rem;white-space:normal}.rvr-table-foot{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;padding:.8rem 1rem;border-top:1px solid var(--c-border)}.rvr-table-foot .tiny{color:var(--c-text-2)}
.rvr-rules-card{margin-top:1.1rem;overflow:hidden}.rvr-rules-head{display:flex;align-items:center;justify-content:space-between;gap:.8rem}.rvr-rules-head h3{margin:0}.rvr-rule-counts{display:flex;gap:.4rem;flex-wrap:wrap}.rvr-count{display:inline-flex;align-items:center;gap:.25rem;padding:.28rem .5rem;border-radius:999px;background:#e9f5ec;color:#176239;font-size:.67rem;font-weight:800;white-space:nowrap}.rvr-count.paused{background:#f1f5f9;color:#475569}.rvr-rules-table{min-width:940px}.rvr-rules-table th,.rvr-rules-table td{vertical-align:middle}.rvr-priority{display:inline-grid;place-items:center;width:27px;height:27px;border-radius:8px;background:var(--c-bg);font-size:.73rem;font-weight:800;font-variant-numeric:tabular-nums}.rvr-rule-name{font-size:.81rem}.rvr-condition{min-width:200px;max-width:350px;white-space:normal}.rvr-adjust{white-space:nowrap;font-variant-numeric:tabular-nums}.rvr-row-actions{display:flex;justify-content:flex-end;align-items:center;gap:.25rem;white-space:nowrap}.rvr-row-actions form{margin:0}.rvr-icon-action{min-width:32px;min-height:32px}.rvr-empty{padding:1rem}
body.dark .rvr-hero{background:linear-gradient(118deg,#0d2718,#143d25 68%,#1d5433);box-shadow:none}body.dark .rvr-status.recommend,body.dark .rvr-count{background:#183a27;color:#a9e1b7}body.dark .rvr-status.off,body.dark .rvr-count.paused{background:#303842;color:#d5dee8}body.dark .rvr-status.auto{background:#332850;color:#d8c7ff}body.dark .rvr-mode-choice:has(input:checked){background:#183225;border-color:#45915b;box-shadow:inset 0 0 0 1px #45915b}body.dark .rvr-readonly{background:var(--c-bg)}body.dark .rvr-priority{background:#303842}
@media(max-width:1000px){.rvr-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.rvr-modes{grid-template-columns:1fr}.rvr-mode-choice{padding:.6rem .7rem}.rvr-mode-choice p{margin-left:1.5rem}}
@media(max-width:760px){.rvr-hero{align-items:flex-start;flex-direction:column;padding:1rem}.rvr-setting-grid{grid-template-columns:1fr}.rvr-card-head{align-items:flex-start;flex-direction:column}.rvr-rules-head{align-items:flex-start}}
@media(max-width:520px){.rvr-stats{gap:.5rem}.rvr-stat{padding:.65rem}.rvr-stat-value{font-size:1rem}.rvr-rules-table{min-width:880px}.rvr-save-row .btn{width:100%}}
@media(prefers-reduced-motion:reduce){.rvr-mode-choice{scroll-behavior:auto;transition:none}}
</style>
@endpush
@section('content')
@php
  $canManage = can_do('revenue.manage');
  $activeCount = $rules->where('is_active', true)->count();
  $pausedCount = $rules->count() - $activeCount;
  $guardedTypes = $types->filter(fn ($type) => $type->min_rate !== null || $type->max_rate !== null)->count();
@endphp
<div class="rvr-page">
  <section class="rvr-hero" aria-label="Revenue rules overview"><div class="rvr-hero-copy"><span class="rvr-kicker"><x-icon name="trend"/> Revenue management · pricing controls</span><p>Choose how recommendations are applied, set safe rate boundaries, and manage the yield rules that drive pricing.</p></div><span class="rvr-mode-pill"><x-icon name="trend"/> {{ \App\Services\RevenueService::MODES[$mode] }}</span></section>

  <section class="rvr-stats" aria-label="Pricing rules summary">
    <article class="rvr-stat"><span class="rvr-stat-label">Pricing mode</span><strong class="rvr-stat-value">{{ \App\Services\RevenueService::MODES[$mode] }}</strong><span class="rvr-stat-note">{{ $canManage ? 'Mode can be changed below' : 'View only' }}</span></article>
    <article class="rvr-stat"><span class="rvr-stat-label">Active rules</span><strong class="rvr-stat-value">{{ number_format($activeCount) }}</strong><span class="rvr-stat-note">Applied in priority order</span></article>
    <article class="rvr-stat"><span class="rvr-stat-label">Paused rules</span><strong class="rvr-stat-value">{{ number_format($pausedCount) }}</strong><span class="rvr-stat-note">Saved, but not currently applied</span></article>
    <article class="rvr-stat"><span class="rvr-stat-label">Room types with guard-rails</span><strong class="rvr-stat-value">{{ number_format($guardedTypes) }} / {{ number_format($types->count()) }}</strong><span class="rvr-stat-note">A floor, ceiling, or both are configured</span></article>
  </section>

  <div class="grid g-2 rvr-top-grid">
    <section class="card rvr-card" aria-labelledby="rvrModeTitle"><div class="card-head rvr-card-head"><div><h3 id="rvrModeTitle">Pricing mode &amp; forecast</h3><p class="tiny muted">Set how rate recommendations affect future nights</p></div><span class="rvr-status {{ $mode }}">{{ \App\Services\RevenueService::MODES[$mode] ?? ucfirst($mode) }}</span></div><div class="card-body">
      @unless($canManage)<div class="rvr-readonly" role="note"><x-icon name="lock"/><span>You can review the current mode and guard-rails, but only a revenue manager can change these settings.</span></div>@endunless
      <form method="post" action="{{ route('revenue.settings') }}">@csrf
        <fieldset class="rvr-modes"><legend>Choose a pricing mode</legend>
          @foreach(['off' => ['Forecast only', 'No rates change automatically. Managers may still publish manually from the pricing grid.'], 'recommend' => ['Review and publish', 'Recommendations wait for a manager to select and publish them.'], 'auto' => ['Auto-pilot · live', 'Matching rules price future nights in booking quotes and channel feeds. Fixed dates are protected.']] as $k => [$label, $description])
            <label class="rvr-mode-choice"><input type="radio" name="rm_mode" value="{{ $k }}" @checked($mode === $k) @disabled(! $canManage) aria-describedby="rvrMode-{{ $k }}"><strong>{{ $label }}</strong><p id="rvrMode-{{ $k }}">{{ $description }}</p></label>
          @endforeach
        </fieldset>
        @error('rm_mode')<span class="err">{{ $message }}</span>@enderror
        <div class="rvr-setting-grid">
          <div class="rvr-setting"><label for="rvrRoundTo">Round rates to the nearest</label><input id="rvrRoundTo" type="number" name="rm_round_to" min="0" max="10000" step="1" value="{{ old('rm_round_to', $roundTo) }}" @disabled(! $canManage) aria-describedby="rvrRoundHint"> <span class="hint" id="rvrRoundHint">0 = no rounding</span>@error('rm_round_to')<span class="err">{{ $message }}</span>@enderror</div>
          <div class="rvr-setting"><label for="rvrHorizon">Forecast horizon (days)</label><input id="rvrHorizon" type="number" name="rm_horizon_days" min="14" max="365" value="{{ old('rm_horizon_days', $horizon) }}" @disabled(! $canManage) aria-describedby="rvrHorizonHint"> <span class="hint" id="rvrHorizonHint">Nights tracked by the daily snapshot</span>@error('rm_horizon_days')<span class="err">{{ $message }}</span>@enderror</div>
        </div>
        @if($canManage)<div class="rvr-save-row"><button type="submit" class="btn btn-primary"><x-icon name="check" size="sm"/> Save pricing settings</button><span class="tiny">Auto-pilot takes effect immediately when saved.</span></div>@endif
      </form>
    </div></section>

    <section class="card rvr-card" aria-labelledby="rvrLimitsTitle"><div class="card-head rvr-card-head"><div><h3 id="rvrLimitsTitle">Rate floors &amp; ceilings</h3><p class="tiny muted">Recommendations are clamped within these optional limits.</p></div><span class="rvr-status recommend">{{ $guardedTypes }} protected</span></div><div class="card-body">
      @unless($canManage)<div class="rvr-readonly" role="note"><x-icon name="lock"/><span>Rate limits are view only for your account.</span></div>@endunless
      @if($types->isEmpty())
        <x-empty title="No active room types" text="Activate room types before setting rate floors or ceilings." icon="bed"/>
      @else
      <form method="post" action="{{ route('revenue.limits') }}">@csrf
        <div class="rvr-limits-wrap"><table class="table table-compact rvr-limits-table" aria-label="Rate limits by room type"><thead><tr><th scope="col">Room type</th><th scope="col" class="num">Base</th><th scope="col" class="num">Weekend</th><th scope="col" class="num">Floor · minimum</th><th scope="col" class="num">Ceiling · maximum</th></tr></thead><tbody>
        @foreach($types as $t)
          <tr><th scope="row" class="bold">{{ $t->name }}</th><td class="num">{{ money($t->base_rate) }}</td><td class="num">{{ $t->weekend_rate !== null ? money($t->weekend_rate) : '—' }}</td>
            <td class="num"><input class="rvr-limit-input" type="number" step="1" min="0" max="99999999" name="min_rate[{{ $t->id }}]" value="{{ old('min_rate.'.$t->id, $t->min_rate !== null ? round($t->min_rate) : '') }}" aria-label="Minimum rate floor for {{ $t->name }}" @disabled(! $canManage)>@error('min_rate.'.$t->id)<div class="rvr-limit-error">{{ $message }}</div>@enderror</td>
            <td class="num"><input class="rvr-limit-input" type="number" step="1" min="0" max="99999999" name="max_rate[{{ $t->id }}]" value="{{ old('max_rate.'.$t->id, $t->max_rate !== null ? round($t->max_rate) : '') }}" aria-label="Maximum rate ceiling for {{ $t->name }}" @disabled(! $canManage)>@error('max_rate.'.$t->id)<div class="rvr-limit-error">{{ $message }}</div>@enderror</td></tr>
        @endforeach
        </tbody></table></div>
        @if($canManage)<div class="rvr-table-foot"><button type="submit" class="btn btn-primary"><x-icon name="check" size="sm"/> Save floors &amp; ceilings</button><span class="tiny">Leave either field blank to remove that limit.</span></div>@endif
      </form>
      @endif
    </div></section>
  </div>

  <section class="card flush rvr-rules-card" aria-labelledby="rvrRulesTitle"><div class="card-head rvr-rules-head"><div><h3 id="rvrRulesTitle">Yield rules</h3><span class="tiny muted">Matching rules apply in order, lowest priority number first.</span></div><div class="rvr-rule-counts"><span class="rvr-count">{{ $activeCount }} active</span><span class="rvr-count paused">{{ $pausedCount }} paused</span></div></div>
    <div class="rvr-rules-wrap"><table class="table rvr-rules-table" aria-label="Revenue management yield rules"><thead><tr><th scope="col" class="center">Priority</th><th scope="col">Rule</th><th scope="col">Room type</th><th scope="col">Conditions</th><th scope="col" class="num">Adjustment</th><th scope="col" class="center">Min stay</th><th scope="col" class="center">Status</th><th scope="col"><span class="rvr-sr-only">Actions</span></th></tr></thead><tbody>
    @forelse($rules as $r)
      <tr class="{{ $r->is_active ? '' : 'rule-off' }}"><td class="center"><span class="rvr-priority">{{ $r->priority }}</span></td><th scope="row" class="rvr-rule-name">{{ $r->name }}</th><td>{{ $r->roomType?->name ?? 'All room types' }}</td>
        <td class="small rvr-condition">{{ $r->conditionLabel() }}</td><td class="num bold rvr-adjust {{ (float) $r->adjust_value >= 0 ? 'text-amber' : 'text-green' }}">{{ $r->adjustmentLabel() }}</td>
        <td class="center">{{ $r->min_stay ?: '—' }}</td><td class="center">{!! $r->is_active ? '<span class="badge badge-green">Active</span>' : '<span class="badge badge-slate">Paused</span>' !!}</td>
        <td class="right"><div class="rvr-row-actions">@if($canManage)
          <form method="post" action="{{ route('revenue.rules.toggle', $r) }}" data-confirm="{{ $r->is_active ? 'Pause' : 'Enable' }} the ‘{{ $r->name }}’ pricing rule?">@csrf<button type="submit" class="btn btn-sm btn-ghost">{{ $r->is_active ? 'Pause' : 'Enable' }}</button></form>
          <a class="btn btn-sm btn-ghost btn-icon rvr-icon-action" href="{{ route('revenue.rules.edit', $r) }}" aria-label="Edit {{ $r->name }}" title="Edit rule"><x-icon name="edit" size="sm"/></a>
          <form method="post" action="{{ route('revenue.rules.destroy', $r) }}" data-confirm="Delete the ‘{{ $r->name }}’ pricing rule? This cannot be undone.">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-ghost btn-icon rvr-icon-action" aria-label="Delete {{ $r->name }}" title="Delete rule"><x-icon name="trash" size="sm"/></button></form>
        @endif</div></td></tr>
    @empty
      <tr><td colspan="8"><div class="rvr-empty"><x-empty title="No pricing rules yet" text="Create a yield rule such as “raise rates when the hotel is over 80% full” or “discount low-demand last-minute nights”." icon="trend"/>@if($canManage)<div class="center mt-1"><a class="btn btn-primary" href="{{ route('revenue.rules.create') }}"><x-icon name="plus" size="sm"/> Add your first rule</a></div>@endif</div></td></tr>
    @endforelse
    </tbody></table></div>
  </section>
</div>
@endsection
