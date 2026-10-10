<div class="toast">
  @if(session('success'))<div class="alert alert-success"><x-icon name="check"/> <div>{{ session('success') }}</div></div>@endif
  @if(session('warning'))<div class="alert alert-warn"><x-icon name="alert"/> <div>{{ session('warning') }}</div></div>@endif
  @if(session('error'))<div class="alert alert-error"><x-icon name="alert"/> <div>{{ session('error') }}</div></div>@endif
</div>
@if($errors->any())
  <div class="alert alert-error"><x-icon name="alert"/><div><b>Please fix the following:</b><ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div></div>
@endif
