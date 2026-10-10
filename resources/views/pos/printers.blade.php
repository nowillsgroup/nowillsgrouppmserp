@extends('layouts.app')
@section('title', 'KOT Printers')
@section('crumbs') POS / KOT Printers @endsection
@section('actions')
  <a class="btn" href="{{ route('kitchen.station') }}"><x-icon name="print"/> Print station</a>
  <a class="btn" href="{{ route('kitchen.index') }}">Kitchen display</a>
@endsection
@section('content')
<div class="alert alert-info"><x-icon name="print"/><div>A KOT can go to <b>more than one printer</b>. Hot food, the tandoor, pastry and the bar each get their own slip; the captain printer gets every item. Network printers (a private LAN address, port 9100) are sent when the KOT is fired. USB or Windows printers use a <a href="{{ route('kitchen.station') }}">print station</a> left open on that PC, or <b>Open each printer</b> on the ticket.</div></div>

<div class="g-2">
  <div class="card">
    <div class="card-head"><h3>Printers</h3></div>
    <div class="card-body table-wrap" style="padding:0">
      <table class="table table-compact">
        <thead><tr><th>Printer</th><th>Serves</th><th>How</th><th></th></tr></thead>
        <tbody>
        @forelse($printers as $p)
          <tr>
            <td><b>{{ $p->name }}</b> <span class="tiny muted">{{ $p->code }}</span>
              @unless($p->is_active)<span class="badge badge-slate">Off</span>@endunless
              @if($p->is_fallback)<span class="badge badge-amber">Fallback</span>@endif
              @if($p->header)<div class="tiny muted">{{ $p->header }}</div>@endif
              @if($p->notes)<div class="tiny muted">{{ $p->notes }}</div>@endif
            </td>
            <td class="small">{{ $p->role === 'captain' ? 'Captain copy' : ($p->station ?: '—') }}<div class="tiny muted">{{ $p->outlet?->name ?? 'All outlets' }} · {{ $p->paper }} mm · {{ $p->copies }}×</div></td>
            <td class="small">{{ $p->connection === 'network' ? ($p->host.':'.$p->port) : 'Browser' }}</td>
            <td class="right" style="white-space:nowrap">
              <a class="btn btn-sm" href="{{ route('pos.printers.test', $p) }}">Test</a>
              <button class="btn btn-sm" type="button" data-modal="editPrinter" data-action="{{ route('pos.printers.update', $p) }}" data-title="Edit {{ $p->name }}"
                data-f-code="{{ $p->code }}" data-f-name="{{ $p->name }}" data-f-outlet_id="{{ $p->outlet_id }}" data-f-station="{{ $p->station }}"
                data-f-role="{{ $p->role }}" data-f-connection="{{ $p->connection }}" data-f-host="{{ $p->host }}" data-f-port="{{ $p->port }}"
                data-f-paper="{{ $p->paper }}" data-f-copies="{{ $p->copies }}" data-f-header="{{ $p->header }}" data-f-sort_order="{{ $p->sort_order }}"
                data-f-notes="{{ $p->notes }}" data-f-beep="{{ $p->beep ? '1' : '0' }}" data-f-is_fallback="{{ $p->is_fallback ? '1' : '0' }}" data-f-is_active="{{ $p->is_active ? '1' : '0' }}">Edit</button>
              <form method="post" action="{{ route('pos.printers.destroy', $p) }}" style="display:inline" data-confirm="Remove {{ $p->name }}?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost"><x-icon name="trash" size="sm"/></button></form>
            </td>
          </tr>
        @empty
          <tr><td colspan="4"><x-empty text="No KOT printers yet. Add the hot kitchen, bar and a captain copy."/></td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>Add a printer</h3></div>
    <form method="post" action="{{ route('pos.printers.store') }}">@csrf
      <div class="card-body form-grid">
        @include('pos.printer-fields')
      </div>
      <div class="card-foot"><button class="btn btn-primary">Add printer</button></div>
    </form>
  </div>
</div>

<div class="card mt-2">
  <div class="card-head"><h3>Which dishes print where</h3><span class="tiny muted">Blank = follow the kitchen station. A chosen printer wins.</span></div>
  <form method="post" action="{{ route('pos.printers.routing') }}">@csrf
    <div class="card-body table-wrap" style="padding:0">
      <table class="table table-compact">
        <thead><tr><th>Category</th><th>Outlet</th><th>Station</th><th>Printer</th></tr></thead>
        <tbody>
        @foreach($categories as $c)
          <tr>
            <td>{{ $c->name }} <span class="tiny muted">{{ $c->items_count }}</span></td>
            <td class="small">{{ $c->outlet?->name ?? 'All' }}</td>
            <td class="small">{{ $c->kitchen_station ?: 'Kitchen' }}</td>
            <td>
              <select name="category[{{ $c->id }}]" class="input input-sm">
                <option value="">Auto — {{ $c->kitchen_station ?: 'Kitchen' }}</option>
                @foreach($printers->where('role', 'station')->where('is_active', true) as $p)
                  <option value="{{ $p->id }}" @selected($c->kot_printer_id == $p->id)>{{ $p->name }}{{ $p->outlet ? ' · '.$p->outlet->name : '' }}</option>
                @endforeach
              </select>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    <div class="card-foot"><button class="btn btn-primary">Save routing</button></div>
  </form>
</div>

<div class="card mt-2">
  <div class="card-head"><h3>Recent print jobs</h3></div>
  <div class="card-body table-wrap" style="padding:0">
    <table class="table table-compact">
      <thead><tr><th>When</th><th>KOT</th><th>Printer</th><th>Items</th><th>Status</th><th></th></tr></thead>
      <tbody>
      @forelse($jobs as $j)
        <tr>
          <td class="small">{{ $j->created_at->format('d M H:i') }}<div class="tiny muted">{{ $j->user?->name }} · {{ $j->mode }}</div></td>
          <td><a href="{{ route('pos.terminal', $j->pos_order_id) }}">{{ $j->order?->order_no }}</a><div class="tiny muted">#{{ $j->kot_no }} · {{ $j->kind }}</div></td>
          <td class="small">{{ $j->printer?->name ?? 'Combined / unrouted' }}</td>
          <td class="num">{{ count($j->item_ids ?? []) }}</td>
          <td>{!! status_badge($j->status) !!}@if($j->error)<div class="tiny text-red">{{ $j->error }}</div>@endif</td>
          <td class="right" style="white-space:nowrap">
            <a class="btn btn-sm" target="_blank" href="{{ route('pos.printkot', [$j->pos_order_id, $j->kot_no]) }}?job={{ $j->id }}">Slip</a>
            <form method="post" action="{{ route('pos.printers.retry', $j) }}" style="display:inline">@csrf<button class="btn btn-sm">{{ $j->printer?->connection === 'network' ? 'Send again' : 'Reprint' }}</button></form>
          </td>
        </tr>
      @empty
        <tr><td colspan="6"><x-empty text="No KOTs have been printed through a printer yet."/></td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
</div>

<div class="modal" id="editPrinter"><div class="modal-box" style="max-width:720px"><form method="post" data-reset>@csrf @method('PUT')
  <div class="modal-head"><h3 data-title>Edit printer</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body form-grid">@include('pos.printer-fields', ['edit' => true])</div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary">Save</button></div>
</form></div></div>
@endsection
