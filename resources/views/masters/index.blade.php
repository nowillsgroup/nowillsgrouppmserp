@extends('layouts.app')
@section('title', $def['title'])
@section('crumbs') Masters / {{ $def['title'] }} @endsection
@section('actions')<a class="btn btn-primary" href="{{ route('masters.create', $def['slug']) }}"><x-icon name="plus"/> Add New</a>@endsection
@section('content')
@if(!empty($def['hint']))<div class="alert alert-info">{{ $def['hint'] }}</div>@endif
<div class="card"><div class="card-body"><form class="filters"><div class="field"><input name="q" value="{{ request('q') }}" placeholder="Search {{ strtolower($def['title']) }}"></div><button class="btn btn-primary">Search</button>@if(request('q'))<a class="btn btn-ghost" href="{{ route('masters.index', $def['slug']) }}">Clear</a>@endif</form></div></div>
<div class="card flush"><div class="card-body table-wrap"><table class="table">
<thead><tr>@foreach($columns as $path => $label)<th>{{ $label }}</th>@endforeach<th style="width:110px"></th></tr></thead><tbody>
@forelse($rows as $row)
<tr>@foreach($columns as $path => $label)@php $v = data_get($row, $path); $ft = $def['fields'][$path][1] ?? null; @endphp
<td>@if($path === 'image_path')@php $url = method_exists($row, 'imageUrl') ? $row->imageUrl() : null; @endphp @if($url)<img src="{{ $url }}" alt="" class="menu-thumb">@else<span class="tiny muted">—</span>@endif @elseif($ft === 'bool' || is_bool($v)){!! $v ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-slate">No</span>' !!}@elseif(in_array($path, ['status','hk_status'])){!! status_badge($v) !!}@elseif($v instanceof \Carbon\CarbonInterface){{ $v->format('d M Y') }}@else{{ is_string($v) ? str_replace('_',' ', $v) : $v }}@endif</td>
@endforeach
<td class="right">@if($def['slug'] === 'employees' && can_do('payroll.manage'))<a class="btn btn-sm" href="{{ route('payroll.employee', $row->id) }}" title="Pay setup"><x-icon name="money" size="sm"/></a> @endif<a class="btn btn-sm" href="{{ route('masters.edit', [$def['slug'], $row->id]) }}"><x-icon name="edit" size="sm"/></a>
@unless($row->getAttribute('system_key'))<form method="post" action="{{ route('masters.destroy', [$def['slug'], $row->id]) }}" style="display:inline" data-confirm="Delete this record?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost"><x-icon name="trash" size="sm"/></button></form>@endunless</td></tr>
@empty<tr><td colspan="{{ count($columns) + 1 }}"><x-empty text="No records yet."><a class="btn btn-primary btn-sm mt-1" href="{{ route('masters.create', $def['slug']) }}">Add the first one</a></x-empty></td></tr>@endforelse
</tbody></table>{{ $rows->links() }}</div></div>
@endsection
