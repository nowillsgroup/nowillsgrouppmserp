@extends('layouts.app')
@section('title', 'Import & Export Data')
@section('crumbs') Administration / Import &amp; Export Data @endsection
@section('content')
<div class="grid g-2">
  <div class="card"><div class="card-head"><h3><x-icon name="upload"/> Import data from CSV</h3></div><div class="card-body">
    <p class="muted">Load supported master records, reservations and cutover balances into this property. Choose a dataset template, fill it with your source data, then upload the CSV. Every file is checked in a dry-run before you can apply it.</p>
    <form method="post" action="{{ route('migration.store') }}" enctype="multipart/form-data" class="form-grid">
      @csrf
      <x-field name="dataset" label="Module / dataset" type="select" :options="array_combine($order, array_map(fn ($k) => $k.' — '.$importers[$k]->label(), $order))" :placeholder="false" :value="old('dataset', 'companies')"/>
      <x-field name="source_name" label="Source system / file date" value="{{ old('source_name') }}" placeholder="e.g. Legacy PMS export · 2026-10"/>
      <div class="field span-2"><label for="f_file">CSV file (max 20 MB)</label><input type="file" name="file" id="f_file" accept=".csv,.txt,.tsv,text/csv" required></div>
      <div class="span-2"><button class="btn btn-primary"><x-icon name="upload"/> Upload &amp; validate</button></div>
    </form>
    <p class="tiny muted mt-1">Imports are property-scoped, limited to 5,000 rows per file, and never execute uploaded SQL. For configured master adapters, relation IDs must belong to this property and select values must use the documented keys. Review every row outcome before selecting Apply.</p>
  </div></div>

  <div class="card"><div class="card-head"><h3><x-icon name="download"/> Export all property data</h3></div><div class="card-body">
    <p class="muted">Download a complete, property-scoped SQL export containing the current property's supported module records and transactions. The file is suitable for database backup / restore into an existing Nowills PMS schema.</p>
    <form method="post" action="{{ route('settings.backup.download') }}" class="flex gap-1 wrap" data-no-lock="1">
      @csrf
      <button name="type" value="hotel" class="btn btn-primary"><x-icon name="download"/> Export this property's data</button>
      <a class="btn" href="{{ route('settings.backup') }}">Backup history &amp; full database options</a>
    </form>
    <p class="tiny muted mt-1">The export includes tenant-scoped rows across modules; secret/session tables are excluded. Import uploaded business data with the validated CSV workflow, not by uploading raw SQL.</p>
  </div></div>
</div>

<div class="card flush mt-2"><div class="card-head"><div><h3>Module templates &amp; safe CSV exports</h3><p class="small muted">Download a CSV template for each permitted import adapter. Configured masters and the enquiry-only banquet adapter also have property-scoped CSV exports; use real source data and property-local related-record ids.</p></div><a class="btn btn-sm" href="{{ route('settings.backup') }}"><x-icon name="download" size="sm"/> Export / backup</a></div>
  <div class="card-body table-wrap"><table class="table">
    <thead><tr><th>Module</th><th>Dataset</th><th>Import mode</th><th>Template</th><th>Export</th></tr></thead>
    <tbody>
    @foreach($order as $k)
      @php
        $templateImporter = $importers[$k];
        $isConfiguredMaster = str_starts_with($k, 'masters__');
        $isMenuItemTransfer = $k === 'menu_items';
        $isBanquetEnquiryTransfer = $k === 'banquet_enquiries';
        $moduleLabel = $isConfiguredMaster ? ucwords(str_replace('-', ' ', substr($k, 9))) : ucwords(str_replace('_', ' ', $k));
        if ($isBanquetEnquiryTransfer) $moduleLabel = 'Banquet events & enquiries';
      @endphp
      <tr>
        <td>{{ $moduleLabel }}</td>
        <td>{{ $templateImporter->label() }}</td>
        <td>@if($isBanquetEnquiryTransfer)<span class="badge badge-blue">Enquiry-only import</span><div class="tiny muted">No holds or financial postings</div>@elseif($templateImporter->supportsApply())<span class="badge badge-green">Validated import</span>@else<span class="badge badge-amber">Preflight only</span>@endif</td>
        <td><a class="btn btn-sm btn-ghost" href="{{ route('migration.template', $k) }}"><x-icon name="download" size="sm"/> Download CSV template</a></td>
        <td>@if($isConfiguredMaster || $isMenuItemTransfer || $isBanquetEnquiryTransfer)<a class="btn btn-sm btn-ghost" href="{{ route('migration.export', $k) }}"><x-icon name="download" size="sm"/> Export records</a>@if($isBanquetEnquiryTransfer)<div class="tiny muted">Enquiries only</div>@endif @else<span class="tiny muted">Full export available above</span>@endif</td>
      </tr>
    @endforeach
    </tbody>
  </table></div>
</div>

