<x-field name="name" label="Name" required :value="$i?->name" maxlength="120" class="span-2" placeholder="FDA Food Hygiene Permit"/>
<x-field name="category" label="Category" type="select" :options="\App\Models\ComplianceItem::CATEGORIES" :value="$i?->category" required :placeholder="false"/>
<x-field name="department" label="Department" type="select" :options="array_combine(\App\Models\ComplianceItem::DEPARTMENTS, \App\Models\ComplianceItem::DEPARTMENTS)" :value="$i?->department"/>
<x-field name="authority" label="Issuing authority / provider" :value="$i?->authority" maxlength="120"/>
<x-field name="reference_no" label="Licence / policy / certificate no." :value="$i?->reference_no" maxlength="80"/>
<x-field name="issued_on" label="Issued / last done" type="date" :value="$i?->issued_on?->toDateString()"/>
<x-field name="expires_on" label="Valid until / next due" type="date" :value="$i?->expires_on?->toDateString()" hint="Leave empty if it never expires"/>
<x-field name="frequency_months" label="Repeats every (months) – inspections" type="number" min="1" max="120" :value="$i?->frequency_months"/>
<x-field name="lead_days" label="Remind this many days before" type="number" min="1" max="365" :value="$i?->lead_days ?? \App\Services\ComplianceService::cfg()['lead_days']"/>
<x-field name="fee" label="Renewal fee / premium" type="number" step="0.01" min="0" :value="$i?->fee"/>
<x-field name="owner_id" label="Responsible person" type="select" :options="$owners" :value="$i?->owner_id"/>
<div class="field"><label>&nbsp;</label><label class="check"><input type="hidden" name="is_critical" value="0"><input type="checkbox" name="is_critical" value="1" @checked($i?->is_critical)> Critical – cannot operate without it</label></div>
<x-field name="notes" label="Notes" type="textarea" rows="2" :value="$i?->notes" class="span-2" maxlength="1000"/>
