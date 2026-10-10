@if($docs->isEmpty())
  <p class="small muted" style="margin-top:.6rem">No documents on file.</p>
@else
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.8rem;margin-top:.8rem">
  @foreach($docs->sortByDesc('id') as $d)
    <div style="border:1px solid var(--c-border);border-radius:var(--radius-sm);padding:.5rem" class="doc-card">
      <a href="{{ route('guests.documents.show', $d) }}" target="_blank" rel="noopener" style="display:block;height:96px;background:var(--c-slate-50);border-radius:6px;overflow:hidden;text-align:center">
        @if(str_starts_with($d->mime, 'image/'))<img src="{{ route('guests.documents.show', $d) }}" alt="" style="height:96px;max-width:100%;object-fit:cover">
        @else<div style="padding-top:34px" class="small bold">PDF</div>@endif
      </a>
      <div class="small bold" style="margin-top:.3rem">{{ \App\Models\GuestDocument::TYPES[$d->type] ?? $d->type }}</div>
      <div class="tiny muted">{{ $d->created_at->format('d M Y') }} · {{ $d->size < 1024 ? '<1' : number_format($d->size / 1024) }} KB</div>
      @if(can_do('guests.manage'))<form method="post" action="{{ route('guests.documents.destroy', $d) }}" data-confirm="Delete this document permanently?">@csrf @method('DELETE')<button class="btn btn-sm btn-ghost text-red" style="padding-left:0"><x-icon name="trash" size="sm"/> Delete</button></form>@endif
    </div>
  @endforeach
  </div>
@endif
