@extends('layouts.print')
@section('title', 'Prep labels – '.$l->item)
@section('nohead', true)
@section('content')
@php $A = \App\Services\AllergenService::class; @endphp
<style>
@media print { @page { size: A4; margin: 8mm } .print-page{box-shadow:none;padding:0} }
.lbl-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:4mm}
.lbl{border:1.5px dashed #64748b;border-radius:3mm;padding:3mm 3.5mm;font-size:11px;line-height:1.35;break-inside:avoid;min-height:34mm;border-left:6mm solid {{ ['Mon' => '#2563eb', 'Tue' => '#eab308', 'Wed' => '#dc2626', 'Thu' => '#16a34a', 'Fri' => '#f97316', 'Sat' => '#7c3aed', 'Sun' => '#0f172a'][$l->use_by->format('D')] }}}
.lbl b.n{font-size:14px;display:block;margin-bottom:1mm} .lbl .u{font-size:13px;font-weight:800}
</style>
<div class="lbl-grid" id="labelGrid">
@for($i = 0; $i < $l->qty; $i++)
  <div class="lbl"><b class="n">{{ $l->item }}</b>
    <div>Made: <b>{{ $l->prepared_at->format('D d/m H:i') }}</b></div>
    <div class="u">USE BY: {{ $l->use_by->format('D d/m H:i') }}</div>
    <div>{{ \App\Models\FsLabel::STORAGE[$l->storage] }}{{ $l->batch ? ' · batch '.$l->batch : '' }}</div>
    <div><b>Allergens:</b> {{ $l->allergens ? strtoupper($A::names($l->allergens)) : 'none declared' }}</div>
    <div class="tiny">By {{ $l->user?->name }}</div></div>
@endfor
</div>
<p class="tiny muted no-print" style="margin-top:6mm">The coloured edge is the day-dot for the use-by day (Mon blue, Tue yellow, Wed red, Thu green, Fri orange, Sat purple, Sun black).</p>
@endsection
