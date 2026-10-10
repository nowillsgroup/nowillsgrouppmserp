@extends('layouts.app')
@section('title', 'Loyalty Program')
@section('crumbs') Guests &amp; CRM / Loyalty @endsection
@section('actions')
<form class="filters" method="get"><div class="field"><input type="search" name="q" value="{{ $q }}" placeholder="Search member…"></div></form>
@if(can_do('guests.manage'))<button class="btn btn-primary" data-modal="adjModal"><x-icon name="plus"/> Adjust points</button>@endif
@if(can_do('settings.manage'))<a class="btn" href="{{ route('settings.index') }}#loyalty"><x-icon name="settings"/> Programme settings</a>@endif
@endsection
@section('content')
@unless($enabled)<div class="alert alert-warn"><x-icon name="alert"/><div>The loyalty programme is <b>switched off</b>. Guests will not earn points until it is enabled in Hotel Settings.</div></div>@endunless
<div class="stats">
  <x-stat label="Members with points" :value="number_format($stats['members'])" icon="users" tone="blue"/>
  <x-stat label="Outstanding points" :value="number_format($stats['outstanding'])" icon="star" tone="purple" :sub="'Liability '.money($stats['outstanding'] * $pointValue)"/>
  <x-stat label="Earned this month" :value="number_format($stats['earned_mtd'])" icon="chart" tone="green"/>
  <x-stat label="Redeemed this month" :value="number_format($stats['redeemed_mtd'])" icon="receipt" tone="amber"/>
</div>
<p class="small muted">Rules: 1 point per {{ money($spendPer) }} net spend, credited when the folio is settled · 1 point = {{ money($pointValue) }} · minimum redemption {{ $minRedeem }} points · tiers Silver 500, Gold 2,000, Platinum 5,000.</p>
<div class="grid g-2">
  <div class="card flush"><div class="card-head"><h3>Members</h3></div><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Guest</th><th>Phone</th><th>Tier</th><th class="num">Points</th><th class="num">Value</th><th></th></tr></thead><tbody>
    @forelse($members as $g)
      <tr><td><a href="{{ route('guests.show', $g) }}" class="bold">{{ $g->full_name }}</a>@if($g->is_vip) <span class="badge badge-amber">VIP</span>@endif</td><td class="small">{{ $g->phone }}</td>
      <td><span class="badge badge-purple">{{ \App\Services\LoyaltyService::tier($g->loyalty_points) }}</span></td><td class="num bold">{{ number_format($g->loyalty_points) }}</td><td class="num">{{ money($g->loyalty_points * $pointValue) }}</td>
      <td class="right">@if(can_do('guests.manage'))<button class="btn btn-sm btn-ghost" data-modal="adjModal" data-fguest="{{ $g->id }}">Adjust</button>@endif</td></tr>
    @empty <tr><td colspan="6"><x-empty title="No members yet" text="Guests earn points automatically when their folio is settled."/></td></tr> @endforelse
    </tbody></table></div>
    @if($members->hasPages())<div class="card-foot">{{ $members->links() }}</div>@endif</div>
  <div class="card flush"><div class="card-head"><h3>Recent activity</h3></div><div class="card-body table-wrap"><table class="table table-compact">
    <thead><tr><th>Date</th><th>Guest</th><th>Details</th><th class="num">Points</th></tr></thead><tbody>
    @forelse($ledger as $e)
      <tr><td class="small nowrap">{{ $e->created_at->format('d M H:i') }}</td><td>{{ $e->guest?->full_name }}</td><td class="small">{{ $e->description }}@if($e->folio) · <a href="{{ route('folios.show', $e->folio) }}">{{ $e->folio->folio_no }}</a>@endif<div class="tiny muted">{{ $e->user?->name }}</div></td>
      <td class="num bold {{ $e->points < 0 ? 'text-red' : 'text-green' }}">{{ $e->points > 0 ? '+' : '' }}{{ number_format($e->points) }}</td></tr>
    @empty <tr><td colspan="4" class="muted">No loyalty transactions yet.</td></tr> @endforelse
    </tbody></table></div></div>
</div>

@if(can_do('guests.manage'))
<div class="modal" id="adjModal"><div class="modal-box"><form method="post" action="" id="adjForm">@csrf
  <div class="modal-head"><h3>Adjust loyalty points</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">
    <div class="field span-all"><label>Guest <span class="req">*</span></label><select name="guest" id="adjGuest" required><option value="">Select guest</option>@foreach($guests as $g)<option value="{{ $g->id }}">{{ $g->full_name }} · {{ $g->phone }} ({{ $g->loyalty_points }} pts)</option>@endforeach</select></div>
    <x-field name="points" label="Points (+ add / − deduct)" type="number" required/>
    <x-field name="reason" label="Reason" required placeholder="Goodwill, correction…"/>
  </div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Cancel</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endif
@endsection
@push('scripts')
<script>
const adjForm = document.getElementById('adjForm');
adjForm?.addEventListener('submit', () => { adjForm.action = @json(url('/loyalty/adjust')) + '/' + document.getElementById('adjGuest').value; });
</script>
@endpush
