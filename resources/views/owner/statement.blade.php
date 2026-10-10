@extends('layouts.app')
@section('title', 'Owner Pack '.$s->label())
@section('crumbs') Owner Reporting / <a href="{{ route('owner.statements') }}">Owner packs</a> / {{ $s->label() }} @endsection
@php
  $OS = \App\Models\OwnerStatement::class; $OQ = \App\Models\OwnerQuery::class;
  $canOp = can_do('owner.operate'); $canApprove = can_do('owner.approve');
  $draft = $s->status === 'draft'; $live = in_array($s->status, ['issued', 'queried'], true);
  $waiting = $s->queries->where('status', 'open')->count();
@endphp
@section('actions')
  @unless($draft)<a class="btn" href="{{ route('owner.statements.show', ['period' => $s->period, 'print' => 1]) }}" target="_blank" id="printPack"><x-icon name="print"/> Print / PDF</a>@endunless
  @if($draft && $canOp)
    <form method="post" action="{{ route('owner.statements.issue', $s) }}" class="inline" data-confirm="Issue the {{ $s->label() }} pack to the owner? The figures and commentary are frozen.">@csrf<button class="btn btn-primary" id="issueBtn" @disabled($blockers)><x-icon name="mail"/> Issue to owner</button></form>
  @endif
  @if($live && $canApprove)
    <form method="post" action="{{ route('owner.statements.ack', $s) }}" class="inline" data-confirm="Acknowledge the {{ $s->label() }} pack?">@csrf<button class="btn btn-primary" id="ackBtn" @disabled($waiting)><x-icon name="check"/> Acknowledge</button></form>
  @endif
@endsection
@section('content')
<div class="card" id="packHead"><div class="card-body between-row wrap">
  <div><h2 style="margin:0">{{ $s->label() }} owner pack</h2>
    <div class="small muted">@if($snap['agreement'] ?? null){{ $snap['agreement']['operator'] }} → {{ $snap['agreement']['owner'] }}{{ $snap['agreement']['ref'] ? ' · '.$snap['agreement']['ref'] : '' }} · @endif{{ $snap['fy_label'] ?? '' }}
    · {{ $draft ? 'live figures (frozen on issue)' : 'figures as issued '.$s->issued_at?->format('d M Y H:i') }}</div></div>
  <div class="right"><span class="badge badge-{{ $OS::TONES[$s->status] }}" id="packStatus">{{ $OS::STATUSES[$s->status] }}</span>
    <div class="tiny muted">@if($s->issued_at)issued by {{ $s->issuer?->name }}@endif @if($s->acknowledged_at) · acknowledged by {{ $s->acknowledger?->name }} {{ $s->acknowledged_at->format('d M Y') }}@endif</div></div>
</div></div>

@if($draft && $blockers)
  <div class="alert alert-warn mt-1" id="blockers"><x-icon name="alert"/> Before issuing: <ul style="margin:.2rem 0 0 1rem">@foreach($blockers as $b)<li>{{ $b }}</li>@endforeach</ul></div>
@endif

<div class="card mt-1" id="commentaryCard"><div class="card-head"><h3><x-icon name="edit"/> General manager's commentary</h3></div><div class="card-body">
  @if($draft && $canOp)
    <form method="post" action="{{ route('owner.statements.update', $s) }}" id="commentaryForm">@csrf @method('PUT')
      <textarea name="commentary" id="commentary" rows="10" maxlength="10000" style="width:100%">{{ old('commentary', $s->commentary) }}</textarea>
      <button class="btn mt-1" id="saveCommentary">Save commentary</button></form>
  @else
    <div class="pack-commentary">{{ $s->commentary }}</div>
  @endif
</div></div>

<div class="card flush mt-1" id="packStatement"><div class="card-head"><h3><x-icon name="chart"/> Summary Operating Statement</h3><span class="tiny muted">GH₵ · USALI 11th edition, operator format</span></div>
  <div class="card-body">@include('owner._usali', ['cols' => $snap['cols'], 'layout' => $layout, 'drill' => $draft ? $s->period : null])</div></div>

