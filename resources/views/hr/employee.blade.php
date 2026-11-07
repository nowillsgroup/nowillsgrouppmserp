@extends('layouts.app')
@section('title', 'Employee file · '.$e->name)
@section('crumbs') <a href="{{ route('hr.register') }}">HR</a> / <a href="{{ route('masters.index', 'employees') }}">Employees</a> / {{ $e->emp_code }} @endsection
@section('actions')
<a class="btn" href="{{ route('masters.edit', ['employees', $e->id]) }}"><x-icon name="edit"/> Edit employee</a>
<a class="btn" href="{{ route('payroll.employee', $e) }}"><x-icon name="wallet"/> Pay setup</a>
@endsection
@push('head')
<style>
.hr-file{display:grid;grid-template-columns:168px minmax(0,1fr);gap:20px;align-items:start}
.hr-photo-wrap{display:grid;gap:8px;justify-items:center}
.hr-photo{display:block;width:168px;height:168px;object-fit:cover;border:1px solid var(--c-border);border-radius:14px;background:var(--c-bg)}
.hr-photo-empty{display:grid;place-items:center;color:#fff;font-size:44px;font-weight:800;letter-spacing:.02em;background:linear-gradient(135deg,#17553a,#2f8a5d)}
.hr-photo-form{display:grid;gap:6px;width:100%}
.hr-photo-form input[type=file]{font-size:.72rem}
.hr-identity h2{margin:0;font-size:1.15rem;letter-spacing:-.01em}
.hr-identity .hr-role{margin:.15rem 0 .6rem;color:var(--c-muted);font-size:.84rem}
.hr-facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px 16px;margin:0}
.hr-facts div{min-width:0}
.hr-facts dt{color:var(--c-muted);font-size:.64rem;font-weight:750;letter-spacing:.06em;text-transform:uppercase}
.hr-facts dd{margin:1px 0 0;font-size:.82rem;overflow-wrap:anywhere}
.hr-month{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
.hr-month span{padding:3px 8px;border:1px solid var(--c-border);border-radius:999px;background:var(--c-surface);font-size:.68rem;color:var(--c-text-2)}
.hr-month b{color:var(--c-text)}
.hr-doc-row-form{display:inline}
.hr-drop{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,.8fr) minmax(0,1fr) auto;gap:10px;align-items:end}
@media(max-width:900px){.hr-file{grid-template-columns:1fr}.hr-facts{grid-template-columns:repeat(2,minmax(0,1fr))}.hr-drop{grid-template-columns:1fr}}
</style>
@endpush

@section('content')

<div class="card"><div class="card-body hr-file">
  <div class="hr-photo-wrap">
    @if($photoUrl)
      <img class="hr-photo" src="{{ $photoUrl }}" alt="Photograph of {{ $e->name }}" width="168" height="168">
    @else
      <span class="hr-photo hr-photo-empty" role="img" aria-label="No photograph loaded for {{ $e->name }}">{{ $e->initials() }}</span>
    @endif
    @if(can_do('hr.manage'))
      <form class="hr-photo-form" method="post" action="{{ route('hr.employee.photo.save', $e) }}" enctype="multipart/form-data">@csrf
        <label class="sr-only" for="hrPhoto">Photograph for {{ $e->name }}</label>
        <input id="hrPhoto" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required>
        <button class="btn btn-sm btn-primary"><x-icon name="upload"/> {{ $photoUrl ? 'Replace photo' : 'Load photo' }}</button>
      </form>
      @if($photoUrl)
        <form method="post" action="{{ route('hr.employee.photo.remove', $e) }}">@csrf
          <button class="btn btn-sm btn-ghost"><x-icon name="x"/> Remove</button>
        </form>
      @endif
      <span class="tiny muted">JPEG, PNG or WebP · up to 5 MB</span>
    @endif
  </div>

  <div class="hr-identity">
    <h2>{{ $e->name }} <span class="badge {{ $e->is_active ? 'badge-green' : 'badge-slate' }}">{{ $e->is_active ? 'Active' : 'Inactive' }}</span></h2>
    <p class="hr-role">{{ $e->designation ?? 'Designation not recorded' }}@if($e->department) · {{ $e->department->name }}@endif</p>
    <dl class="hr-facts">
      <div><dt>Employee no.</dt><dd>{{ $e->emp_code }}</dd></div>
      <div><dt>Joined</dt><dd>{{ $e->joining_date ? dmy($e->joining_date) : '—' }}@if($e->leaving_date) · left {{ dmy($e->leaving_date) }}@endif</dd></div>
      <div><dt>Employment</dt><dd>{{ ['permanent' => 'Permanent', 'fixed_term' => 'Fixed-term', 'weekly' => 'Weekly-paid', 'casual' => 'Casual / daily-rated'][$e->employment_type] ?? ucfirst((string) $e->employment_type) }}</dd></div>
      <div><dt>Phone</dt><dd>{{ $e->phone ?? '—' }}</dd></div>
      <div><dt>E-mail</dt><dd>{{ $e->email ?? '—' }}</dd></div>
      <div><dt>TIN</dt><dd>{{ $e->tax_id ?? '—' }}</dd></div>
      <div><dt>SSNIT</dt><dd>{{ $e->social_security_no ?? '—' }}</dd></div>
      <div><dt>Login</dt><dd>{{ $e->user_id ? 'Linked to a user' : 'No login linked' }}</dd></div>
    </dl>
    <div class="hr-month" aria-label="Attendance this month">
      <span>This month · <b>{{ $month->format('F Y') }}</b></span>
      <span>Present <b>{{ $monthStatus['P'] ?? 0 }}</b></span>
      <span>Absent <b>{{ $monthStatus['A'] ?? 0 }}</b></span>
      <span>Leave <b>{{ $monthStatus['L'] ?? 0 }}</b></span>
      <span>Half day <b>{{ $monthStatus['H'] ?? 0 }}</b></span>
      <span>Week off <b>{{ $monthStatus['WO'] ?? 0 }}</b></span>
      <a class="tiny" href="{{ route('hr.register') }}">Open the monthly register</a>
    </div>
  </div>
