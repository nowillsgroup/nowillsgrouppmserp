@extends('layouts.app')
@section('title', 'Depreciation')
@section('crumbs') <a href="{{ route('assets.index') }}">Fixed Assets</a> / Depreciation @endsection
@section('content')
<div class="grid g-2-1">
<div>
  <div class="card" id="nextRun"><div class="card-head"><h3>{{ $next ? 'Next run: '.$next->format('F Y') : 'Next run' }}</h3>
    @if($next && ! $blocker && can_do('assets.depreciation'))
      <form method="post" action="{{ route('assets.depreciation.run') }}" class="inline" onsubmit="return confirm('Post depreciation of {{ money($preview->sum('amount')) }} for {{ $next->format('F Y') }}?')">@csrf<input type="hidden" name="period" value="{{ $next->format('Y-m') }}"><button class="btn btn-primary" id="postRun"><x-icon name="check"/> Post {{ $next->format('M Y') }} – {{ money($preview->sum('amount')) }}</button></form>
    @endif
  </div>
  <div class="card-body">
    @if($blocker)<div class="alert alert-info m-0" id="runBlocker"><x-icon name="clock"/><div>{{ $blocker }}</div></div>@endif
    @if($next && $preview->isNotEmpty())
    <div class="table-wrap {{ $blocker ? 'mt-1' : '' }}"><table class="table table-compact" id="previewTable">
      <thead><tr><th>Asset</th><th>Category</th><th class="num">Book value</th><th class="num">Depreciation</th><th class="num">After</th></tr></thead><tbody>
      @foreach($preview as $r)<tr><td><a href="{{ route('assets.show', $r->asset) }}">{{ $r->asset->asset_no }}</a> <span class="small">{{ \Illuminate\Support\Str::limit($r->asset->name, 40) }}</span>@if($r->days < $next->daysInMonth)<span class="tiny muted"> · {{ $r->days }} days</span>@endif</td><td class="small">{{ $r->asset->category?->name }}</td><td class="num">{{ money($r->opening_nbv, false) }}</td><td class="num">{{ money($r->amount, false) }}</td><td class="num">{{ money($r->opening_nbv - $r->amount, false) }}</td></tr>@endforeach
      </tbody><tfoot><tr><td colspan="3" class="right">{{ $preview->count() }} assets</td><td class="num bold">{{ money($preview->sum('amount')) }}</td><td></td></tr></tfoot></table></div>
    @elseif($next)<p class="muted m-0">Nothing to depreciate for {{ $next->format('F Y') }}.</p>@endif
  </div></div>
</div>
<div>
  <div class="card flush"><div class="card-head"><h3>Runs</h3></div><div class="card-body table-wrap"><table class="table table-compact" id="runTable">
    <thead><tr><th>Month</th><th>Run</th><th class="num">Assets</th><th class="num">Amount</th><th></th></tr></thead><tbody>
    @forelse($runs as $r)<tr class="{{ $r->status === 'reversed' ? 'muted' : '' }}"><td>{{ $r->period_end->format('M Y') }}</td><td><a href="{{ route('assets.depreciation.show', $r) }}">{{ $r->run_no }}</a></td><td class="num">{{ $r->assets }}</td><td class="num">{{ money($r->total, false) }}</td><td>@if($r->status === 'reversed')<span class="badge badge-slate">Reversed</span>@else<span class="badge badge-green">Posted</span>@endif</td></tr>
    @empty<tr><td colspan="5" class="muted center">No runs yet.</td></tr>@endforelse
    </tbody></table>{{ $runs->links() }}</div></div>
  <div class="card"><div class="card-body small muted">Runs go month by month and post <b>Dr Depreciation / Cr Accumulated depreciation</b> dated the month-end, one line per category. A month can be run once it's over. Made a mistake? Reverse the latest run, fix the asset, and run it again.</div></div>
</div>
</div>
@endsection
