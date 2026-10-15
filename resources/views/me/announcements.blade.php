@extends('layouts.app')
@section('title', 'Announcements')
@section('crumbs') {{ $e->name }} · {{ $e->emp_code }} @endsection

<div class="card">
  <div class="card-head"><h3>Staff announcements</h3></div>
  <div class="card-body">
    @if ($items->isEmpty())
      <x-empty text="No announcements right now." icon="bell"/>
    @else
      @foreach ($items as $a)
      <div style="border-left:3px solid {{ $a->is_pinned ? '#f59e0b' : '#cbd5e1' }}; padding:6px 12px; margin:10px 0;">
        <div class="flex gap">
          <span class="bold">{{ $a->title }}</span>
          @if ($a->is_pinned)<span class="badge badge-amber">Pinned</span>@endif
          <span class="muted small">{{ $a->publish_at->format('j M Y') }}</span>
        </div>
        <div class="small" style="white-space:pre-wrap">{{ $a->body }}</div>
      </div>
      @endforeach
    @endif
  </div>
</div>
