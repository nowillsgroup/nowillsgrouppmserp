<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Employee;
use App\Models\GlJournal;
use App\Models\PaymentMode;
use App\Models\Room;
use App\Models\SupplierInvoice;
use App\Services\ActivityLogger;
use App\Services\AssetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->input('status', 'live');
        $q = Asset::with('category', 'room', 'custodian')->withSum('depLines', 'amount')
            ->when($status === 'live', fn ($w) => $w->where('status', '!=', 'disposed'))
            ->when(in_array($status, array_keys(Asset::STATUSES)), fn ($w) => $w->where('status', $status))
            ->when($request->filled('category'), fn ($w) => $w->where('asset_category_id', $request->integer('category')))
            ->when($request->filled('department'), fn ($w) => $w->where('department', $request->department))
            ->when($request->input('warranty') === 'expiring', fn ($w) => $w->whereDate('warranty_until', '>=', bdate()->toDateString())->whereDate('warranty_until', '<=', bdate()->copy()->addDays(60)->toDateString()))
            ->when($request->filled('q'), function ($w) use ($request) {
                $term = '%'.$request->q.'%';
                $w->where(fn ($x) => $x->where('name', 'like', $term)->orWhere('asset_no', 'like', $term)->orWhere('serial_no', 'like', $term)
                    ->orWhere('location', 'like', $term)->orWhereHas('room', fn ($r) => $r->where('room_no', $request->q)));
            });

        $live = Asset::withSum('depLines', 'amount')->where('status', '!=', 'disposed')->get();

        return view('assets.index', [
            'assets' => $q->orderBy('asset_no')->paginate(30)->withQueryString(),
            'categories' => AssetCategory::orderBy('name')->pluck('name', 'id'),
            'stats' => [
                'count' => $live->count(), 'cost' => $live->sum(fn ($a) => (float) $a->cost), 'acc' => $live->sum(fn ($a) => $a->accumulated()),
                'nbv' => $live->sum(fn ($a) => $a->nbv()), 'repair' => $live->where('status', 'in_repair')->count(),
                'warranty' => $live->filter(fn ($a) => $a->warrantyState() === 'expiring')->count(),
            ],
            'next' => AssetService::nextPeriod(),
        ]);
    }

    public function create(Request $request)
    {
        $a = new Asset(['acquired_via' => 'direct', 'purchase_date' => bdate(), 'asset_category_id' => $request->integer('category') ?: null]);
        if ($request->filled('bill') && ($bill = SupplierInvoice::find($request->integer('bill')))) {
            $a->fill(['acquired_via' => 'bill', 'supplier_invoice_id' => $bill->id, 'purchase_date' => $bill->doc_date, 'reference' => $bill->supplier_ref]);
        }

        return view('assets.form', $this->formData($a));
    }

    public function store(Request $request)
    {
        $a = AssetService::create($this->validated($request));

        return redirect()->route('assets.show', $a)->with('success', "Asset {$a->asset_no} added to the register.");
    }

    public function edit(Asset $asset)
    {
        abort_if($asset->isDisposed(), 404);

        return view('assets.form', $this->formData($asset));
    }

    public function update(Request $request, Asset $asset)
    {
        AssetService::update($asset, $this->validated($request, $asset));

        return redirect()->route('assets.show', $asset)->with('success', 'Asset updated.');
    }

    public function show(Asset $asset)
    {
        $asset->load('category', 'room', 'custodian', 'supplier', 'bill', 'paymentMode', 'disposalMode', 'creator');
        $opts = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG, 'outputBase64' => false,
            'svgAddXmlHeader' => false, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M, 'addQuietzone' => true]);

        return view('assets.show', [
            'a' => $asset,
            'lines' => $asset->hasMany(\App\Models\AssetDepLine::class)->with('run')->orderByDesc('id')->get(),
            'projection' => AssetService::projection($asset, 12),
            'tickets' => $asset->tickets()->with('employee', 'schedule')->latest()->limit(25)->get(),
            'schedules' => $asset->schedules()->orderBy('next_due')->get(),
            'journals' => GlJournal::whereIn('source_type', ['asset', 'asset_disp'])->where('source_key', (string) $asset->id)->orderBy('id')->get(),
            'qr' => (new \chillerlan\QRCode\QRCode($opts))->render(route('assets.show', $asset)),
            'modes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'online', 'gift_voucher'])->pluck('name', 'id'),
            'repairCost' => (float) $asset->tickets()->sum('cost'),
            'staff' => Employee::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function status(Request $request, Asset $asset)
    {
        $d = $request->validate(['status' => 'required|in:active,in_repair', 'note' => 'nullable|string|max:190']);
        AssetService::setStatus($asset, $d['status'], $d['note'] ?? null);

        return back()->with('success', "{$asset->asset_no} is now ".strtolower(Asset::STATUSES[$d['status']]).'.');
    }

    public function dispose(Request $request, Asset $asset)
    {
        $d = $request->validate(['disposed_date' => 'required|date', 'disposal_type' => ['required', Rule::in(array_keys(Asset::DISPOSALS))],
            'disposal_proceeds' => 'nullable|numeric|min:0|max:1000000000', 'disposal_mode_id' => 'nullable|integer|owned:payment_modes', 'disposal_note' => 'nullable|string|max:255']);
        $a = AssetService::dispose($asset, $d);
        $gain = round((float) $a->disposal_proceeds - (float) $a->disposal_nbv, 2);

        return back()->with('success', "{$a->asset_no} disposed – book value ".money($a->disposal_nbv).', '.($gain >= 0 ? 'gain ' : 'loss ').money(abs($gain)).'.');
    }

    public function destroy(Asset $asset)
    {
        if ($asset->isDisposed() || $asset->depLines()->exists() || \App\Models\AssetDepLine::where('asset_id', $asset->id)->exists()) {
            return back()->with('error', 'Only assets that were never depreciated can be deleted – dispose of it instead.');
        }
        $no = $asset->asset_no;
        $asset->schedules()->delete();
        $asset->delete();
        ActivityLogger::log('assets', 'delete', "Asset {$no} deleted (entered in error)");
        \App\Services\LedgerService::syncSafely();

        return redirect()->route('assets.index')->with('success', "Asset {$no} deleted.");
    }

    public function tags(Request $request)
    {
        $ids = array_filter(array_map('intval', (array) $request->input('ids', [])));
        $assets = Asset::with('room')->where('status', '!=', 'disposed')
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->when(! $ids && $request->filled('category'), fn ($q) => $q->where('asset_category_id', $request->integer('category')))
            ->orderBy('asset_no')->limit(200)->get();
        $opts = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG, 'outputBase64' => false,
            'svgAddXmlHeader' => false, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::L, 'addQuietzone' => false]);
        $qr = new \chillerlan\QRCode\QRCode($opts);

        return view('assets.tags', ['assets' => $assets, 'qr' => $assets->mapWithKeys(fn ($a) => [$a->id => $qr->render(route('assets.show', $a))])]);
    }

    public function export(Request $request)
    {
        $rows = Asset::with('category', 'room')->withSum('depLines', 'amount')->orderBy('asset_no')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Asset no', 'Name', 'Category', 'Location', 'Department', 'Serial', 'Purchased', 'Cost', 'Accumulated dep.', 'Book value', 'Method', 'Status']);
            foreach ($rows as $a) {
                fputcsv($out, [$a->asset_no, $a->name, $a->category?->name, $a->placeLabel(), $a->department, $a->serial_no, $a->purchase_date->toDateString(),
                    $a->cost, $a->isDisposed() ? round((float) $a->cost - (float) $a->disposal_nbv, 2) : $a->accumulated(), $a->isDisposed() ? 0 : $a->nbv(), strtoupper($a->method), Asset::STATUSES[$a->status]]);
            }
            fclose($out);
        }, 'asset-register-'.bdate()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    // ---------------------------------------------------------------- categories

    public function categories()
    {
        return view('assets.categories', ['categories' => AssetCategory::withCount(['assets' => fn ($q) => $q->where('status', '!=', 'disposed')])
            ->withSum(['assets' => fn ($q) => $q->where('status', '!=', 'disposed')], 'cost')->orderBy('name')->get()]);
    }

    public function saveCategory(Request $request, ?AssetCategory $acat = null)
    {
        $acat = $acat?->exists ? $acat : null;
        $d = $request->validate([
            'code' => ['required', 'string', 'max:12', Rule::unique('asset_categories')->where('hotel_id', \App\Support\Tenant::id())->ignore($acat?->id)],
            'name' => 'required|string|max:80', 'method' => 'required|in:slm,wdv',
            'life_months' => 'required_if:method,slm|nullable|integer|min:1|max:600', 'wdv_rate' => 'required_if:method,wdv|nullable|numeric|min:0.5|max:100',
            'residual_pct' => 'required|numeric|min:0|max:90', 'is_active' => 'boolean',
        ]);
        $d['code'] = strtoupper($d['code']);
        $d['life_months'] = $d['life_months'] ?? 60;
        $d['wdv_rate'] = $d['wdv_rate'] ?? 0;
        $d['is_active'] = $request->boolean('is_active', true);
        $acat ? $acat->update($d) : $acat = AssetCategory::create($d);
        ActivityLogger::log('assets', 'category', "Asset category {$acat->code} {$acat->name} saved", $acat);

        return back()->with('success', "Category {$acat->name} saved. New assets use these defaults; existing assets keep their own settings.");
    }

    // ---------------------------------------------------------------- helpers

    protected function validated(Request $request, ?Asset $a = null): array
    {
        $d = $request->validate([
            'name' => 'required|string|max:150', 'asset_category_id' => 'required|integer|owned:asset_categories',
            'room_id' => 'nullable|integer|owned:rooms', 'location' => 'nullable|string|max:120', 'department' => 'nullable|string|max:60',
            'make' => 'nullable|string|max:80', 'model' => 'nullable|string|max:80', 'serial_no' => 'nullable|string|max:80',
            'custodian_id' => 'nullable|integer|owned:employees', 'acquired_via' => ['required', Rule::in(array_keys(Asset::VIA))],
            'supplier_id' => 'nullable|integer|owned:suppliers', 'supplier_invoice_id' => 'nullable|integer|owned:supplier_invoices',
            'payment_mode_id' => 'nullable|integer|owned:payment_modes', 'reference' => 'nullable|string|max:60',
            'purchase_date' => 'required|date', 'dep_start' => 'nullable|date', 'cost' => 'required|numeric|min:0.01|max:1000000000',
            'residual_value' => 'nullable|numeric|min:0|max:1000000000', 'opening_acc_dep' => 'nullable|numeric|min:0|max:1000000000',
            'method' => 'required|in:slm,wdv', 'life_months' => 'nullable|integer|min:1|max:600', 'wdv_rate' => 'nullable|numeric|min:0|max:100',
            'warranty_until' => 'nullable|date', 'amc_vendor' => 'nullable|string|max:120', 'amc_until' => 'nullable|date', 'notes' => 'nullable|string|max:2000',
        ]);

        return $d;
    }

    protected function formData(Asset $a): array
    {
        $fa = AssetService::faHeads();
        $bills = SupplierInvoice::with('supplier')->where('type', 'invoice')->where('status', '!=', 'cancelled')
            ->whereHas('lines', fn ($q) => $q->whereIn('account_head_id', $fa))->orderByDesc('doc_date')->limit(100)->get();

        return [
            'a' => $a,
            'categories' => AssetCategory::where('is_active', true)->orWhere('id', $a->asset_category_id)->orderBy('name')->get(),
            'rooms' => Room::orderBy('room_no')->pluck('room_no', 'id'),
            'staff' => Employee::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'suppliers' => \App\Models\Supplier::orderBy('name')->pluck('name', 'id'),
            'bills' => $bills->mapWithKeys(fn ($b) => [$b->id => $b->doc_no.' · '.$b->supplier?->name.' · '.$b->doc_date->format('d M Y').' · '.money($b->total)]),
            'modes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'online', 'gift_voucher'])->pluck('name', 'id'),
            'locked' => $a->exists && $a->depLines()->exists(),
        ];
    }
}
