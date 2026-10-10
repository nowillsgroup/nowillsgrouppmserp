@php
  $st = $cell['state']; $L = \App\Services\LearningService::STATES;
  $txt = match ($st) {
    'current' => $cell['expires'] ? $cell['expires']->format('M y') : '✓',
    'expiring' => $cell['expires']->format('d M'),
    'expired' => 'exp '.$cell['expires']->format('M y'),
    'due' => 'by '.($cell['due']?->format('d M') ?? '—'),
    default => 'overdue',
  };
  $tip = $L[$st][0].($cell['expires'] ? ' · expires '.$cell['expires']->format('d M Y') : '').($cell['due'] ? ' · due '.$cell['due']->format('d M Y') : '').($cell['booked'] ? ' · booked '.$cell['booked']->format('d M') : '').(($cell['record']?->status ?? null) === 'exempt' ? ' · exempt' : '');
@endphp
<span class="mx-cell mx-{{ $st }}" title="{{ $tip }}" data-state="{{ $st }}">{{ ($cell['record']?->status ?? null) === 'exempt' ? 'exempt' : $txt }}@if($cell['booked'])<x-icon name="calendar"/>@endif</span>