@if(in_array('banquet_enquiries', $order, true))
<div class="card mt-2"><div class="card-head"><div><h3>Banquet events &amp; enquiries</h3><p class="small muted">Move enquiry-stage records without bypassing the booking, venue-hold or cashier workflows.</p></div><span class="badge badge-blue">Non-binding intake only</span></div>
  <div class="card-body grid g-2">
    <div>
      <h4>What the CSV can carry</h4>
      <p class="small muted">Contact, company, event type/title, requirements and staff notes, plus 1–30 proposed functions and their optional service extras. <code>functions_json</code> uses keys <code>name</code>, <code>banquet_hall_id</code>, <code>date</code>, <code>start_time</code>, <code>end_time</code>, <code>setup_style</code>, <code>expected_pax</code>, optional <code>guaranteed_pax</code>, <code>banquet_package_id</code>, <code>rate_per_pax</code>, <code>hall_rate</code>, <code>notes</code> and <code>items</code>. Each extra may contain <code>banquet_service_id</code>, <code>description</code>, <code>qty</code>, <code>unit_price</code> and <code>tax_rate</code>.</p>
      <p class="small muted">Use active venue/package/service and company ids from this property. Any rates in the file are estimates in the property currency, not bills or postings.</p>
    </div>
    <div>
      <h4>What it will not do</h4>
      <p class="small muted">Every imported event is forced to <code>enquiry</code>. CSV cannot create tentative/definite holds, take deposits, open folios, bill events or post to the ledger. Apply is web-only and requires <code>banquet.manage</code>, a clean dry-run and typed confirmation.</p>
      <p class="small muted">Export contains only unheld, unbilled enquiries without a folio. Export ids are property-specific. Existing enquiries with linked organiser/BEO, visitor, payment or sales activity, actual attendance or locked guarantees cannot be overwritten.</p>
    </div>
  </div>
</div>
@endif

<div class="grid g-2 mt-2">
  <div class="card flush"><div class="card-head"><h3>Safe import sequence</h3></div><div class="card-body small muted">
    <ol class="ml-1">
      <li>Import prerequisite master records first (for example floors and room types before rooms or rate plans, stores and item categories before stock, and outlets before their setup), then use the dedicated cutover datasets that depend on them.</li>
      <li>Each row needs a stable source <b>external_id</b>. Legacy dataset links use property-scoped maps; configured-master relation fields use existing ids in this property.</li>
      <li>Dry-run reports rows to create/update, unchanged rows, and failures with reasons. Nothing operational is written until Apply.</li>
      <li>Apply is idempotent where the dataset supports it; reservation history is never duplicated.</li>
      <li>Banquet CSV transfer is restricted to enquiry-stage records. Its <code>functions_json</code> carries proposed functions and extras using target-property ids; rates are estimates in the property currency only. It cannot create tentative/definite venue holds, deposits, folios, billing or ledger entries; later booking and payment steps stay in the Banquet workflow.</li>
      <li>Every batch retains the uploaded CSV, row outcomes and a reconciliation report.</li>
    </ol>
    <p class="alert alert-info mt-1">Opening AP/AR and stock follow cutover rules. Opening GL, bank mappings and inventory allergens are preflight-only; paid supplier and historical stock files are archives, not operational postings. Do not use this tool to replay live payments, refunds, POS settlements or journals.</p>
  </div></div>
  <div class="card flush"><div class="card-head"><h3>What imports are enabled</h3></div><div class="card-body small muted">
    <p>Templates cover the existing validated migration datasets plus selected configuration-backed master records with safe fields and natural keys. Menu-category and restaurant-table setup is included. Menu-item transfers include catalogue fields and variants only; photos, recipes, modifier groups and allergen declarations remain on their module workflows, and new items stay unavailable until staff complete setup. Custom chart-of-accounts transfers exclude system accounts and require GL permission. Rate-plan, tax, chart-of-accounts and menu-item batches show submitted values, require a clean dry-run and typed confirmation. Configured master exports include only approved fields; relational ids refer to records inside this property.</p>
    <p>Only listed adapters are enabled for direct import. Financial/POS transactions, stock movements, payments, journals and other posted records must use their purpose-built cutover or live workflow; the system does not write arbitrary spreadsheet columns or uploaded SQL into business tables.</p>
    <a href="#migrationBatches" class="small">Review import batches and validation reports →</a>
  </div></div>
</div>

<div class="card flush mt-2" id="migrationBatches"><div class="card-head"><h3>Batches</h3></div><div class="card-body table-wrap"><table class="table">
  <thead><tr><th>Batch</th><th>Dataset</th><th>Source</th><th class="num">Rows</th><th class="num">Created</th><th class="num">Updated</th><th class="num">Skipped</th><th class="num">Failed</th><th>Status</th><th>Uploaded</th></tr></thead>
  <tbody>
  @forelse($batches as $b)
    <tr>
      <td><a href="{{ route('migration.show', $b) }}">{{ $b->code }}</a></td>
      <td>{{ $b->dataset }}</td>
      <td class="small">{{ $b->source_name }}</td>
      <td class="num">{{ number_format($b->rows_total) }}</td>
      <td class="num">{{ number_format($b->rows_created) }}</td>
      <td class="num">{{ number_format($b->rows_updated) }}</td>
      <td class="num">{{ number_format($b->rows_skipped) }}</td>
      <td class="num">{!! $b->rows_failed ? '<b class="bad">'.$b->rows_failed.'</b>' : '0' !!}</td>
      <td><span class="badge badge-{{ $b->status === 'imported' ? 'green' : ($b->status === 'failed' ? 'red' : ($b->status === 'validated' ? 'blue' : 'slate')) }}">{{ $b->status }}</span></td>
      <td class="small">{{ $b->created_at->format('d M Y H:i') }}</td>
    </tr>
  @empty
    <tr><td colspan="10"><x-empty text="No migration batches yet." icon="upload"/></td></tr>
  @endforelse
  </tbody>
</table>
{{ $batches->links() }}
</div></div>
@endsection
