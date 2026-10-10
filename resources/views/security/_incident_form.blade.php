@php $x = $i ?? null; @endphp
<x-field name="type" label="Type" type="select" required :options="\App\Models\SecurityIncident::TYPES" :value="$x?->type"/>
<x-field name="severity" label="Severity" type="select" :placeholder="false" :options="collect(\App\Models\SecurityIncident::SEVERITIES)->map(fn ($s) => $s[0])->all()" :value="$x?->severity ?? 'medium'"/>
<x-field name="occurred_at" label="When" type="datetime-local" required :value="($x?->occurred_at ?? now())->format('Y-m-d\TH:i')" :max="now()->addMinutes(5)->format('Y-m-d\TH:i')"/>
<x-field name="location" label="Where" required maxlength="150" :value="$x?->location" placeholder="Lobby / 2nd-floor corridor / car park"/>
<x-field name="room_id" label="Room (if any)" type="select" :options="$rooms" :value="$x?->room_id"/>
<x-field name="reservation_room_id" label="Guest involved (in-house / just left)" type="select" :options="$stays" :value="$x?->reservation_room_id"/>
<x-field name="title" label="Short title" required maxlength="200" class="span-2" :value="$x?->title" placeholder="Laptop reported missing from room"/>
<x-field name="description" label="What happened" type="textarea" required rows="3" class="span-2" :value="$x?->description"/>
<x-field name="persons_involved" label="Persons involved / witnesses" type="textarea" rows="2" :value="$x?->persons_involved"/>
<x-field name="action_taken" label="Immediate action taken" type="textarea" rows="2" :value="$x?->action_taken"/>
<x-field name="loss_amount" label="Estimated loss / damage" type="number" step="0.01" min="0" :value="$x?->loss_amount"/>
<x-field name="assigned_to" label="Investigating officer" type="select" :options="$users" :value="$x?->assigned_to"/>
<label class="check"><input type="hidden" name="injuries" value="0"><input type="checkbox" name="injuries" value="1" @checked(old('injuries', $x?->injuries))> Someone was injured</label>
<label class="check"><input type="hidden" name="police_informed" value="0"><input type="checkbox" name="police_informed" value="1" @checked(old('police_informed', $x?->police_informed))> Police informed</label>
<x-field name="police_station" label="Police station" maxlength="120" :value="$x?->police_station"/>
<x-field name="police_ref" label="FIR / complaint no." maxlength="60" :value="$x?->police_ref"/>
