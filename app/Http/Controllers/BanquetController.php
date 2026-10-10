<?php

namespace App\Http\Controllers;

use App\Models\BanquetEvent;
use App\Models\BanquetFunction;
use App\Models\BanquetHall;
use App\Models\BanquetPackage;
use App\Models\BanquetService as ServiceItem;
use App\Models\Company;
use App\Models\PaymentMode;
use App\Models\RoomBlock;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\BanquetSalesService as Sales;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BanquetController extends Controller
{
    public const TABS = ['upcoming' => 'Upcoming (definite)', 'tentative' => 'Tentative holds', 'enquiry' => 'Enquiries', 'to_bill' => 'To bill',
        'completed' => 'Completed', 'closed' => 'Lost & cancelled', 'all' => 'All'];

    public function index(Request $request)
    {
        $tab = array_key_exists($request->input('show'), self::TABS) ? $request->input('show') : 'upcoming';
        $today = bdate()->toDateString();
        $q = BanquetEvent::with('functions.items', 'functions.hall', 'company', 'salesUser')
            ->when($request->filled('q'), fn ($w) => $w->where(fn ($x) => $x->where('event_no', 'like', '%'.$request->q.'%')->orWhere('title', 'like', '%'.$request->q.'%')->orWhere('contact_name', 'like', '%'.$request->q.'%')))
            ->when($request->filled('type'), fn ($w) => $w->where('event_type', $request->type))
            ->when($request->filled('sales'), fn ($w) => $w->where('sales_user_id', $request->sales));
        match ($tab) {
            'upcoming' => $q->where('status', 'definite')->whereDate('end_date', '>=', $today)->orderBy('event_date'),
            'tentative' => $q->where('status', 'tentative')->orderBy('option_date'),
            'enquiry' => $q->where('status', 'enquiry')->orderBy('event_date'),
            'to_bill' => $q->where('status', 'definite')->whereNull('billed_at')->whereDate('end_date', '<=', $today)->orderBy('end_date'),
            'completed' => $q->where('status', 'completed')->orderByDesc('event_date'),
            'closed' => $q->whereIn('status', ['lost', 'cancelled'])->orderByDesc('closed_at'),
            default => $q->orderByDesc('event_date'),
        };
        $events = $q->paginate(25)->withQueryString();

        $open = BanquetEvent::with('functions.items')->whereIn('status', ['enquiry', 'tentative', 'definite'])->get();
        $since = bdate()->copy()->subDays(90);
        $decided = BanquetEvent::where('created_at', '>=', $since)->whereIn('status', ['definite', 'completed', 'cancelled', 'lost'])->get();
        $won = $decided->whereIn('status', ['definite', 'completed', 'cancelled'])->count();
        $stats = [
            'definite' => $open->where('status', 'definite')->sum(fn ($e) => $e->net()),
            'definite_n' => $open->where('status', 'definite')->count(),
            'tentative' => $open->where('status', 'tentative')->sum(fn ($e) => $e->net()),
            'tentative_n' => $open->where('status', 'tentative')->count(),
            'expiring' => $open->where('status', 'tentative')->filter(fn ($e) => $e->option_date && $e->option_date->lte(bdate()->copy()->addDays(3)))->count(),
            'enquiry_n' => $open->where('status', 'enquiry')->count(),
            'conversion' => $decided->count() ? round($won / $decided->count() * 100) : null,
            'deposits' => $open->sum(fn ($e) => $e->depositsReceived()),
            'to_bill' => BanquetEvent::where('status', 'definite')->whereNull('billed_at')->whereDate('end_date', '<=', $today)->count(),
        ];

        return view('banquet.index', ['events' => $events, 'tab' => $tab, 'stats' => $stats,
            'sales' => User::where('hotel_id', Tenant::id())->whereIn('id', BanquetEvent::whereNotNull('sales_user_id')->distinct()->pluck('sales_user_id'))->pluck('name', 'id')]);
    }

    public function calendar()
    {
        return redirect()->route('banquet.diary');
    }

    public function diary(Request $request)
    {
        $days = in_array((int) $request->input('days'), [7, 14, 31], true) ? (int) $request->input('days') : 14;
        $from = Carbon::parse($request->input('start', bdate()->toDateString()))->startOfDay();
        $to = $from->copy()->addDays($days - 1);
        $halls = BanquetHall::where('is_active', true)->orderBy('sort')->orderBy('name')->get();

        return view('banquet.diary', ['from' => $from, 'to' => $to, 'days' => $days, 'halls' => $halls,
            'cells' => Sales::diary($from, $to, $request->input('enquiries', '1') === '1'), 'withEnquiries' => $request->input('enquiries', '1') === '1']);
    }

    protected function formData(BanquetEvent $e, array $functions): array
    {
        $halls = BanquetHall::where('is_active', true)->orderBy('sort')->orderBy('name')->get();
        $pkgs = BanquetPackage::where('is_active', true)->orderBy('sort')->orderBy('name')->get();
        $svcs = ServiceItem::where('is_active', true)->orderBy('category')->orderBy('name')->get();

        return [
            'event' => $e, 'functions' => $functions, 'halls' => $halls, 'packages' => $pkgs, 'services' => $svcs,
            'companies' => Company::orderBy('name')->pluck('name', 'id'),
            'blocks' => RoomBlock::whereIn('status', ['tentative', 'definite'])->orderBy('arrival_date')->get()->mapWithKeys(fn ($b) => [$b->id => $b->block_no.' – '.$b->name]),
            'salesUsers' => User::where('hotel_id', Tenant::id())->where('is_active', true)->orderBy('name')->get()->filter(fn ($u) => $u->hasPermission('banquet.manage'))->pluck('name', 'id'),
            'hallData' => $halls->mapWithKeys(fn ($h) => [$h->id => ['cap' => $h->capacity, 'caps' => collect(BanquetFunction::SETUPS)->keys()->mapWithKeys(fn ($s) => [$s => $h->capacityFor($s)]),
                'half' => $h->rate_per_session, 'full' => $h->rate_full_day]]),
            'pkgData' => $pkgs->mapWithKeys(fn ($p) => [$p->id => ['p' => $p->price_per_pax, 'min' => $p->min_pax, 't' => $p->tax_rate]]),
            'svcData' => $svcs->mapWithKeys(fn ($s) => [$s->id => ['n' => $s->name, 'p' => $s->price, 't' => $s->tax_rate, 'u' => $s->unit]]),
            'halfDayHours' => (float) setting('banquet_half_day_hours', '5'), 'hallTax' => (float) setting('banquet_hall_tax_rate', '21'),
        ];
    }

    public function create(Request $request)
    {
        $e = new BanquetEvent(['event_type' => 'wedding', 'status' => 'enquiry', 'sales_user_id' => auth()->id(), 'source' => 'phone']);
        $fn = [['id' => null, 'name' => '', 'banquet_hall_id' => (int) $request->input('hall') ?: null, 'date' => $request->input('date', bdate()->copy()->addDays(14)->toDateString()),
            'start_time' => '19:00', 'end_time' => '23:00', 'setup_style' => 'banquet', 'expected_pax' => null, 'guaranteed_pax' => null, 'banquet_package_id' => null,
            'rate_per_pax' => null, 'hall_rate' => null, 'notes' => null, 'items' => []]];

        return view('banquet.form', $this->formData($e, $fn));
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'contact_name' => 'required|string|max:120', 'contact_phone' => 'nullable|string|max:30', 'contact_email' => 'nullable|email|max:150',
            'company_id' => 'nullable|integer|owned:companies', 'event_type' => 'required|in:'.implode(',', array_keys(BanquetEvent::TYPES)),
            'title' => 'required|string|max:150', 'source' => 'nullable|in:'.implode(',', array_keys(BanquetEvent::SOURCES)),
            'sales_user_id' => 'nullable|integer|owned:users', 'room_block_id' => 'nullable|integer|owned:room_blocks',
            'deposit_due' => 'nullable|numeric|min:0|max:99999999', 'deposit_due_date' => 'nullable|date',
            'requirements' => 'nullable|string|max:3000', 'internal_notes' => 'nullable|string|max:3000',
            'status' => 'nullable|in:enquiry,tentative', 'option_date' => 'nullable|date',
            'functions' => 'required|array|min:1|max:30', 'functions.*.id' => 'nullable|integer',
            'functions.*.name' => 'nullable|string|max:120', 'functions.*.banquet_hall_id' => 'required|integer|owned:banquet_halls',
            'functions.*.date' => 'required|date', 'functions.*.start_time' => 'required|date_format:H:i', 'functions.*.end_time' => 'required|date_format:H:i',
            'functions.*.setup_style' => 'required|in:'.implode(',', array_keys(BanquetFunction::SETUPS)),
            'functions.*.expected_pax' => 'required|integer|min:1|max:10000', 'functions.*.guaranteed_pax' => 'nullable|integer|min:1|max:10000',
            'functions.*.banquet_package_id' => 'nullable|integer|owned:banquet_packages', 'functions.*.rate_per_pax' => 'nullable|numeric|min:0|max:999999',
            'functions.*.hall_rate' => 'nullable|numeric|min:0|max:99999999', 'functions.*.notes' => 'nullable|string|max:3000',
            'functions.*.items' => 'nullable|array|max:40', 'functions.*.items.*.banquet_service_id' => 'nullable|integer|owned:banquet_services',
            'functions.*.items.*.description' => 'nullable|string|max:150', 'functions.*.items.*.qty' => 'nullable|numeric|min:0|max:100000',
            'functions.*.items.*.unit_price' => 'nullable|numeric|min:0|max:99999999', 'functions.*.items.*.tax_rate' => 'nullable|numeric|min:0|max:50',
        ], ['functions.*.banquet_hall_id.required' => 'Pick a venue for every function.', 'functions.*.expected_pax.required' => 'Enter the expected number of guests for every function.']);
    }

    public function store(Request $request)
    {
        $e = Sales::save($this->validated($request));
        ActivityLogger::log('banquet', 'create', "Banquet {$e->event_no} {$e->title} ({$e->status})", $e);

        return redirect()->route('banquet.show', $e)->with('success', "{$e->event_no} saved as ".($e->status === 'tentative' ? 'a tentative hold until '.$e->option_date->format('d M') : 'an enquiry').'.');
    }

    public function show(BanquetEvent $event)
    {
        $event->load('functions.items.service.department', 'functions.hall', 'functions.package', 'company', 'folio', 'salesUser', 'roomBlock', 'closer');
        $folio = $event->folio;

        return view('banquet.show', [
            'e' => $event, 'folio' => $folio, 'deposits' => $event->depositsReceived(),
            'txns' => $folio ? $folio->transactions()->where('is_void', false)->orderBy('id')->get() : collect(),
            'modes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'online', 'gift_voucher'])->pluck('name', 'id'),
            'quote' => $event->status === 'definite' && ! $event->billed_at ? Sales::cancellationQuote($event) : null,
            'suggestDeposit' => round($event->total() * (float) setting('banquet_deposit_pct', '25') / 100 / 100) * 100,
        ]);
    }

    public function edit(BanquetEvent $event)
    {
        abort_unless($event->isEditable(), 403);
        $fns = $event->functions()->with('items')->get()->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'banquet_hall_id' => $f->banquet_hall_id, 'date' => $f->date->toDateString(),
            'start_time' => substr($f->start_time, 0, 5), 'end_time' => substr($f->end_time, 0, 5), 'setup_style' => $f->setup_style, 'expected_pax' => $f->expected_pax,
            'guaranteed_pax' => $f->guaranteed_pax, 'banquet_package_id' => $f->banquet_package_id, 'rate_per_pax' => $f->rate_per_pax, 'hall_rate' => $f->hall_rate, 'notes' => $f->notes,
            'locked' => $f->guaranteeLocked() && $f->guaranteed_pax !== null,
            'items' => $f->items->map(fn ($i) => ['banquet_service_id' => $i->banquet_service_id, 'description' => $i->description, 'qty' => $i->qty, 'unit_price' => $i->unit_price, 'tax_rate' => $i->tax_rate])->all()])->all();

        return view('banquet.form', $this->formData($event, $fns));
    }

    public function update(Request $request, BanquetEvent $event)
    {
        $e = Sales::save($this->validated($request), $event);
        ActivityLogger::log('banquet', 'update', "Updated banquet {$e->event_no}", $e);

        return redirect()->route('banquet.show', $e)->with('success', 'Event updated.');
    }

    // ------------------------------------------------------------------ pipeline actions

    public function tentative(Request $request, BanquetEvent $event)
    {
        $data = $request->validate(['option_date' => 'required|date']);
        Sales::holdTentative($event, $data['option_date']);

        return back()->with('success', "Venue held for {$event->event_no} until ".Carbon::parse($data['option_date'])->format('d M Y').'.');
    }

    public function confirm(BanquetEvent $event)
    {
        Sales::confirm($event);

        return back()->with('success', "{$event->event_no} is definite. The function sheet (BEO) can go to the departments.");
    }

    public function lost(Request $request, BanquetEvent $event)
    {
        $data = $request->validate(['lost_reason' => 'required|string|max:255']);
        Sales::markLost($event, $data['lost_reason']);

        return back()->with('success', "{$event->event_no} marked as lost business.");
    }

    public function reopen(BanquetEvent $event)
    {
        Sales::reopen($event);

        return back()->with('success', "{$event->event_no} re-opened as an enquiry.");
    }

    public function cancel(Request $request, BanquetEvent $event)
    {
        $data = $request->validate(['cancel_reason' => 'required|string|max:200', 'waive' => 'nullable|boolean', 'waive_reason' => 'nullable|string|max:200']);
        $waive = (bool) ($data['waive'] ?? false);
        abort_if($waive && ! can_do('banquet.billing'), 403);
        Sales::cancel($event, $data['cancel_reason'], $waive, $data['waive_reason'] ?? null);
        $event->refresh();

        return back()->with('success', "{$event->event_no} cancelled".($event->cancellation_fee > 0 ? ' – cancellation fee '.money($event->cancellation_fee).' posted to the event folio.' : ' with no cancellation fee.'));
    }

    // ------------------------------------------------------------------ money

    public function deposit(Request $request, BanquetEvent $event)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01|max:99999999', 'payment_mode_id' => 'required|integer|owned:payment_modes', 'reference' => 'nullable|string|max:60', 'cheque_bank_name' => 'nullable|string|max:100', 'cheque_number' => 'nullable|string|max:20', 'cheque_bank_branch' => 'nullable|string|max:100']);
        $tx = Sales::takeDeposit($event, (float) $data['amount'], (int) $data['payment_mode_id'], $data['reference'] ?? null, [
            'bank_name' => $data['cheque_bank_name'] ?? null, 'number' => $data['cheque_number'] ?? null, 'bank_branch' => $data['cheque_bank_branch'] ?? null,
        ]);

        return back()->with('success', 'Deposit of '.money($tx->amount).' received on folio '.$event->fresh()->folio?->folio_no.'.');
    }

    public function refund(Request $request, BanquetEvent $event)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'payment_mode_id' => 'required|integer|owned:payment_modes', 'reference' => 'nullable|string|max:60', 'cheque_bank_name' => 'nullable|string|max:100', 'cheque_number' => 'nullable|string|max:20', 'cheque_bank_branch' => 'nullable|string|max:100']);
        Sales::refundDeposit($event, (float) $data['amount'], (int) $data['payment_mode_id'], $data['reference'] ?? null, [
            'bank_name' => $data['cheque_bank_name'] ?? null, 'number' => $data['cheque_number'] ?? null, 'bank_branch' => $data['cheque_bank_branch'] ?? null,
        ]);

        return back()->with('success', 'Refund of '.money($data['amount']).' recorded.');
    }

    public function actuals(Request $request, BanquetEvent $event)
    {
        $data = $request->validate(['actual' => 'required|array', 'actual.*' => 'nullable|integer|min:0|max:100000']);
        $event->load('functions.hall');
        Sales::recordActuals($event, $data['actual']);

        return back()->with('success', 'Attendance saved.');
    }

    public function bill(BanquetEvent $event)
    {
        $folio = Sales::bill($event);

        $to = can_do('folio.view') ? redirect()->route('folios.show', $folio) : redirect()->route('banquet.show', $event);

        return $to->with('success', "{$event->event_no} billed to folio {$folio->folio_no}. Deposits are already on it – settle the balance or transfer it to the company.");
    }

    // ------------------------------------------------------------------ documents

    public function print(BanquetEvent $event)
    {
        $event->load('functions.items.service.department', 'functions.hall', 'functions.package', 'company', 'salesUser', 'roomBlock');

        return view('banquet.print', ['e' => $event, 'hotel' => Tenant::hotel(), 'deposits' => $event->depositsReceived()]);
    }

    public function quote(BanquetEvent $event)
    {
        $event->load('functions.items', 'functions.hall', 'functions.package', 'company', 'salesUser');

        return view('banquet.quote', ['e' => $event, 'hotel' => Tenant::hotel(), 'deposits' => $event->depositsReceived(),
            'terms' => setting('banquet_terms', Sales::DEFAULTS['banquet_terms'])]);
    }

    public function sheet(Request $request)
    {
        $date = Carbon::parse($request->input('date', bdate()->toDateString()));
        $fns = BanquetFunction::with('event.company', 'hall', 'package', 'items.service.department')->whereDate('date', $date->toDateString())
            ->whereHas('event', fn ($q) => $q->whereIn('status', ['tentative', 'definite', 'completed']))->orderBy('start_time')->get();

        return view('banquet.sheet', ['date' => $date, 'fns' => $fns, 'hotel' => Tenant::hotel()]);
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        return view('banquet.settings', ['s' => collect(Sales::DEFAULTS)->map(fn ($v, $k) => setting($k, $v))]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'banquet_guarantee_days' => 'required|integer|min:0|max:60', 'banquet_turnaround_minutes' => 'required|integer|min:0|max:480',
            'banquet_half_day_hours' => 'required|numeric|min:1|max:24', 'banquet_deposit_pct' => 'required|numeric|min:0|max:100',
            'banquet_hall_tax_rate' => 'required|numeric|min:0|max:50', 'banquet_terms' => 'nullable|string|max:5000',
            'banquet_cancel_policy' => ['required', 'string', 'max:200', 'regex:/^\s*\d+\s*:\s*\d+(\.\d+)?\s*(,\s*\d+\s*:\s*\d+(\.\d+)?\s*)*$/'],
        ], ['banquet_cancel_policy.regex' => 'Write the policy as days:percent pairs, e.g. 60:0,30:25,14:50,7:75,0:100.']);
        foreach (explode(',', $data['banquet_cancel_policy']) as $t) {
            if ((float) explode(':', $t)[1] > 100) {
                return back()->withErrors(['banquet_cancel_policy' => 'A percentage can\'t be more than 100.'])->withInput();
            }
        }
        foreach ($data as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => (string) $v]);
        }
        Setting::$version++;
        ActivityLogger::log('settings', 'banquet', 'Updated banquet settings');

        return back()->with('success', 'Banquet settings saved.');
    }
}
