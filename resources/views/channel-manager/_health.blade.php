@php $h = $conn->health(); @endphp
<span class="badge badge-{{ ['ok' => 'green', 'failing' => 'red', 'paused' => 'slate', 'pending' => 'blue'][$h] }}" title="{{ $conn->last_push_status }}">{{ ['ok' => 'Connected', 'failing' => 'Failing', 'paused' => 'Paused', 'pending' => 'Awaiting first sync'][$h] }}</span>
