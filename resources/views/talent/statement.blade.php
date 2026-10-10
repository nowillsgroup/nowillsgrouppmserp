@extends('layouts.print')
@section('title', 'Statement of particulars – '.$e->name)
@section('doctitle', 'STATEMENT OF PARTICULARS OF EMPLOYMENT')
@section('docmeta')<div class="small">Labour Act 2003 (Act 651), s. 13 &amp; Schedule I</div><div class="small"><b>{{ $e->emp_code }}</b></div>@endsection
@section('content')
<table class="tal-terms" id="talStatement">
  <tr><th>1. Parties</th><td>{{ $currentHotel->name }} (employer) and <b>{{ $e->name }}</b> (worker){{ $e->address ? ', '.$e->address : '' }}</td></tr>
  <tr><th>2. Date of employment</th><td>{{ $e->joining_date?->format('d F Y') }}</td></tr>
  <tr><th>3. Job title &amp; place of work</th><td>{{ $e->designation }}, {{ $e->department?->name }} – {{ $currentHotel->address }}{{ $currentHotel->city ? ', '.$currentHotel->city : '' }}</td></tr>
  <tr><th>4. Type of employment</th><td>{{ \App\Models\TalentVacancy::TYPES[$e->employment_type] ?? $e->employment_type }}{{ $e->contract_end ? ', ending '.$e->contract_end->format('d F Y') : '' }}</td></tr>
  <tr><th>5. Remuneration</th><td>{{ money($e->salary) }} per month, paid monthly by {{ $e->bank_account ? 'bank / mobile-money transfer' : 'the method agreed' }}; PAYE income tax and the worker's 5.5 % SSNIT contribution are deducted; the employer contributes 13 % to SSNIT.</td></tr>
  <tr><th>6. Hours of work</th><td>Normally 8 hours a day and 40 hours a week on a shift roster (s. 33); overtime is paid as agreed.</td></tr>
  <tr><th>7. Annual leave</th><td>@foreach($leave as $t){{ $t->name }}: {{ rtrim(rtrim(number_format($t->days_per_year, 1), '0'), '.') }} days{{ $t->is_paid ? '' : ' (unpaid)' }}@if(! $loop->last); @endif @endforeach – at least 15 working days' paid leave a year (s. 20). Maternity leave of at least 12 weeks (s. 57).</td></tr>
  <tr><th>8. Probation</th><td>{{ $e->probation_end ? 'Until '.$e->probation_end->format('d F Y').'; during probation either party may end the employment with '.$cfg['probation_notice'].' days\' notice.' : 'None' }}</td></tr>
  <tr><th>9. Notice</th><td>Two weeks' written notice, or one month after three years' service (a week for weekly-paid workers), or pay in lieu (s. 17). Currently: {{ $notice[1] }}.</td></tr>
  <tr><th>10. Discipline</th><td>The hotel's disciplinary rules apply: a written query with at least {{ $cfg['query_days'] }} days to reply, a hearing with a representative for serious matters, and warnings that expire (verbal {{ $cfg['warn_verbal'] }}, written {{ $cfg['warn_written'] }}, final {{ $cfg['warn_final'] }} months). Gross misconduct may lead to summary dismissal.</td></tr>
  <tr><th>11. Grievances</th><td>Raise any grievance with your line manager ({{ $e->manager?->name ?? 'your head of department' }}) or HR; it will be heard in confidence. You may also complain to the National Labour Commission (s. 64).</td></tr>
  <tr><th>12. Social security</th><td>SSNIT number: {{ $e->social_security_no ?? '________________' }} · Ghana Card / TIN: {{ $e->tax_id ?? '________________' }}</td></tr>
</table>
<div class="tal-sig"><div>For the employer<br><br>Name / date</div><div>Worker: {{ $e->name }}<br><br>Signature / date</div></div>
@endsection
