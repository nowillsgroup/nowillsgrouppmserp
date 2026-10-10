@extends('layouts.app')
@section('title', 'Database Backup')
@section('content')
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3>Create backup</h3></div><div class="card-body">
    <p class="muted">Backups are generated in pure PHP (no <code>mysqldump</code> required) and saved to <code>storage/app/backups</code>. A scheduled backup runs nightly at 02:30 when the Laravel scheduler cron is configured.</p>
    <form method="post" action="{{ route('settings.backup.download') }}" class="flex gap-1 wrap" data-no-lock="1">@csrf
      <button name="type" value="hotel" class="btn btn-primary"><x-icon name="download"/> Export this property's data</button>
      @if($full)<button name="type" value="full" class="btn"><x-icon name="download"/> Full database backup</button>@endif
    </form>
    <div class="alert alert-info mt-2 small">To restore a full backup: <code>mysql -u USER -p DBNAME &lt; full_YYYYMMDD_HHMMSS.sql</code> or import it via phpMyAdmin.</div>
  </div></div>
  <div class="card flush"><div class="card-head"><h3>Saved backups</h3></div><div class="card-body table-wrap"><table class="table">
    <thead><tr><th>File</th><th>Created</th><th class="num">Size</th><th></th></tr></thead><tbody>
    @forelse($files as $f)<tr><td class="small">{{ $f['name'] }}</td><td>{{ date('d M Y H:i', $f['time']) }}</td><td class="num">{{ number_format($f['size']/1024, 1) }} KB</td>
    <td><form method="post" action="{{ route('settings.backup.download') }}" data-no-lock="1">@csrf<input type="hidden" name="file" value="{{ $f['name'] }}"><button class="btn btn-sm"><x-icon name="download" size="sm"/></button></form></td></tr>
    @empty<tr><td colspan="4"><x-empty text="No backups yet." icon="download"/></td></tr>@endforelse
    </tbody></table></div></div>
</div>
@endsection
