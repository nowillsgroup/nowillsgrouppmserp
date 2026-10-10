@extends('layouts.print')
@section('title', 'Licences & compliance register')
@section('doctitle', 'LICENCES & STATUTORY COMPLIANCE REGISTER')
@section('docmeta')<div class="small">As of {{ $asOf->format('d M Y') }}</div>@endsection
@section('content')
<table class="table" style="width:100%;border-collapse:collapse;font-size:12px;margin-top:1rem" id="printRegister">
  <thead><tr style="background:#1e293b;color:#fff"><th align="left" style="padding:5px">#</th><th align="left">Item</th><th align="left">Category</th><th align="left">Authority</th><th align="left">Reference</th><th align="left">Valid until</th><th align="right">Days</th><th align="left">Status</th><th align="left">Responsible</th></tr></thead>
  <tbody>
  @foreach($rows as $r)
    @php $i = $r['item']; $col = ['expired' => '#b91c1c', 'due' => '#b45309', 'renewing' => '#1d4ed8'][$r['state']] ?? '#0f172a'; @endphp
    <tr style="border-bottom:1px solid #e2e8f0"><td style="padding:5px">{{ $loop->iteration }}</td><td><b>{{ $i->name }}</b>@if($i->is_critical) *@endif</td><td>{{ $i->categoryLabel() }}</td><td>{{ $i->authority }}</td>
      <td style="font-family:monospace">{{ $i->reference_no }}</td><td>{{ $i->expires_on?->format('d M Y') ?? 'No expiry' }}</td><td align="right" style="color:{{ $col }}">{{ $r['days'] }}</td>
      <td style="color:{{ $col }};font-weight:600">{{ \App\Models\ComplianceItem::STATES[$r['state']][0] }}</td><td>{{ $i->owner?->name }}</td></tr>
  @endforeach
  </tbody>
</table>
<p class="small" style="margin-top:.8rem">* critical – the hotel cannot lawfully operate without it. {{ $rows->where('state', 'expired')->count() }} expired · {{ $rows->where('state', 'due')->count() }} due for renewal · {{ $rows->count() }} items.</p>
@endsection
