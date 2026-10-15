@extends('layouts.app')
@section('title', 'My Documents')
@section('crumbs') {{ $e->name }} · {{ $e->emp_code }} @endsection

@if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h3>Upload a document</h3></div>
    <div class="card-body">
      <p class="muted">ID / passport, certificates, contracts — PDF, JPEG, PNG or WebP up to 10 MB. HR verifies each upload.</p>
      <form method="post" action="{{ route('me.documents.upload') }}" enctype="multipart/form-data">@csrf
        <div class="x-field"><label>Title</label><input name="title" maxlength="200" placeholder="e.g. Passport renewal 2026"></div>
        <div class="x-field"><label>Kind</label>
          <select name="kind"><option value="">—</option>@foreach ($kinds as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
        </div>
        <div class="x-field"><label>File</label><input type="file" name="file" required></div>
        @error('file')<div class="field-error">{{ $message }}</div>@enderror
        <button class="btn btn-primary" id="doUpload"><x-icon name="upload"/> Upload</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-head"><h3>My uploads</h3></div>
    <div class="card-body">
      @if ($docs->isEmpty())
        <x-empty text="No documents uploaded yet." icon="file"/>
      @else
        <table class="table table-compact">
          <thead><tr><th>Title</th><th>Kind</th><th>Size</th><th>Status</th><th>Uploaded</th></tr></thead>
          <tbody>
            @foreach ($docs as $d)
            <tr>
              <td>{{ $d->title }}</td>
              <td class="small">{{ $kinds[$d->kind] ?? '—' }}</td>
              <td class="small">{{ number_format($d->bytes / 1024, 0) }} KB</td>
              <td><span class="badge badge-{{ $d->statusTone() }}">{{ $d->statusLabel() }}</span>@if ($d->review_note)<div class="small muted">{{ $d->review_note }}</div>@endif</td>
              <td class="small">{{ $d->created_at->format('j M Y') }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </div>
</div>
