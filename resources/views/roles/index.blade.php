@extends('layouts.app')
@section('title', 'Roles & Permissions')
@section('actions')<a class="btn btn-primary" href="{{ route('roles.create') }}"><x-icon name="plus"/> New Role</a>@endsection
@section('content')
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr><th>Role</th><th>Description</th><th>Permissions</th><th class="num">Users</th><th></th></tr></thead><tbody>
@foreach($roles as $r)<tr><td class="bold">{{ $r->name }} @if($r->is_system)<span class="badge badge-slate">System</span>@endif</td><td class="small">{{ $r->description }}</td>
<td style="min-width:200px"><div class="progress"><span style="width:{{ $total ? round($r->permissions_count*100/$total) : 0 }}%"></span></div><span class="tiny muted">{{ $r->permissions_count }} / {{ $total }}</span></td>
<td class="num">{{ $counts[$r->id] ?? 0 }}</td>
<td class="right"><a class="btn btn-sm" href="{{ route('roles.edit', $r) }}"><x-icon name="edit" size="sm"/></a>
@if(!$r->is_system)<form method="post" action="{{ route('roles.destroy', $r) }}" style="display:inline" data-confirm="Delete role {{ $r->name }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost"><x-icon name="trash" size="sm"/></button></form>@endif</td></tr>@endforeach
</tbody></table></div></div>
@endsection
