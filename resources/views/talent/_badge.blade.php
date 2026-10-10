@php $tones = ['draft' => 'slate', 'pending' => 'amber', 'open' => 'green', 'on_hold' => 'slate', 'filled' => 'blue', 'cancelled' => 'slate',
  'applied' => 'slate', 'screening' => 'blue', 'interview' => 'purple', 'offer' => 'amber', 'hired' => 'green', 'rejected' => 'red', 'withdrawn' => 'slate',
  'issued' => 'blue', 'accepted' => 'green', 'declined' => 'red', 'self' => 'slate', 'review' => 'amber', 'hr' => 'purple', 'signed' => 'green',
  'query' => 'amber', 'hearing' => 'purple', 'decided' => 'blue', 'investigating' => 'amber', 'resolved' => 'green', 'closed' => 'slate',
  'notice' => 'amber', 'completed' => 'slate', 'scheduled' => 'blue', 'done' => 'green', 'no_show' => 'red']; @endphp
<span class="badge badge-{{ $tones[$s] ?? 'slate' }}">{{ $l ?? ucfirst(str_replace('_', ' ', $s)) }}</span>
