{{-- $acts, $showAccount (bool) --}}
@forelse($acts as $a)@php $mayEdit = \App\Services\SalesService::canEdit(auth()->user(), $a->owner_id); @endphp
  <div class="sa-act sa-act-{{ $a->status }} {{ $a->isOverdue() ? 'sa-act-overdue' : '' }}" data-activity="{{ $a->id }}" data-status="{{ $a->status }}">
    <div class="sa-act-icon"><x-icon :name="$a->typeIcon()"/></div>
    <div class="sa-act-main"><div class="between"><b>{{ $a->subject }}</b><span class="badge badge-{{ $a->isOverdue() ? 'red' : $a->statusColor() }}">{{ $a->isOverdue() ? 'Overdue' : $a->statusLabel() }}</span></div>
      <div class="tiny muted">{{ $a->typeLabel() }} · {{ ($a->done_at ?? $a->due_at)->format('D d M Y H:i') }} · {{ $a->owner?->name }}@if($showAccount && $a->company) · <a href="{{ route('sales.account', $a->company) }}">{{ $a->company->name }}</a>@endif{{ $a->contact ? ' · with '.$a->contact->name : '' }}@if($a->opportunity && ($showOpp ?? true)) · <a href="{{ route('sales.opportunity', $a->opportunity) }}">{{ $a->opportunity->number }}</a>@endif{{ (float) $a->cost ? ' · '.money($a->cost) : '' }}</div>
      @if($a->outcome)<div class="small sa-outcome">{{ $a->outcome }}</div>@endif
      @if($a->status === 'planned' && $mayEdit)<div class="flex gap-1 mt-05">
        <button class="btn btn-sm" data-modal="doneModal" data-action="{{ route('sales.activity.complete', $a) }}" data-title="Complete: {{ $a->subject }}" data-done="{{ $a->id }}"><x-icon name="check"/> Done</button>
        <button class="btn btn-sm btn-ghost" data-modal="cancelActModal" data-action="{{ route('sales.activity.cancel', $a) }}" data-title="Cancel: {{ $a->subject }}" data-cancel="{{ $a->id }}">Cancel</button></div>@endif
    </div></div>
@empty<div class="muted small pad">No activities yet.</div>@endforelse
