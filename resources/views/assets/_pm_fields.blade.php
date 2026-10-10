{{-- shared PM schedule fields: $s (schedule or null), $withTarget, $staff, [$assets, $rooms] --}}
<x-field name="title" label="Task" required :value="$s?->title" class="span-all" placeholder="e.g. Clean AC filters & check gas pressure"/>
@if($withTarget)
<x-field name="asset_id" label="Asset" type="select" :options="$assets" :value="$s?->asset_id" class="span-all"/>
<x-field name="room_id" label="Or room" type="select" :options="$rooms" :value="$s?->room_id"/>
<x-field name="location" label="Or area" :value="$s?->location" placeholder="e.g. Pool plant room"/>
@endif
<div class="field"><label>Repeat every <span class="req">*</span></label><div class="flex gap-1"><input type="number" name="every" min="1" max="365" value="{{ old('every', $s?->every ?? 1) }}" style="max-width:80px" required>
  <select name="every_unit">@foreach(\App\Models\PmSchedule::UNITS as $k => $v)<option value="{{ $k }}" @selected(old('every_unit', $s?->every_unit ?? 'month') === $k)>{{ $v }}</option>@endforeach</select></div></div>
<x-field name="next_due" label="Next due" type="date" required :value="$s?->next_due?->toDateString() ?? bdate()->copy()->addDays(7)->toDateString()"/>
<x-field name="lead_days" label="Raise ticket (days before)" type="number" min="0" max="90" :value="$s?->lead_days ?? 3" required/>
<x-field name="category" label="Trade" type="select" :options="\App\Http\Controllers\PmScheduleController::CATEGORIES" :value="$s?->category ?? 'general'" :placeholder="false"/>
<x-field name="priority" label="Priority" type="select" :options="['normal' => 'Normal', 'low' => 'Low', 'high' => 'High', 'urgent' => 'Urgent']" :value="$s?->priority ?? 'normal'" :placeholder="false"/>
<x-field name="employee_id" label="Technician" type="select" :options="$staff" :value="$s?->employee_id"/>
<x-field name="vendor" label="Or service vendor (AMC)" :value="$s?->vendor"/>
<x-field name="checklist" label="Checklist (one step per line)" type="textarea" :value="$s?->checklist" class="span-all" rows="4"/>
