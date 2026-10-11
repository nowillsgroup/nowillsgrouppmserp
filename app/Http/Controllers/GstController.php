<?php

namespace App\Http\Controllers;

use App\Models\EinvoiceLog;
use App\Models\Folio;
use App\Models\GstCancelledDoc;
use App\Models\GstPeriod;
use App\Models\PaymentMode;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\EInvoiceService;
use App\Services\GstService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** v4.3 Ghana GRA VAT compliance: E-VAT stamping, credit notes (refunds), invoice cancellation, monthly VAT & Tourism Levy return, filing record, settings. */
class GstController extends Controller
{
    public const EI_TABS = ['attention' => 'Needs attention', 'generated' => 'Stamped', 'all' => 'All documents', 'pos' => 'Restaurant bills'];

    protected function period(Request $request): string
    {
        $p = (string) $request->input('period', bdate()->format('Y-m'));

        return preg_match('/^20\d\d-(0[1-9]|1[0-2])$/', $p) ? $p : bdate()->format('Y-m');
    }

    protected function periods(): array
    {
        $out = [];
        $d = bdate()->copy()->startOfMonth();
        for ($i = 0; $i < 18; $i++) {
            $out[$d->format('Y-m')] = $d->format('M Y');
            $d->subMonth();
        }

        return $out;
    }

    public function index(Request $request)
    {
        $period = $this->period($request);
        $r = GstService::vatReturn($period);
        $docs = GstService::documents($period);

        return view('gst.index', [
            'period' => $period, 'periods' => $this->periods(), 'r' => $r, 'checks' => GstService::checks($period, $r), 'alerts' => GstService::alerts(),
            'counts' => ['invoices' => $docs->where('type', '!=', 'credit_note')->count(), 'b2b' => $docs->where('type', '!=', 'credit_note')->where('supply_type', 'B2B')->count(),
                'notes' => $docs->where('type', 'credit_note')->count(), 'cancelled' => GstCancelledDoc::where('doc_date', 'like', $period.'%')->count()],
            'ei' => Folio::where('status', 'closed')->whereNotNull('invoice_no')->where('invoice_date', 'like', $period.'%')
                ->selectRaw("COALESCE(irn_status, 'none') s, COUNT(*) n")->groupBy('s')->pluck('n', 's'),
            'failed' => Folio::with('guest', 'company')->where('irn_status', 'failed')->where('status', 'closed')->orderByDesc('invoice_date')->limit(8)->get(),
            'filings' => GstPeriod::with('filer')->orderByDesc('period')->limit(12)->get(),
            'tin' => GstService::hotelTin(), 'tinOk' => GstService::validTin(GstService::hotelTin()),
            'enabled' => EInvoiceService::enabled(), 'driver' => EInvoiceService::driver(),
        ]);
    }

    public function einvoices(Request $request)
    {
        $tab = array_key_exists($request->tab, self::EI_TABS) ? $request->tab : 'attention';
        $base = fn () => Folio::whereNotNull('invoice_no')->whereIn('status', ['closed', 'cancelled']);
        $scope = fn ($q, $t) => match ($t) {
            'attention' => $q->where('status', 'closed')->where(fn ($w) => $w->whereIn('irn_status', ['failed', 'pending'])->orWhereNull('irn_status')),
            'generated' => $q->where('irn_status', 'generated'),
            default => $q,
        };
        $posBase = fn () => \App\Models\PosOrder::where('status', 'settled')->where('settlement', 'payment')->where('total', '>', 0); // v4.4 outlet bills
        $counts = collect(self::EI_TABS)->map(fn ($l, $k) => $k === 'pos' ? $posBase()->where(fn ($w) => $w->whereNull('evat_status')->orWhere('evat_status', '!=', 'generated'))->count() : $scope($base(), $k)->count());
        if ($tab === 'pos') {
            $bills = $posBase()->with('outlet:id,name')
                ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('bill_no', 'like', "%$t%")->orWhere('buyer_tin', 'like', "%$t%")->orWhere('buyer_name', 'like', "%$t%")))
                ->when($request->status, fn ($q, $st) => $st === 'none' ? $q->whereNull('evat_status') : $q->where('evat_status', $st))
                ->orderByRaw("CASE WHEN evat_status = 'generated' THEN 1 ELSE 0 END")->orderByDesc('business_date')->orderByDesc('id')->paginate(30)->withQueryString();

