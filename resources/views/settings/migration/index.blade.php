@extends('layouts.app')
@section('title', 'Data Migration')
@section('content')
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3>Import a legacy export</h3></div><div class="card-body">
    <p class="muted">Cut guest, company, employee, room and reservation data over from a legacy PMS. Upload one CSV per dataset — start with companies, then guests, employees, rooms and finally reservations. Each upload is dry-run first; nothing is written until you press <b>Import</b> on the batch.</p>
    <form method="post" action="{{ route('migration.store') }}" enctype="multipart/form-data" class="form-grid">
      @csrf
      <x-field name="dataset" label="Dataset" type="select" :options="array_combine($order, array_map(fn ($k) => $k.' — '.$importers[$k]->label(), $order))" :placeholder="false" :value="old('dataset', 'companies')"/>
      <x-field name="source_name" label="Source (legacy system / export date)" value="{{ old('source_name') }}" placeholder="e.g. Exceed PMS export 2026-10"/>
      <div class="field span-2"><label for="f_file">CSV file</label><input type="file" name="file" id="f_file" accept=".csv,.txt,.tsv,text/csv" required></div>
      <div class="span-2"><button class="btn btn-primary">Upload &amp; dry-run</button></div>
    </form>
    <div class="alert alert-info mt-2 small">
      CSV templates:
      @foreach($order as $k)
        <a href="{{ route('migration.template', $k) }}">{{ $k }}</a>@if(!$loop->last), @endif
      @endforeach
      — see <code>docs/DATA-MIGRATION.md</code> for the cutover runbook.
    </div>
  </div></div>

  <div class="card flush"><div class="card-head"><h3>How cutover works</h3></div><div class="card-body small muted">
    <ol class="ml-1">
      <li>Every row must carry a stable <b>external_id</b> from the legacy system.</li>
      <li>Dry-run labels each row <i>would create</i>, <i>would update</i> or <i>failed</i> with the reason.</li>
      <li>Import is idempotent: re-uploads update changed rows and skip unchanged ones; already-imported reservations are never duplicated.</li>
      <li>Cross-references (guest's company, reservation's guest) resolve through the id map — import in the listed order.</li>
      <li>Every batch keeps its source file, per-row outcomes and a reconciliation report.</li>
    </ol>
  </div></div>
</div>

<div class="card flush mt-2"><div class="card-head"><h3>Batches</h3></div><div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Batch</th><th>Dataset</th><th>Source</th><th class="num">Rows</th><th class="num">Created</th><th class="num">Updated</th><th class="num">Skipped</th><th class="num">Failed</th><th>Status</th><th>Uploaded</th></tr></thead>
  <tbody>
  @forelse($batches as $b)
    <tr>
      <td><a href="{{ route('migration.show', $b) }}">{{ $b->code }}</a></td>
      <td>{{ $b->dataset }}</td>
      <td class="small">{{ $b->source_name }}</td>
      <td class="num">{{ number_format($b->rows_total) }}</td>
      <td class="num">{{ number_format($b->rows_created) }}</td>
      <td class="num">{{ number_format($b->rows_updated) }}</td>
      <td class="num">{{ number_format($b->rows_skipped) }}</td>
      <td class="num">{!! $b->rows_failed ? '<b class="bad">'.$b->rows_failed.'</b>' : '0' !!}</td>
      <td><span class="badge badge-{{ $b->status === 'imported' ? 'green' : ($b->status === 'failed' ? 'red' : ($b->status === 'validated' ? 'blue' : 'slate')) }}">{{ $b->status }}</span></td>
      <td class="small">{{ $b->created_at->format('d M Y H:i') }}</td>
    </tr>
  @empty
    <tr><td colspan="10"><x-empty text="No migration batches yet." icon="upload"/></td></tr>
  @endforelse
  </tbody>
</table>
{{ $batches->links() }}
</div></div>
@endsection
