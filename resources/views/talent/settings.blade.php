@extends('layouts.app')
@section('title', 'Talent Settings')
@section('crumbs') Talent Management / Settings @endsection
@section('content')
<form method="post" action="{{ route('talent.settings.save') }}" class="card" id="talSettings">@csrf<div class="card-head"><h3>Talent management settings</h3></div>
  <div class="card-body form-grid">
    <x-field name="probation_months" label="Maximum probation (months)" type="number" :value="$cfg['probation_months']" required/>
    <x-field name="probation_notice" label="Notice during probation (days)" type="number" :value="$cfg['probation_notice']" required/>
    <x-field name="offer_days" label="Offer open for (days)" type="number" :value="$cfg['offer_days']" required/>
    <x-field name="query_days" label="Query reply window (days)" type="number" :value="$cfg['query_days']" required/>
    <x-field name="warn_verbal" label="Verbal warning on file (months)" type="number" :value="$cfg['warn_verbal']" required/>
    <x-field name="warn_written" label="Written warning on file (months)" type="number" :value="$cfg['warn_written']" required/>
    <x-field name="warn_final" label="Final warning / suspension on file (months)" type="number" :value="$cfg['warn_final']" required/>
    <x-field name="goal_weight" label="Goals weight in the overall score (%)" type="number" :value="$cfg['goal_weight']" required hint="Competencies make up the rest"/>
    <x-field name="retention_months" label="Keep unsuccessful applicants (months)" type="number" :value="$cfg['retention_months']" required hint="Then anonymised at night audit and the CV deleted"/>
    <x-field name="statement_from" label="Track written statements for staff joining from" type="date" :value="$cfg['statement_from']" hint="s. 13 – set earlier to catch up on staff who joined before the module was set up"/>
    <x-field name="careers_intro" label="Careers page introduction" :value="$cfg['careers_intro']" class="span-all"/>
    <input type="hidden" name="careers" value="0"><label class="check span-all small"><input type="checkbox" name="careers" value="1" @checked($cfg['careers'])> Publish the public careers page ({{ route('careers.index', hotel()->code) }})</label>
  </div>
  <div class="card-foot"><button class="btn btn-primary" id="talSettingsSave">Save</button></div>
</form>
@endsection