</div></div>

<div class="card flush mt-2">
  <div class="card-head"><h3>Documents</h3><span class="small muted">{{ collect($docs)->count() }} on file · ID, contracts, certificates</span></div>
  <div class="card-body table-wrap"><table class="table table-compact" id="hrDocs">
    <caption class="sr-only">Documents held in the file of {{ $e->name }}</caption>
    <thead><tr><th scope="col">Document</th><th scope="col">Kind</th><th scope="col">From</th><th scope="col">Status</th><th scope="col" class="num">Size</th><th scope="col">Added</th><th scope="col"><span class="sr-only">Actions</span></th></tr></thead>
    <tbody>
    @forelse($docs as $d)
      <tr>
        <td><b>{{ $d->title }}</b>@if($d->review_note)<div class="tiny muted">{{ $d->review_note }}</div>@endif</td>
        <td class="small">{{ $d->kindLabel() }}</td>
        <td><span class="badge {{ $d->source === 'hr' ? 'badge-blue' : 'badge-slate' }}">{{ $d->sourceLabel() }}</span></td>
        <td><span class="badge badge-{{ $d->statusTone() }}">{{ $d->statusLabel() }}</span></td>
        <td class="num small">{{ $d->sizeLabel() }}</td>
        <td class="small">{{ $d->created_at?->format('d M Y') }}</td>
        <td class="right nowrap">
          <a class="btn btn-sm" href="{{ route('hr.document', $d) }}" target="_blank" rel="noopener" aria-label="Open {{ $d->title }}"><x-icon name="eye"/></a>
          @if(can_do('hr.manage') && $d->status === 'new')
            <form class="hr-doc-row-form" method="post" action="{{ route('hr.ess.doc', $d) }}">@csrf<input type="hidden" name="status" value="verified"><button class="btn btn-sm btn-ghost" aria-label="Verify {{ $d->title }}"><x-icon name="check"/></button></form>
            <form class="hr-doc-row-form" method="post" action="{{ route('hr.ess.doc', $d) }}">@csrf<input type="hidden" name="status" value="rejected"><button class="btn btn-sm btn-ghost" aria-label="Reject {{ $d->title }}"><x-icon name="x"/></button></form>
          @endif
          @if(can_do('hr.manage'))
            <form class="hr-doc-row-form" method="post" action="{{ route('hr.document.delete', $d) }}" onsubmit="return confirm('Remove {{ $d->title }} from the file? The stored file is deleted too.')">@csrf<button class="btn btn-sm btn-ghost" aria-label="Remove {{ $d->title }}"><x-icon name="trash"/></button></form>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="7" class="muted small">Nothing on file yet — load the contract, the ID and the certificates below.</td></tr>
    @endforelse
    </tbody></table></div>
</div>

@if(can_do('hr.manage'))
<div class="card mt-2">
  <div class="card-head"><h3>Load a document</h3><span class="small muted">PDF, JPEG, PNG or WebP · up to 10 MB · HR-loaded papers are marked verified</span></div>
  <div class="card-body">
    <form class="hr-drop" method="post" action="{{ route('hr.employee.documents.save', $e) }}" enctype="multipart/form-data">@csrf
      <div class="field"><label for="hrDocTitle">Title</label><input class="input" id="hrDocTitle" name="title" maxlength="200" placeholder="Signed contract, Ghana Card, food hygiene certificate"></div>
      <div class="field"><label for="hrDocKind">Kind</label><select class="input" id="hrDocKind" name="kind"><option value="">— Kind —</option>@foreach($kinds as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</select></div>
      <div class="field"><label for="hrDocFile">File</label><input class="input" id="hrDocFile" type="file" name="file" accept="application/pdf,image/jpeg,image/png,image/webp" required></div>
      <div class="field"><button class="btn btn-primary"><x-icon name="upload"/> Load into the file</button></div>
    </form>
    <p class="tiny muted mt-1">A document the member of staff sends through self-service waits on the <a href="{{ route('hr.ess') }}">self-service desk</a> for verification. What HR loads here is filed as verified.</p>
  </div>
</div>
@endif
@endsection
