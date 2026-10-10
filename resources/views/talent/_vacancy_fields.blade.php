@php $v = $v ?? null; @endphp
<x-field name="title" label="Job title" :value="$v?->title" required/>
<x-field name="department_id" label="Department" type="select" :options="$departments->pluck('name', 'id')" :value="$v?->department_id" required/>
<x-field name="headcount" label="Number of posts" type="number" min="1" max="20" :value="$v?->headcount ?? 1" required/>
<x-field name="employment_type" label="Type of employment" type="select" :options="\App\Models\TalentVacancy::TYPES" :value="$v?->employment_type ?? 'permanent'" :placeholder="false"/>
<x-field name="contract_months" label="Contract length (months)" type="number" min="1" max="60" :value="$v?->contract_months" hint="Fixed-term posts only"/>
<x-field name="closing_on" label="Closing date" type="date" :value="$v?->closing_on?->toDateString()"/>
<x-field name="salary_min" label="Salary from (GH₵ / month)" type="number" step="0.01" :min="$mw" :value="$v?->salary_min" required :hint="'Minimum wage '.money($mw)"/>
<x-field name="salary_max" label="Salary to (GH₵ / month)" type="number" step="0.01" :value="$v?->salary_max" required/>
<x-field name="reason" label="Reason" type="select" :options="\App\Models\TalentVacancy::REASONS" :value="$v?->reason ?? 'new'" :placeholder="false"/>
<x-field name="replaces_employee_id" label="Replacing" type="select" :options="$employees->pluck('name', 'id')" :value="$v?->replaces_employee_id" hint="Replacement posts only" class="span-all"/>
<x-field name="justification" label="Business justification" type="textarea" :value="$v?->justification" class="span-all" placeholder="Why the post is needed – workload, occupancy, a resignation…"/>
<x-field name="description" label="Job description (shown on the careers page)" type="textarea" :value="$v?->description" class="span-all"/>
<x-field name="requirements" label="Requirements" type="textarea" :value="$v?->requirements" class="span-all" placeholder="Qualifications, experience, languages…"/>
<input type="hidden" name="is_public" value="0"><label class="check span-all small"><input type="checkbox" name="is_public" value="1" @checked($v?->is_public ?? true)> Advertise on the public careers page once approved</label>