<div class="grid g-2 mt-1">
  <div class="card flush"><div class="card-head"><h3><x-icon name="star"/> KPIs</h3></div><div class="card-body">@include('owner._kpis', ['kpis' => $snap['kpis']])</div></div>
  <div>
    <div class="card" id="packFees"><div class="card-head"><h3><x-icon name="money"/> Management fees</h3></div><div class="card-body">
      @if($f = $snap['fee'] ?? null)
        <dl class="kv"><dt>Base fee</dt><dd>{{ money($f['base_fee']) }} <span class="tiny muted">({{ $snap['agreement']['base'] ?? '' }}% of {{ money($f['total_revenue']) }})</span></dd>
          <dt>{{ ($snap['agreement']['basis'] ?? 'agop') === 'agop' ? 'Adjusted GOP' : 'GOP' }}</dt><dd>{{ money($f['agop']) }} <span class="tiny muted">fee year {{ money($f['agop_ytd']) }} vs priority {{ money($f['priority_ytd']) }}</span></dd>
          <dt>Incentive fee</dt><dd>{{ money($f['incentive_fee']) }}</dd><dt>Total this month</dt><dd><b>{{ money($f['total_fee']) }}</b></dd><dt>Fee year to date</dt><dd>{{ money($snap['fees_ytd']) }}</dd>
          <dt>FF&E contribution</dt><dd>{{ money($f['ffe_amount']) }} <span class="tiny muted">({{ rtrim(rtrim(number_format($f['ffe_pct'], 2), '0'), '.') }}% of revenue)</span></dd></dl>
      @else<div class="tiny muted">No management fees for this month.</div>@endif
    </div></div>
    <div class="card mt-1" id="packReserve"><div class="card-head"><h3><x-icon name="wallet"/> FF&E reserve & capex</h3></div><div class="card-body">
      <dl class="kv"><dt>Reserve at month end</dt><dd>{{ money($snap['reserve']['balance']) }}</dd>@if($snap['reserve']['funded'] !== null)<dt>Cash in reserve account</dt><dd>{{ money($snap['reserve']['funded']) }}</dd>@endif</dl>
      @if($snap['capex'])
        <table class="table table-compact mt-1"><thead><tr><th>Project</th><th>Status</th><th class="num">Budget</th><th class="num">Spent</th></tr></thead><tbody>
        @foreach($snap['capex'] as $c)<tr><td class="small"><b>{{ $c['number'] }}</b> {{ $c['title'] }}<div class="tiny muted">{{ $c['funding'] }}</div></td><td class="small">{{ $c['status'] }}</td><td class="num">{{ money($c['budget'], false) }}</td><td class="num">{{ money($c['spent'], false) }}</td></tr>@endforeach
        </tbody></table>
      @endif
    </div></div>
  </div>
</div>

<div class="card mt-1" id="queriesCard"><div class="card-head"><h3><x-icon name="message"/> Owner queries</h3></div><div class="card-body">
  @forelse($s->queries as $q)
    <div class="oq" id="q{{ $q->id }}" data-query="{{ $q->id }}" data-status="{{ $q->status }}">
      <div class="oq-q"><b>{{ $q->raiser?->name }}</b> <span class="tiny muted">{{ $q->created_at->format('d M Y H:i') }}</span> <span class="badge badge-{{ ['open' => 'amber', 'answered' => 'blue', 'closed' => 'slate'][$q->status] }}">{{ $OQ::STATUSES[$q->status] }}</span><div>{{ $q->body }}</div></div>
      @if($q->reply)<div class="oq-a"><b>{{ $q->replier?->name }}</b> <span class="tiny muted">{{ $q->replied_at?->format('d M Y H:i') }}</span><div>{{ $q->reply }}</div></div>@endif
      @if($q->status === 'open' && $canOp)
        <form method="post" action="{{ route('owner.queries.reply', $q) }}" class="oq-form">@csrf<textarea name="reply" rows="2" maxlength="4000" required placeholder="Answer the owner" aria-label="Answer"></textarea><button class="btn btn-sm btn-primary">Send answer</button></form>
      @endif
      @if($q->status === 'answered' && ($canApprove || $canOp))
        <form method="post" action="{{ route('owner.queries.close', $q) }}" class="inline">@csrf<button class="btn btn-sm">Close query</button></form>
      @endif
    </div>
  @empty<div class="tiny muted">No queries on this pack.</div>@endforelse
  @if($live && $canApprove)
    <form method="post" action="{{ route('owner.queries.store', $s) }}" class="oq-form mt-1" id="queryForm">@csrf<textarea name="body" rows="2" maxlength="4000" required placeholder="Ask the operator about this month's results" aria-label="Question"></textarea><button class="btn btn-sm btn-primary">Send query</button></form>
  @endif
</div></div>
@endsection
