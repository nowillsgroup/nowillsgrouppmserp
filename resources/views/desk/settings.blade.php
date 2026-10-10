@extends('layouts.app')
@section('title', 'Desk Operations Settings')
@section('crumbs') Desk Operations / Settings @endsection
@section('content')
<form method="post" action="{{ route('desk.settings.save') }}" id="deskSettings">@csrf
<div class="card"><div class="card-head"><h3>Shifts & log book</h3></div><div class="card-body"><div class="form-grid">
  <x-field name="shift_morning" label="Morning shift starts" type="time" :value="$c['shift_morning']" required/>
  <x-field name="shift_evening" label="Evening shift starts" type="time" :value="$c['shift_evening']" required/>
  <x-field name="shift_night" label="Night shift starts" type="time" :value="$c['shift_night']" required/>
  <x-field name="log_unread_days" label="Unread window (days)" type="number" :value="$c['log_unread_days']" min="1" max="30" required hint="Entries by others this recent count as unread until acknowledged"/>
</div></div></div>
<div class="card"><div class="card-head"><h3>Wake-up calls</h3></div><div class="card-body"><div class="form-grid">
  <x-field name="wakeup_lead" label="Show as due (minutes before)" type="number" :value="$c['wakeup_lead']" min="0" max="120" required/>
  <x-field name="wakeup_grace" label="Escalate if not called after (min)" type="number" :value="$c['wakeup_grace']" min="1" max="120" required/>
  <x-field name="wakeup_retry" label="Retry every (min)" type="number" :value="$c['wakeup_retry']" min="1" max="60" required/>
  <x-field name="wakeup_max_attempts" label="Attempts before sending someone" type="number" :value="$c['wakeup_max_attempts']" min="1" max="10" required/>
  <x-field name="escalate_phone" label="Duty manager mobile (SMS alerts)" :value="$c['escalate_phone']" hint="Blank = log book + dashboard only"/>
</div></div></div>
<div class="card"><div class="card-head"><h3>Guest messages & parcels</h3></div><div class="card-body"><div class="form-grid">
  <x-field name="msg_notify" label="Notify in-house guests automatically" type="select" :options="['none' => 'No', 'sms' => 'By SMS', 'whatsapp' => 'By WhatsApp']" :value="$c['msg_notify']" :placeholder="false"/>
  <x-field name="msg_remind_minutes" label="Flag items waiting longer than (min)" type="number" :value="$c['msg_remind_minutes']" min="5" max="1440" required/>
  <label class="flex span-all" style="gap:.4rem;align-items:center"><input type="checkbox" name="msg_include_text" value="1" @checked($c['msg_include_text'] === '1')> Include the message text in the SMS <span class="small muted">(off: the guest is only told that a message is waiting – safer if the phone is shared)</span></label>
</div></div></div>
<div class="card"><div class="card-head"><h3>Lost & found</h3></div><div class="card-body"><div class="form-grid">
  <x-field name="lf_retention" label="Keep ordinary items (days)" type="number" :value="$c['lf_retention']" min="1" required/>
  <x-field name="lf_retention_valuable" label="Keep valuables (days)" type="number" :value="$c['lf_retention_valuable']" min="1" required/>
  <x-field name="lf_retention_perishable" label="Keep food / perishables (days)" type="number" :value="$c['lf_retention_perishable']" min="0" max="30" required/>
  <x-field name="lf_valuable_amount" label="Valuable from estimated value" type="number" :value="$c['lf_valuable_amount']" min="0" required hint="0 = only by category / tick box"/>
  <label class="flex span-all" style="gap:.4rem;align-items:center"><input type="checkbox" name="lf_notify_guest" value="1" @checked($c['lf_notify_guest'] === '1')> Tell the last guest in the room by SMS when something is found after check-out <span class="small muted">(many hotels prefer discretion – off by default)</span></label>
</div></div></div>
<button class="btn btn-primary" id="saveDeskSettings">Save settings</button>
</form>
@endsection
