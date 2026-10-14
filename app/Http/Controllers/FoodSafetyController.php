<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\FsCertificate;
use App\Models\FsChecklist;
use App\Models\FsChecklistRun;
use App\Models\FsEquipment;
use App\Models\FsLabel;
use App\Models\FsTempLog;
use App\Models\FsWaste;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\Outlet;
use App\Models\Store;
use App\Models\Supplier;
use App\Services\AllergenService;
use App\Services\FoodSafetyService as FS;
use App\Services\FoodWasteService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** v5.0 Food Safety & HACCP. */
class FoodSafetyController extends Controller
{
    protected function attempt(callable $fn, string|callable $ok, ?string $route = null)
    {
        try {
            $r = $fn();
            $msg = is_callable($ok) ? $ok($r) : $ok;

            return ($route ? redirect($route) : back())->with('success', $msg);
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ dashboard

    public function index()
    {
        $from = bdate()->copy()->startOfMonth()->toDateString();

        return view('foodsafety.index', [
            'c7' => FS::compliance(7), 'c30' => FS::compliance(30), 'att' => FS::attention(), 'board' => FS::board(), 'lists' => FS::checklistStatus(),
            'certs' => FS::certProblems(), 'allergens' => AllergenService::attention(), 'waste' => FoodWasteService::summary($from, bdate()->toDateString()),
            'breaches' => FsTempLog::with('equipment', 'user')->where('in_range', false)->whereNull('reviewed_at')->latest('recorded_at')->take(8)->get(),
            'cooling' => FsTempLog::with('user')->where('kind', 'cooling')->whereNull('reading2_c')->where('in_range', true)->where('recorded_at', '>=', now()->subDays(2))->orderBy('recorded_at')->get(),
            'labels' => FsLabel::where('use_by', '>=', now())->where('use_by', '<=', now()->addDay())->orderBy('use_by')->take(8)->get(),
        ]);
    }

    // ------------------------------------------------------------------ temperatures

    public function temps(Request $request)
    {
        $day = $request->filled('date') ? Carbon::parse($request->date) : now();
        $kind = $request->input('kind');
        $logs = FsTempLog::with('equipment', 'user', 'reviewer', 'supplier')->whereBetween('recorded_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->when($kind, fn ($q) => $q->where('kind', $kind))->orderByDesc('recorded_at')->get();

        return view('foodsafety.temps', ['day' => $day, 'kind' => $kind, 'board' => FS::board($day), 'logs' => $logs,
            'equipment' => FsEquipment::with('outlet')->orderByDesc('is_active')->orderBy('name')->get(),
            'suppliers' => Supplier::orderBy('name')->pluck('name', 'id'), 'outlets' => Outlet::orderBy('name')->pluck('name', 'id'),
            'pending' => FsTempLog::with('equipment', 'user')->where('in_range', false)->whereNull('reviewed_at')->orderByDesc('recorded_at')->get(),
            'coolingOpen' => FsTempLog::with('user')->where('kind', 'cooling')->whereNull('reading2_c')->where('in_range', true)->where('recorded_at', '>=', now()->subDays(2))->orderBy('recorded_at')->get(),
            'limits' => collect(FS::SETTINGS)->map(fn ($s, $k) => FS::num($k))]);
    }

    public function record(Request $request)
    {
        $d = $request->validate([
            'kind' => ['required', Rule::in(array_keys(FsTempLog::KINDS))], 'fs_equipment_id' => 'nullable|required_if:kind,equipment|integer|owned:fs_equipment',
            'item' => 'nullable|string|max:120', 'category' => 'nullable|in:chilled,frozen,hot', 'supplier_id' => 'nullable|integer|owned:suppliers',
            'reading_c' => 'required|numeric|between:-60,150', 'corrective_action' => 'nullable|string|max:255', 'recorded_at' => 'nullable|date',
        ]);

        return $this->attempt(fn () => FS::record($d, $request->user()), fn ($l) => $l->in_range
            ? 'Recorded '.$l->subject().' '.rtrim(rtrim(number_format((float) $l->reading_c, 1), '0'), '.').' °C – within limits.'.($l->kind === 'cooling' ? ' Take the 6-hour reading later.' : '')
            : 'Out-of-range reading recorded with the corrective action – a manager will review it.');
    }

    public function cooling(Request $request, FsTempLog $fslog)
    {
        $d = $request->validate(['reading2_c' => 'required|numeric|between:-30,100', 'corrective_action' => 'nullable|string|max:255']);

        return $this->attempt(fn () => FS::completeCooling($fslog, (float) $d['reading2_c'], $d['corrective_action'] ?? null, $request->user()),
            fn ($l) => $l->in_range ? "Cooling of {$l->item} complete – within limits." : "Cooling of {$l->item} failed – corrective action recorded.");
    }

    public function review(Request $request, FsTempLog $fslog)
    {
        $d = $request->validate(['review_note' => 'nullable|string|max:255']);

        return $this->attempt(fn () => FS::review($fslog, $d['review_note'] ?? null, $request->user()), 'Reading reviewed.');
    }

    public function saveEquipment(Request $request, ?FsEquipment $fsequip = null)
    {
        $d = $request->validate(['name' => 'required|string|max:80', 'type' => ['required', Rule::in(array_keys(FsEquipment::TYPES))], 'location' => 'nullable|string|max:80',
            'outlet_id' => 'nullable|integer|owned:outlets', 'min_c' => 'nullable|numeric|between:-60,150', 'max_c' => 'nullable|numeric|between:-60,150',
            'checks_per_day' => 'required|integer|min:1|max:6', 'is_active' => 'nullable|boolean']);
        $d['is_active'] = $request->boolean('is_active', ! $fsequip);

        return $this->attempt(fn () => FS::saveEquipment($d, $fsequip), fn ($e) => "{$e->name} saved – critical limit {$e->limitText()}.");
    }

    // ------------------------------------------------------------------ checklists

    public function checklists(Request $request)
    {
        $from = $request->input('from', now()->subDays(13)->toDateString());

        return view('foodsafety.checklists', ['lists' => FS::checklistStatus(), 'all' => FsChecklist::orderByDesc('is_active')->orderBy('name')->get(), 'from' => $from,
            'runs' => FsChecklistRun::with('checklist', 'completer', 'signer')->whereDate('period', '>=', $from)->orderByDesc('period')->orderByDesc('completed_at')->get()]);
    }

    public function runForm(FsChecklist $fslist)
    {
        abort_unless($fslist->is_active, 404);
        $p = FS::periodFor($fslist);

        return view('foodsafety.checklist-run', ['c' => $fslist, 'period' => $p, 'done' => FsChecklistRun::where('fs_checklist_id', $fslist->id)->whereDate('period', $p->toDateString())->first()]);
    }

    public function complete(Request $request, FsChecklist $fslist)
    {
        $d = $request->validate(['answers' => 'required|array', 'answers.*.ok' => 'nullable|in:0,1', 'answers.*.note' => 'nullable|string|max:200', 'notes' => 'nullable|string|max:500']);

        return $this->attempt(fn () => FS::complete($fslist, $d['answers'], $d['notes'] ?? null, $request->user()),
            fn ($r) => "{$fslist->name} completed".($r->failed ? " – {$r->failed} item(s) failed, waiting for manager sign-off." : ' – all items OK.'), route('fs.checklists'));
    }

    public function showRun(FsChecklistRun $fsrun)
    {
        return view('foodsafety.checklist-show', ['run' => $fsrun->load('checklist', 'completer', 'signer')]);
    }

    public function sign(Request $request, FsChecklistRun $fsrun)
    {
        $d = $request->validate(['sign_note' => 'nullable|string|max:255']);

        return $this->attempt(fn () => FS::signOff($fsrun, $d['sign_note'] ?? null, $request->user()), 'Checklist signed off.');
    }

    public function saveChecklist(Request $request, ?FsChecklist $fslist = null)
    {
        $d = $request->validate(['name' => 'required|string|max:100', 'frequency' => ['required', Rule::in(array_keys(FsChecklist::FREQUENCIES))], 'area' => 'nullable|string|max:60',
            'items' => 'required|string|max:6000', 'due_time' => 'nullable|date_format:H:i', 'is_active' => 'nullable|boolean']);
        $d['is_active'] = $request->boolean('is_active', ! $fslist);

        return $this->attempt(fn () => FS::saveChecklist($d, $fslist), fn ($c) => "Checklist {$c->name} saved.");
    }

    // ------------------------------------------------------------------ allergens

    public function allergens(Request $request)
    {
        $menu = MenuItem::with('category', 'recipes.inventoryItem')->orderBy('menu_category_id')->orderBy('name')->get();
        $food = InventoryItem::with('category')->where('is_active', true)
            ->where(fn ($q) => $q->whereHas('recipes')->orWhereNotNull('allergens')->orWhereHas('category', fn ($c) => $c->whereIn('name', ['Grocery', 'Beverages', 'Food', 'Dairy', 'Meat & Fish', 'Vegetables'])))
            ->orderBy('name')->get();

        return view('foodsafety.allergens', ['menu' => $menu, 'map' => AllergenService::map($menu), 'food' => $food, 'att' => AllergenService::attention(), 'tab' => $request->input('tab', 'menu')]);
    }

    public function saveMenuAllergens(Request $request)
    {
        // rows[menu item id][allergen] = c (contains) | m (may contain) | '' (no)
        $d = $request->validate(['rows' => 'nullable|array', 'rows.*' => 'array', 'rows.*.*' => 'nullable|in:c,m', 'verify' => 'nullable|array', 'ids' => 'required|array', 'ids.*' => 'integer']);
        $rows = [];
        foreach ($d['ids'] as $id) {
            $cells = collect($d['rows'][$id] ?? []);
            $rows[(int) $id] = ['contains' => $cells->filter(fn ($v) => $v === 'c')->keys()->all(), 'may' => $cells->filter(fn ($v) => $v === 'm')->keys()->all()];
        }
        $n = AllergenService::saveMenu($rows, $d['verify'] ?? [], $request->user());

        return back()->with('success', $n ? "Allergen information saved and verified for {$n} dish(es)." : 'No changes.');
    }

    public function saveIngredientAllergens(Request $request)
    {
        $d = $request->validate(['ids' => 'required|array', 'rows' => 'nullable|array']);
        $rows = [];
        foreach ($d['ids'] as $id) {
            $rows[(int) $id] = $d['rows'][$id] ?? [];
        }
        $n = AllergenService::saveIngredients($rows);

        return redirect()->route('fs.allergens', ['tab' => 'ingredients'])->with('success', $n ? "Allergens updated on {$n} ingredient(s) – dishes using them now show these allergens." : 'No changes.');
    }

    public function allergenPrint(Request $request)
    {
        $menu = MenuItem::with('category', 'outlet', 'recipes.inventoryItem')->where('is_available', true)
            ->when($request->integer('outlet'), fn ($q, $o) => $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhere('outlet_id', $o)))
            ->orderBy('menu_category_id')->orderBy('name')->get();

        return view('foodsafety.allergens-print', ['menu' => $menu, 'map' => AllergenService::map($menu), 'outlet' => $request->integer('outlet') ? Outlet::find($request->integer('outlet')) : null]);
    }

    // ------------------------------------------------------------------ waste

    public function waste(Request $request)
    {
        $from = $request->input('from', bdate()->copy()->startOfMonth()->toDateString());
        $to = $request->input('to', bdate()->toDateString());

        return view('foodsafety.waste', ['from' => $from, 'to' => $to, 'summary' => FoodWasteService::summary($from, $to),
            'rows' => FsWaste::with('item', 'menuItem', 'store', 'outlet', 'user')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderByDesc('date')->orderByDesc('id')->get(),
            'stock' => InventoryItem::where('is_active', true)->whereHas('category', fn ($c) => $c->whereIn('name', ['Grocery', 'Beverages']))->orderBy('name')->get(),
            'dishes' => MenuItem::withCount('recipes')->orderBy('name')->get(), 'stores' => Store::whereIn('type', ['kitchen', 'bar', 'main'])->pluck('name', 'id'),
            'outlets' => Outlet::orderBy('name')->pluck('name', 'id'), 'defaultStore' => FoodWasteService::defaultStore()]);
    }

    public function storeWaste(Request $request)
    {
        $d = $request->validate(['source' => ['required', Rule::in(array_keys(FsWaste::SOURCES))], 'inventory_item_id' => 'nullable|required_if:source,raw|integer|owned:inventory_items',
            'menu_item_id' => 'nullable|required_unless:source,raw|integer|owned:menu_items', 'store_id' => 'nullable|integer|owned:stores', 'outlet_id' => 'nullable|integer|owned:outlets',
            'qty' => 'required|numeric|min:0.001|max:9999', 'reason' => ['required', Rule::in(array_keys(FsWaste::REASONS))], 'date' => 'nullable|date', 'notes' => 'nullable|string|max:255']);

        return $this->attempt(fn () => FoodWasteService::record($d, $request->user()), fn ($w) => "{$w->waste_no} recorded – ".money($w->cost).($w->source === 'plate' ? ' (analysis only; stock was used by the sale).' : ' taken out of stock.'));
    }

    public function cancelWaste(Request $request, FsWaste $fswaste)
    {
        $d = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(fn () => FoodWasteService::cancel($fswaste, $d['reason'], $request->user()), "{$fswaste->waste_no} cancelled – stock returned.");
    }

    // ------------------------------------------------------------------ certificates

    public function certs()
    {
        $h = FS::handlers();

        return view('foodsafety.certs', ['handlers' => $h, 'map' => FS::certMap($h->pluck('id')), 'history' => FsCertificate::with('employee')->latest('issued_on')->take(40)->get(),
            'employees' => Employee::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->name} ({$e->emp_code})"]),
            'departments' => Department::orderBy('name')->pluck('name', 'id'), 'handlerDepts' => FS::handlerDepartments()]);
    }

    public function storeCert(Request $request)
    {
        $d = $request->validate(['employee_id' => 'required|integer|owned:employees', 'type' => ['required', Rule::in(array_keys(FsCertificate::TYPES))], 'cert_no' => 'nullable|string|max:40',
            'issuer' => 'nullable|string|max:100', 'issued_on' => 'required|date', 'expires_on' => 'required|date', 'notes' => 'nullable|string|max:255']);

        return $this->attempt(fn () => FS::saveCertificate($d, $request->user()), fn ($c) => 'Certificate recorded for '.$c->employee->name.', valid to '.dmy($c->expires_on).'.');
    }

    // ------------------------------------------------------------------ labels

    public function labels()
    {
        return view('foodsafety.labels', ['recent' => FsLabel::with('user')->latest('id')->take(30)->get(), 'dishes' => MenuItem::orderBy('name')->pluck('name', 'id'),
            'shelf' => collect(FsLabel::STORAGE)->map(fn ($l, $k) => FS::shelfHours($k))]);
    }

    public function storeLabels(Request $request)
    {
        $d = $request->validate(['item' => 'nullable|string|max:80', 'menu_item_id' => 'nullable|integer|owned:menu_items', 'storage' => ['required', Rule::in(array_keys(FsLabel::STORAGE))],
            'prepared_at' => 'nullable|date', 'shelf_hours' => 'nullable|integer|min:1|max:4320', 'qty' => 'required|integer|min:1|max:48',
            'allergens' => 'nullable|array', 'allergens.*' => Rule::in(array_keys(AllergenService::ALLERGENS)), 'batch' => 'nullable|string|max:30']);
        try {
            $l = FS::createLabels($d, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('fs.labels')->with('success', "{$l->qty} label(s) ready for {$l->item}.")->with('print_label', $l->id);
    }

    public function printLabels(FsLabel $fslabel)
    {
        return view('foodsafety.labels-print', ['l' => $fslabel->load('user')]);
    }

    // ------------------------------------------------------------------ settings & inspection pack

    public function settings(Request $request)
    {
        $rules = collect(FS::SETTINGS)->mapWithKeys(fn ($s, $k) => [$k => 'required|numeric|between:-40,100'])->all();
        $d = $request->validate($rules + ['fs_cert_warn_days' => 'required|integer|min:0|max:180', 'fs_cert_roster' => 'nullable|boolean',
            'fs_handler_depts' => 'nullable|array', 'fs_handler_depts.*' => 'integer|owned:departments']);
        $d['fs_cert_roster'] = $request->boolean('fs_cert_roster') ? 1 : 0;
        $d['fs_handler_depts'] ??= [];
        if ($d['fs_fridge_max'] <= $d['fs_freezer_max'] || $d['fs_hot_hold_min'] <= $d['fs_fridge_max']) {
            return back()->withInput()->with('error', 'Check the limits: freezer < chilled < hot holding.');
        }
        FS::saveSettings($d);

        return back()->with('success', 'Food safety settings saved. New equipment uses these limits; existing units keep their own.');
    }

    public function pack(Request $request)
    {
        $from = $request->input('from', now()->subDays(6)->toDateString());
        $to = $request->input('to', now()->toDateString());
        if ($from > $to || Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 31) {
            return back()->with('error', 'Choose a range of up to 31 days.');
        }

        return view('foodsafety.pack', ['from' => $from, 'to' => $to,
            'logs' => FsTempLog::with('equipment', 'user', 'reviewer', 'supplier')->whereBetween('recorded_at', [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()])->orderBy('recorded_at')->get(),
            'runs' => FsChecklistRun::with('checklist', 'completer', 'signer')->whereDate('period', '>=', $from)->whereDate('period', '<=', $to)->orderBy('period')->get(),
            'waste' => FsWaste::with('item', 'menuItem')->whereNull('cancelled_at')->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderBy('date')->get(),
            'compliance' => FS::compliance(max(1, (int) Carbon::parse($from)->diffInDays(now()->startOfDay())))]);
    }
}
