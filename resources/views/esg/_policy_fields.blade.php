<div class="form-grid">
  <div class="field"><label>Code</label><input name="code" maxlength="20" value="{{ $x?->code }}" required></div>
  <div class="field"><label>Pillar</label><select name="pillar" required>@foreach(\App\Models\EsgPolicy::PILLARS as $k => $l)<option value="{{ $k }}" @selected($x?->pillar === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field span-all"><label>Title</label><input name="title" maxlength="150" value="{{ $x?->title }}" required></div>
  <div class="field"><label>Owner</label><select name="owner_user_id"><option value="">—</option>@foreach($users as $u)<option value="{{ $u->id }}" @selected($x?->owner_user_id == $u->id)>{{ $u->name }}</option>@endforeach</select></div>
  <div class="field"><label>Version</label><input name="version" maxlength="10" value="{{ $x?->version ?? '1.0' }}"></div>
  <div class="field"><label>Status</label><select name="status">@foreach(['draft' => 'Draft', 'active' => 'Approved & in force', 'retired' => 'Retired'] as $k => $l)<option value="{{ $k }}" @selected(($x?->status ?? 'draft') === $k)>{{ $l }}</option>@endforeach</select></div>
  <div class="field"><label>Review every (months)</label><input type="number" name="review_months" min="1" max="60" value="{{ $x?->review_months ?? 12 }}" required></div>
  <div class="field"><label>Approved on</label><input type="date" name="approved_on" value="{{ $x?->approved_on?->toDateString() }}" max="{{ bdate()->toDateString() }}"></div>
  <div class="field"><label>Approved by</label><input name="approved_by_name" maxlength="120" value="{{ $x?->approved_by_name }}" placeholder="e.g. General Manager"></div>
  <div class="field span-all"><label>Summary</label><input name="summary" maxlength="500" value="{{ $x?->summary }}"></div>
  <div class="field span-all"><label>Policy document (PDF / Word, max 5 MB){{ $x?->file_name ? ' – replaces '.$x->file_name : '' }}</label><input type="file" name="file" accept=".pdf,.doc,.docx"></div>
</div>