            return view('gst.einvoices', ['tab' => $tab, 'counts' => $counts, 'docs' => null, 'bills' => $bills, 'enabled' => EInvoiceService::enabled(), 'posEnabled' => \App\Services\PosEvatService::enabled(),
                'driver' => EInvoiceService::driver(), 'logs' => EinvoiceLog::with('user')->whereNotNull('pos_order_id')->latest('id')->limit(15)->get()]);
        }
        $docs = $scope($base(), $tab)->with('guest', 'company', 'original:id,invoice_no')
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%$t%")->orWhere('buyer_gstin', 'like', "%$t%")->orWhere('buyer_name', 'like', "%$t%")))
            ->orderByDesc('invoice_date')->orderByDesc('id')->paginate(30)->withQueryString();

        return view('gst.einvoices', ['tab' => $tab, 'counts' => $counts, 'docs' => $docs, 'bills' => null, 'enabled' => EInvoiceService::enabled(), 'posEnabled' => \App\Services\PosEvatService::enabled(), 'driver' => EInvoiceService::driver(),
            'logs' => EinvoiceLog::with('user')->whereNull('pos_order_id')->latest('id')->limit(15)->get()]); // restaurant bills have their own log on their tab
    }

    public function creditNotes(Request $request)
    {
        $notes = Folio::with('original:id,invoice_no,invoice_date', 'guest', 'company')->where('type', 'credit_note')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('invoice_no', 'like', "%$t%")->orWhere('buyer_name', 'like', "%$t%")
                ->orWhereHas('original', fn ($o) => $o->where('invoice_no', 'like', "%$t%"))))
            ->orderByDesc('invoice_date')->orderByDesc('id')->paginate(30)->withQueryString();

        return view('gst.credit-notes', ['notes' => $notes, 'cancelled' => GstCancelledDoc::with('user')->latest('id')->limit(20)->get()]);
    }

    public function vatReturn(Request $request)
    {
        $period = $this->period($request);
        $r = GstService::vatReturn($period);

        return view('gst.vat-return', ['period' => $period, 'periods' => $this->periods(), 'r' => $r, 'checks' => GstService::checks($period, $r),
            'tab' => in_array($request->tab, ['summary', 'sales', 'levy', 'docs']) ? $request->tab : 'summary']);
    }

    public function exportReturn(Request $request)
    {
        $period = $this->period($request);
        $rows = GstService::exportRows($period);
        ActivityLogger::log('gst', 'export', 'VAT sales register downloaded for '.Carbon::parse($period.'-01')->format('M Y'));

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $r) {
                fputcsv($out, $r);
            }
            fclose($out);
        }, 'VAT_'.(GstService::hotelTin() ?? 'NA').'_'.str_replace('-', '', $period).'.csv', ['Content-Type' => 'text/csv']);
    }

    public function file(Request $request)
    {
        $data = $request->validate(['period' => 'required|regex:/^20\d\d-\d\d$/', 'arn' => ['required', 'string', 'min:6', 'max:40', 'regex:/^[A-Za-z0-9\/\-]+$/'],
            'filed_on' => 'required|date|before_or_equal:today'], ['arn.regex' => 'Enter the acknowledgement / reference number shown by the GRA Taxpayers\' Portal (letters, digits, / and -).']);
        GstService::file($data['period'], $data['arn'], $data['filed_on'], auth()->id());

        return redirect()->route('gst.return', ['period' => $data['period']])->with('success', 'VAT return for '.Carbon::parse($data['period'].'-01')->format('M Y').' marked as filed. Invoices and credit notes of the month are now locked.');
    }

    public function settings()
    {
        return view('gst.settings', ['s' => collect(GstService::DEFAULTS)->map(fn ($v, $k) => setting($k, $v)),
            'taxes' => \App\Models\Tax::orderBy('id')->get(), 'tin' => GstService::hotelTin(), 'tinOk' => GstService::validTin(GstService::hotelTin()), 'hotel' => hotel()]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate([
            'gst_einvoice_enabled' => 'boolean', 'gst_einvoice_auto' => 'boolean', 'gst_evat_pos' => 'boolean', 'gst_einvoice_driver' => 'required|in:sandbox,gra',
            'gst_evat_url' => 'nullable|url|max:255|required_if:gst_einvoice_driver,gra', 'gst_evat_key' => 'nullable|string|max:500', 'gst_evat_username' => 'nullable|string|max:60',
            'tax_prices_inclusive' => 'required|in:0,1',
        ]);
        if ($data['gst_evat_url'] ?? null) {
            try {
                \App\Services\IcalService::assertSafeUrl($data['gst_evat_url']);
            } catch (\Throwable $e) {
                return back()->withInput()->withErrors(['gst_evat_url' => 'This address cannot be used: '.$e->getMessage()]);
            }
        }
        if (($data['gst_einvoice_enabled'] ?? false) && ! GstService::validTin(GstService::hotelTin())) {
            return back()->withInput()->withErrors(['gst_einvoice_enabled' => 'Set a valid hotel TIN in Hotel settings before switching on E-VAT.']);
        }
        $vals = ['gst_einvoice_enabled' => ! empty($data['gst_einvoice_enabled']) ? '1' : '0', 'gst_einvoice_auto' => ! empty($data['gst_einvoice_auto']) ? '1' : '0', 'gst_evat_pos' => ! empty($data['gst_evat_pos']) ? '1' : '0',
            'gst_einvoice_driver' => $data['gst_einvoice_driver'], 'gst_evat_url' => (string) ($data['gst_evat_url'] ?? ''), 'gst_evat_username' => (string) ($data['gst_evat_username'] ?? ''), 'tax_prices_inclusive' => $data['tax_prices_inclusive']];
        if (filled($data['gst_evat_key'] ?? null)) { // secret: blank keeps the stored value
            $vals['gst_evat_key'] = $data['gst_evat_key'];
        }
        foreach ($vals as $k => $v) {
            Setting::updateOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
        ActivityLogger::log('gst', 'settings', 'GRA VAT / E-VAT settings updated (E-VAT '.($vals['gst_einvoice_enabled'] === '1' ? 'on, '.EInvoiceService::driverLabel($vals['gst_einvoice_driver']) : 'off').')');

        return back()->with('success', 'VAT settings saved.');
    }

    public function retryAll()
    {
        abort_unless(EInvoiceService::enabled(), 422, 'E-VAT is switched off.');
        $ok = 0;
        $bad = 0;
        // manual retry ignores the back-off; documents with data errors (not retryable) need fixing first
        foreach (Folio::where('status', 'closed')->where(fn ($q) => $q->where('irn_status', 'pending')
            ->orWhere(fn ($w) => $w->where('irn_status', 'failed')->where('irn_retryable', true)))->orderBy('invoice_date')->orderBy('id')->limit(100)->get() as $f) {
            EInvoiceService::generate($f, auth()->id()) ? $ok++ : $bad++;
        }
        [$pok, $pbad] = \App\Services\PosEvatService::retry(false, auth()->id()); // v4.4 restaurant bills
        $ok += $pok;
        $bad += $pbad;

        return back()->with($bad ? 'warning' : 'success', "E-VAT retry: {$ok} stamped, {$bad} still failing.");
    }

    // ------------------------------------------------------------------ per document

    /** v4.4: correct (or add) the customer TIN of a bill that is not stamped yet, then submit it. */
    public function posBuyer(Request $request, \App\Models\PosOrder $order)
    {
        abort_unless(\App\Services\PosEvatService::stampable($order) && $order->evat_status !== 'generated', 422, 'Only settled outlet bills that are not stamped yet can be corrected.');
        $d = $request->validate(['buyer_tin' => ['nullable', 'string', 'max:20', function ($a, $v, $fail) { if (filled($v) && ! GstService::validTin($v)) { $fail('Enter a valid Ghana TIN or Ghana Card PIN, or leave it blank for a cash customer.'); } }],
            'buyer_name' => 'nullable|required_with:buyer_tin|string|max:120']);
        $old = $order->buyer_tin;
        $order->forceFill(['buyer_tin' => filled($d['buyer_tin'] ?? null) ? GstService::normaliseTin($d['buyer_tin']) : null, 'buyer_name' => filled($d['buyer_tin'] ?? null) ? $d['buyer_name'] : null])->saveQuietly();
        ActivityLogger::log('pos', 'evat_buyer', "Bill {$order->bill_no}: customer TIN ".($old ?: 'none').' → '.($order->buyer_tin ?: 'cash customer'), $order);

        return $this->stampPos($order);
    }

    /** v4.4: submit (or retry) one restaurant bill. */
    public function stampPos(\App\Models\PosOrder $order)
    {
        abort_unless(\App\Services\PosEvatService::enabled(), 422, 'E-VAT for restaurant bills is switched off.');
        abort_unless(\App\Services\PosEvatService::stampable($order), 422, 'Only settled bills paid at the outlet are stamped (room-posted bills are part of the guest invoice).');
        $ok = \App\Services\PosEvatService::generate($order, auth()->id());
        $order->refresh();

        return back()->with($ok ? 'success' : 'error', $ok ? "Bill {$order->bill_no} stamped by GRA E-VAT (receipt {$order->evat_rec_num})." : "E-VAT refused bill {$order->bill_no}: {$order->evat_error}");
    }

    public function generate(Folio $folio)
    {
        abort_unless($folio->status === 'closed' && $folio->invoice_no, 422, 'Only issued documents can be submitted to E-VAT.');
        if (! EInvoiceService::enabled()) {
            return back()->with('error', 'E-VAT is switched off (Billing › GRA VAT › Settings).');
        }
        $ok = EInvoiceService::generate($folio, auth()->id());

        return back()->with($ok ? 'success' : 'error', $ok ? "{$folio->invoice_no} stamped by GRA E-VAT." : 'E-VAT did not accept the document: '.$folio->fresh()->irn_error);
    }

    public function refreshBuyer(Folio $folio)
    {
        abort_unless($folio->invoice_no && $folio->status === 'closed', 422);
        if ($folio->hasActiveIrn() || GstPeriod::isFiled($folio->invoice_date?->toDateString())) {
            return back()->with('error', 'Buyer details are locked once the document is stamped by E-VAT or the VAT return is filed.');
        }
        $before = $folio->supply_type;
        $snap = GstService::snapshot($folio->load('company', 'guest'));
        $folio->forceFill($snap)->save();
        ActivityLogger::log('gst', 'buyer', "Buyer details refreshed on {$folio->invoice_no} ({$before} → {$snap['supply_type']})", $folio);
        if (EInvoiceService::eligible($folio) && setting('gst_einvoice_auto', '1') === '1') {
            $ok = EInvoiceService::generate($folio, auth()->id());

            return back()->with($ok ? 'success' : 'warning', $ok ? 'Buyer details refreshed and the document stamped by E-VAT.' : 'Buyer details refreshed; E-VAT still failing: '.$folio->fresh()->irn_error);
        }

        return back()->with('success', 'Buyer details refreshed from the '.($folio->company_id ? 'company' : 'guest').' profile.');
    }

    public function cancel(Request $request, Folio $folio)
    {
        $data = $request->validate(['reason_code' => 'required|in:'.implode(',', array_keys(GstCancelledDoc::REASONS)), 'remarks' => 'required|string|min:5|max:100']);
        $no = $folio->invoice_no;
        $cn = $folio->isCreditNote();
        GstService::cancel($folio, $data['reason_code'], $data['remarks'], auth()->id());

        return redirect()->route('folios.show', $folio)->with('success', $cn ? "Credit note {$no} cancelled." : "Invoice {$no} cancelled – the folio is open again and will get a new invoice number when settled.");
    }

    public function payload(Folio $folio)
    {
        abort_unless($folio->invoice_no, 404);

        return response()->json(['errors' => EInvoiceService::validate($folio), 'payload' => EInvoiceService::payload($folio)], 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function creditNoteForm(Folio $folio)
    {
        abort_unless($folio->isInvoiced() && ! $folio->isCreditNote(), 422, 'Credit notes can only be issued against an issued invoice.');
        $folio->load('guest', 'company');
        $cl = $folio->activeTransactions()->where('type', 'payment')->where('category', 'city_ledger')->whereNotNull('company_id')->with('company')->first();

        return view('gst.credit-note-form', ['inv' => $folio, 'lines' => GstService::creditable($folio),
            'cl' => $cl, 'modes' => PaymentMode::where('is_active', true)->whereNotIn('type', ['city_ledger', 'online', 'gift_voucher'])->pluck('name', 'id'),
            'notes' => $folio->creditNotes()->orderBy('id')->get(), 'filed' => GstPeriod::isFiled(bdate()->toDateString())]);
    }

    public function storeCreditNote(Request $request, Folio $folio)
    {
        $data = $request->validate(['amounts' => 'required|array', 'amounts.*' => 'nullable|numeric|min:0', 'note_reason' => 'required|in:'.implode(',', array_keys(Folio::NOTE_REASONS)),
            'note_remarks' => 'nullable|string|max:255', 'settle' => 'required|in:refund,city_ledger', 'payment_mode_id' => 'nullable|required_if:settle,refund|integer|owned:payment_modes']);
        $cn = GstService::issueCreditNote($folio, $data['amounts'], $data['note_reason'], $data['note_remarks'] ?? null, $data['settle'], $data['payment_mode_id'] ?? null, auth()->id());
        $msg = "Credit note {$cn->invoice_no} issued for ".money(abs($cn->gstTotals()['value'])).'.';
        if (EInvoiceService::enabled()) {
            $msg .= $cn->hasActiveIrn() ? ' Stamped by GRA E-VAT (refund).' : ' E-VAT: '.($cn->irn_error ?? 'not submitted yet').'.';
        }

        return redirect()->route('folios.show', $cn)->with($cn->irn_status === 'failed' ? 'warning' : 'success', $msg);
    }
}
