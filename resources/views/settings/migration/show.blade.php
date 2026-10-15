@extends('layouts.app')
@section('title', 'Migration batch '.$batch->code)
@section('content')
<div class="flex gap-1 wrap between">
  <div>
    <h2>{{ $batch->code }} — {{ $importer->label() }}</h2>
    <p class="muted small">Source: {{ $batch->source_name ?: '—' }} · file <code>{{ $batch->file_path }}</code> · uploaded {{ $batch->created_at->format('d M Y H:i') }} by {{ $batch->creator?->name ?? 'system' }}</p>
  </div>
  <div class="flex gap-1 wrap">
    <a class="btn" href="{{ route('migration.index') }}">← All batches</a>
    @if($batch->status !== 'imported')
      <form method="post" action="{{ route('migration.validate', $batch) }}">@csrf<button class="btn">Dry-run again</button></form>
      <form method="post" action="{{ route('migration.apply', $batch) }}" data-confirm="Import {{ $batch->rows_total }} rows of {{ $batch->dataset }} into the live database?">@csrf<input type="hidden" name="confirm" value="yes"><button class="btn btn-primary">Import (apply)</button></form>
    @endif
    <a class="btn" href="{{ route('migration.template', $batch->dataset) }}"><x-icon name="download" size="sm"/> Template</a>
  </div>
</div>

<div class="grid g-4 mt-2">
  <x-stat label="Rows in file" value="{{ number_format($batch->rows_total) }}" icon="upload"/>
  <x-stat label="Created / would create" value="{{ number_format($batch->rows_created) }}" icon="plus" tone="green"/>
  <x-stat label="Updated / would update" value="{{ number_format($batch->rows_updated) }}" icon="edit" tone="blue"/>
  <x-stat label="Failed" value="{{ number_format($batch->rows_failed) }}" icon="alert" tone="{{ $batch->rows_failed ? 'red' : 'slate' }}"/>
</div>

@if($report)
<div class="card mt-2"><div class="card-head"><h3>Reconciliation</h3></div><div class="card-body">
  <table class="table"><tbody>
  @foreach($report as $label => $n)<tr><td>{{ $label }}</td><td class="num">{{ is_numeric($n) ? number_format((float) $n) : $n }}</td></tr>@endforeach
  </tbody></table>
</div></div>
@endif

<div class="card flush mt-2"><div class="card-head"><h3>Row outcomes</h3>
  <div class="flex gap-1 small">
    @foreach(['pending'=>'slate','would_create'=>'blue','would_update'=>'blue','unchanged'=>'slate','created'=>'green','updated'=>'green','skipped'=>'slate','failed'=>'red'] as $s => $c)
      @if(($counts[$s] ?? 0) > 0)<span class="badge badge-{{ $c }}">{{ $s }}: {{ number_format($counts[$s]) }}</span>@endif
    @endforeach
  </div></div>
  <div class="card-body table-wrap"><table class="table">
    <thead><tr><th class="num">Row</th><th>External id</th><th>Status</th><th>Message</th></tr></thead>
    <tbody>
    @forelse($rows as $r)
      <tr>
        <td class="num">{{ $r->row_no }}</td>
        <td class="small">{{ $r->external_id }}</td>
        <td><span class="badge badge-{{ in_array($r->status, ['created','updated']) ? 'green' : ($r->status === 'failed' ? 'red' : (in_array($r->status, ['would_create','would_update']) ? 'blue' : 'slate')) }}">{{ $r->status }}</span></td>
        <td class="small">{{ $r->message }}</td>
      </tr>
    @empty
      <tr><td colspan="4"><x-empty text="No rows recorded." icon="upload"/></td></tr>
    @endforelse
    </tbody>
  </table>
  {{ $rows->links() }}
  </div></div>
@endsection
