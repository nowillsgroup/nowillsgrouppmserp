@props(['text' => 'No records found.', 'icon' => 'box'])
<div class="empty"><x-icon :name="$icon"/><div>{{ $text }}</div>{{ $slot }}</div>
