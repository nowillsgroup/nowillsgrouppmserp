{{-- capex request create / edit modal; $p = CapexProject or null --}}
@php $CP = \App\Models\CapexProject::class; $U = \App\Services\UsaliService::class; @endphp
<div class="modal" id="projectModal"><div class="modal-box" style="max-width:42rem"><form method="post" action="{{ $p ? route('owner.capex.update', $p) : route('owner.capex.store') }}" id="projectForm">@csrf @if($p) @method('PUT') @endif
  <div class="modal-head"><h3 data-title>{{ $p ? 'Edit '.$p->number : 'New capex request' }}</h3><button type="button" class="btn btn-ghost btn-icon" data-close><x-icon name="x"/></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field span-all"><label for="pTitle">Project</label><input name="title" id="pTitle" maxlength="150" value="{{ old('title', $p?->title) }}" required placeholder="e.g. Replace guest-room air conditioners, floor 2"></div>
    <div class="field"><label for="pCat">Category</label><select name="category" id="pCat">@foreach($CP::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(old('category', $p?->category ?? 'ffe') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label for="pFund">Funded by</label><select name="funding" id="pFund">@foreach($CP::FUNDING as $k => $l)<option value="{{ $k }}" @selected(old('funding', $p?->funding ?? 'reserve') === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label for="pBudget">Budget (GH₵)</label><input type="number" step="0.01" min="1" name="budget" id="pBudget" value="{{ old('budget', $p ? (float) $p->budget : '') }}" required></div>
    <div class="field"><label for="pDept">Department</label><select name="usali_dept" id="pDept"><option value="">—</option>@foreach($U::payrollDepts() as $k => $l)<option value="{{ $k }}" @selected(old('usali_dept', $p?->usali_dept) === $k)>{{ $l }}</option>@endforeach</select></div>
    <div class="field"><label for="pNeed">Needed by</label><input type="date" name="needed_by" id="pNeed" value="{{ old('needed_by', $p?->needed_by?->toDateString()) }}"></div>
    <div class="field span-all"><label for="pJust">Justification for the owner</label><textarea name="justification" id="pJust" rows="4" maxlength="4000" placeholder="Why it is needed, what happens if deferred, quotes obtained, expected savings / revenue">{{ old('justification', $p?->justification) }}</textarea></div>
    @unless($p)<div class="field span-all"><label class="check"><input type="checkbox" name="submit" value="1" id="pSubmit"> <span>Send to the owner for approval now</span></label></div>@endunless
  </div></div>
  <div class="modal-foot"><button type="button" class="btn" data-close>Close</button><button class="btn btn-primary" id="projectSave">Save</button></div>
</form></div></div>
