@extends('layouts.app')
@section('title', 'Data retention')
@section('crumbs') <a href="{{ route('privacy.index') }}">Privacy</a> / Retention @endsection
@section('actions')
@if(can_do('privacy.erase'))<form method="post" action="{{ route('privacy.retention.run') }}" onsubmit="return confirm('Apply the retention policies now? Deleted scans and erased profiles cannot be recovered.')">@csrf<button class="btn btn-primary" id="runRetention"><x-icon name="refresh"/> Run now</button></form>@endif
@endsection
@section('content')
@include('privacy._nav')
@php $s = $settings; $ro = ! can_do('privacy.erase'); @endphp
<div class="grid g-2-1">
  <div>
    <div class="card flush"><div class="card-head"><h3>Due now (dry run)</h3><span class="tiny muted">{!! $s['retention_enabled'] ? 'Applied automatically every night at 03:30' : '<span class="badge badge-red">automatic run switched off</span>' !!}</span></div>
      <div class="card-body table-wrap"><table class="table" id="previewTable"><thead><tr><th>Policy</th><th class="num">Due</th><th>Examples</th></tr></thead><tbody>
      @foreach($preview as $k => $p)<tr class="{{ $p['count'] ? '' : 'muted' }}"><td>{{ $p['label'] }}</td><td class="num bold">{{ $p['count'] }}</td><td class="small">{{ implode(', ', $p['sample']) }}{{ $p['count'] > count($p['sample']) && $p['sample'] ? ' …' : '' }}</td></tr>@endforeach
      </tbody></table></div></div>
    <div class="card flush"><div class="card-head"><h3>Run history</h3></div><div class="card-body table-wrap"><table class="table" id="runTable">
      <thead><tr><th>When</th><th>Trigger</th>@foreach(\App\Services\PrivacyService::RETENTION_LABELS as $k => $l)<th class="num" title="{{ $l }}">{{ ['documents' => 'Scans', 'signatures' => 'Sigs', 'messages' => 'SMS', 'emails' => 'E-mails', 'dining' => 'Dining', 'inactive' => 'Profiles', 'holds_ended' => 'Holds', 'unverified' => 'Web req.'][$k] }}</th>@endforeach<th class="num">ms</th></tr></thead><tbody>
      @forelse($runs as $r)<tr><td class="small nowrap">{{ $r->created_at->format('d M Y H:i') }}</td><td class="small">{{ $r->trigger === 'manual' ? 'Manual · '.($r->user?->name ?? '') : 'Nightly' }}</td>
        @foreach(array_keys(\App\Services\PrivacyService::RETENTION_LABELS) as $k)<td class="num">{{ $r->counts[$k] ?? 0 ?: '·' }}</td>@endforeach<td class="num tiny muted">{{ $r->duration_ms }}</td></tr>
      @empty<tr><td colspan="10"><x-empty text="No retention runs yet." icon="clock"/></td></tr>@endforelse</tbody></table></div></div>
  </div>
  <form method="post" action="{{ route('privacy.settings') }}" class="card" id="retentionForm">@csrf
    <div class="card-head"><h3>Policies &amp; deadlines</h3></div>
    <div class="card-body form-grid" style="grid-template-columns:1fr 1fr">
      <label class="check span-all"><input type="checkbox" name="retention_enabled" value="1" @checked($s['retention_enabled']) @disabled($ro)> Apply retention automatically every night</label>
      <x-field name="retention_id_days" label="ID scans & e-signatures (days after check-out)" type="number" :value="$s['retention_id_days']" min="0" required :disabled="$ro" hint="0 = keep"/>
      <x-field name="retention_message_days" label="SMS / WhatsApp / e-mail contents (days)" type="number" :value="$s['retention_message_days']" min="0" required :disabled="$ro"/>
      <x-field name="retention_inactive_years" label="Anonymise profiles inactive for (years)" type="number" :value="$s['retention_inactive_years']" min="0" required :disabled="$ro" hint="0 = never"/>
      <x-field name="retention_unverified_days" label="Close unconfirmed web requests after (days)" type="number" :value="$s['retention_unverified_days']" min="1" required :disabled="$ro"/>
      <x-field name="privacy_tax_years" label="Tax-invoice legal hold (years)" type="number" :value="$s['privacy_tax_years']" min="0" required :disabled="$ro" hint="GRA: keep tax records and invoices for at least 6 years"/>
      <x-field name="privacy_police_years" label="Police / Form-C hold (years after stay)" type="number" :value="$s['privacy_police_years']" min="0" required :disabled="$ro"/>
      <x-field name="privacy_response_days" label="Reply to requests within (days)" type="number" :value="$s['privacy_response_days']" min="1" required :disabled="$ro"/>
      <x-field name="privacy_extension_days" label="One-off extension (days)" type="number" :value="$s['privacy_extension_days']" min="0" required :disabled="$ro"/>
      <x-field name="breach_notify_hours" label="Notify breaches within (hours)" type="number" :value="$s['breach_notify_hours']" min="1" required :disabled="$ro"/>
      <x-field name="dpo_name" label="Data Protection Officer / grievance officer" :value="$s['dpo_name']" :disabled="$ro"/>
      <x-field name="dpo_email" label="DPO e-mail" type="email" :value="$s['dpo_email']" :disabled="$ro"/>
      <x-field name="dpo_phone" label="DPO phone" :value="$s['dpo_phone']" :disabled="$ro"/>
      <x-field name="privacy_notice" label="Privacy notice (public page)" type="textarea" :value="$s['privacy_notice']" class="span-all" rows="8" :disabled="$ro"/>
    </div>
    @unless($ro)<div class="card-foot"><button class="btn btn-primary" id="saveRetention"><x-icon name="check"/> Save</button></div>@endunless
  </form>
</div>
@endsection
