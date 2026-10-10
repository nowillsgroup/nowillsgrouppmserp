@extends('layouts.app')
@section('title', 'Reports')
@section('content')
<div class="grid g-3">
@foreach($groups as $group => $reports)
<div class="card"><div class="card-head"><h3>{{ $group }}</h3></div><div class="card-body"><ul class="list">
@foreach($reports as $slug => $r)<li><a href="{{ route('reports.show', $slug) }}" class="bold">{{ $r[1] }}</a><div class="tiny muted">{{ $r[2] }}</div></li>@endforeach
</ul></div></div>
@endforeach
</div>
@endsection
