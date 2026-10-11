<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\GlLine;
use App\Models\GstCancelledDoc;
use App\Models\GstPeriod;
use App\Models\PaymentMode;
use App\Models\PosOrder;
use App\Models\Setting;
use App\Support\Numbering;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * v4.3 Ghana tax compliance (GRA) – replaces the v3.0 India GST module; class, route and column names are kept
 * for compatibility (buyer_gstin = buyer TIN, irn = E-VAT SDC ID, gst_periods = filed VAT returns).
 *
 *  - TIN validation: GRA TIN (P/C/G/Q/V + 10 digits) or Ghana Card PIN (GHA-123456789-0)
 *  - invoices show VAT 15%, NHIL 2.5%, GETFund 2.5% (GRA) and the 1% Tourism Levy (GTA) separately; B2B when the
 *    buyer has a TIN (TIN printed and sent to E-VAT), otherwise B2C
 *  - invoice immutability: same-day re-open only; an invoice stamped by GRA E-VAT can never be cancelled – a credit
 *    note (E-VAT "REFUND" referencing the original invoice) is the only correction
 *  - monthly VAT return worksheet (output VAT / NHIL / GETFund, credit notes, POS receipts, input tax from the
 *    ledger, net payable) + Tourism Levy return; filing a month locks its documents
 *
 * Deadlines: VAT return and payment by the last working day of the following month (GRA); Tourism Levy within
 * 30 days after the month of collection (GTA).
 */
class GstService
{
    public const DEFAULTS = [
        'gst_einvoice_enabled' => '0', 'gst_einvoice_driver' => 'sandbox', 'gst_einvoice_auto' => '1', 'gst_evat_pos' => '1',
        'gst_evat_url' => '', 'gst_evat_key' => '', 'gst_evat_username' => '', 'tax_prices_inclusive' => '0',
    ];

    /** TIN used on E-VAT for buyers without one (walk-in / cash customers). */
    public const CASH_CUSTOMER_TIN = 'C0000000000';

    public static function ensureDefaults(): void
    {
        foreach (self::DEFAULTS as $k => $v) {
            Setting::firstOrCreate(['key' => $k], ['value' => $v]);
        }
        Setting::$version++;
    }

    public static function isGhana(): bool
    {
        return in_array(strtolower(trim((string) (hotel()?->country ?? 'Ghana'))), ['ghana', 'gh', 'gha', '']);
    }

    // ------------------------------------------------------------------ TIN

    public static function normaliseTin(?string $tin): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string) $tin)));
    }

    /**
     * GRA Taxpayer Identification Number: 11 characters – P (individual), C (company), G (government), Q (other
     * organisation), V (VAT-only / non-resident) + 10 digits – or a Ghana Card PIN (GHA-XXXXXXXXX-X), which has been
     * the TIN of individuals since 2021.
     */
    public static function validTin(?string $tin): bool
    {
        $t = self::normaliseTin($tin);

        return (bool) (preg_match('/^[PCGQV]\d{10}$/', $t) || preg_match('/^GHA-\d{9}-\d$/', $t));
    }

    public static function hotelTin(): ?string
    {
        $t = self::normaliseTin(hotel()?->tax_number);

        return $t ?: null;
    }

    public static function tinType(?string $tin): string
    {
        $t = self::normaliseTin($tin);

        return match (true) {
            str_starts_with($t, 'GHA-') => 'Ghana Card PIN',
            ($t[0] ?? '') === 'P' => 'Individual TIN',
            ($t[0] ?? '') === 'C' => 'Company TIN',
            ($t[0] ?? '') === 'G' => 'Government TIN',
            ($t[0] ?? '') === 'V' => 'VAT / non-resident TIN',
            ($t[0] ?? '') === 'Q' => 'Organisation TIN',
            default => 'TIN',
        };
    }

    // ------------------------------------------------------------------ lines

    /**
     * Charge lines of a document grouped by charge category and combined rate (signed – negative on credit notes),
     * with the statutory breakdown: vat, nhil, getfund (GRA) and tl (Tourism Levy, GTA).
     */
    public static function lines(Folio $f): Collection
    {
        $cats = config('pms.charge_categories');

        return $f->activeTransactions()->where('type', 'charge')->get()
            ->groupBy(fn ($t) => $t->category.'|'.(float) $t->tax_rate)
            ->map(function ($g, $k) use ($cats) {
                [$cat, $rate] = explode('|', $k);
                $tax = round((float) $g->sum('tax_amount'), 2);
                $taxable = round((float) $g->sum('amount'), 2);
                $b = TaxService::breakdown($tax, $cat, (float) $rate);

                return ['category' => $cat, 'rate' => (float) $rate, 'desc' => $cats[$cat] ?? ucfirst($cat),
                    'taxable' => $taxable, 'vat' => $b['VAT'], 'nhil' => $b['NHIL'], 'getfund' => $b['GETFUND'], 'tl' => $b['TL'],
                    'tax' => $tax, 'total' => round($taxable + $tax, 2), 'count' => $g->count()];
            })->values();
    }

    /** Document totals by component: taxable, vat, nhil, getfund, tl, tax, total (signed). */
    public static function componentTotals(Collection $lines): array
    {
        $out = [];
        foreach (['taxable', 'vat', 'nhil', 'getfund', 'tl', 'tax', 'total'] as $k) {
            $out[$k] = round((float) $lines->sum($k), 2);
        }

        return $out;
    }

    // ------------------------------------------------------------------ issuing

    /** Buyer details frozen onto the document when the invoice is issued (B2B when the buyer has a valid TIN). */
    public static function snapshot(Folio $f): array
    {
        $c = $f->company;
        $g = $f->guest;
        $tin = null;
        $name = null;
        $addr = null;
        if ($c && self::validTin($c->tax_number)) {
            [$tin, $name, $addr] = [self::normaliseTin($c->tax_number), $c->name, $c->address];
        } elseif ($g && self::validTin($g->tax_number)) {
            [$tin, $name, $addr] = [self::normaliseTin($g->tax_number), $g->company?->name ?? $g->full_name, trim($g->address.' '.$g->city)];
        }

        return ['supply_type' => $tin ? 'B2B' : 'B2C', 'buyer_gstin' => $tin, 'buyer_name' => $name ?? ($c?->name ?? $g?->full_name),
            'buyer_address' => $addr ?? ($c?->address ?? ($g ? trim($g->address.' '.$g->city) : null)), 'buyer_state_code' => null, 'pos_state_code' => null];
    }

    /** Called when a folio is closed (invoice issued or re-issued the same day). */
    public static function onClose(Folio $f): void
    {
        $f->forceFill(['invoice_date' => $f->invoice_date ?? bdate()->toDateString()] + self::snapshot($f))->save();
        if (EInvoiceService::eligible($f) && setting('gst_einvoice_auto', '1') === '1') {
            EInvoiceService::generate($f);
        }
    }

    /** Why an issued invoice may NOT be re-opened for editing (null = allowed). */
    public static function reopenBlocker(Folio $f): ?string
    {
        if ($f->isCreditNote()) {
            return 'Credit notes cannot be re-opened – cancel the credit note instead.';
        }
        if (! $f->invoice_no) {
            return null;
        }
        if (GstPeriod::isFiled($f->invoice_date?->toDateString())) {
            return "The VAT return for {$f->invoice_date->format('M Y')} has been filed – issue a credit note.";
        }
        if ($f->hasActiveIrn()) {
            return "Invoice {$f->invoice_no} has been stamped by GRA E-VAT – issue a credit note (refund) to correct it.";
        }
        if ($f->invoice_date && $f->invoice_date->toDateString() < bdate()->toDateString()) {
            return "Invoice {$f->invoice_no} was issued on {$f->invoice_date->format('d M Y')} (a previous business day) – issue a credit note or cancel the invoice.";
        }

        return null;
    }

    /** Why an issued document may NOT be cancelled (null = allowed). */
    public static function cancelBlocker(Folio $f): ?string
    {
        if (! $f->invoice_no || ! in_array($f->status, ['closed'])) {
            return 'Only issued invoices / credit notes can be cancelled.';
        }
        if (GstPeriod::isFiled($f->invoice_date?->toDateString())) {
            return "The VAT return for {$f->invoice_date->format('M Y')} has been filed – issue a credit note instead.";
        }
        if ($f->hasActiveIrn()) {
            return 'This document has been stamped by GRA E-VAT and cannot be cancelled – issue a credit note (refund) instead.';
        }
        if (! $f->isCreditNote() && $f->creditNotes()->where('status', 'closed')->exists()) {
            return 'Credit notes have been issued against this invoice – cancel them first or issue another credit note.';
        }

        return null;
    }

    /**
     * Cancel an issued invoice / credit note that has NOT been stamped by E-VAT. The number is recorded as cancelled and never reused.
     * An invoice's folio re-opens (same charges and payments) and gets a new number when closed again;
     * a credit note's lines are voided.
     */
    public static function cancel(Folio $f, string $reasonCode, string $remarks, ?int $userId = null): void
    {
        if ($why = self::cancelBlocker($f)) {
            throw ValidationException::withMessages(['cancel' => $why]);
        }
        if (! isset(GstCancelledDoc::REASONS[$reasonCode])) {
            throw ValidationException::withMessages(['reason_code' => 'Choose a cancellation reason.']);
        }
        DB::transaction(function () use ($f, $reasonCode, $remarks, $userId) {
            $t = $f->gstTotals();
            GstCancelledDoc::create(['folio_id' => $f->id, 'doc_type' => $f->isCreditNote() ? 'credit_note' : 'invoice', 'doc_no' => $f->invoice_no,
                'doc_date' => ($f->invoice_date ?? bdate())->toDateString(), 'buyer_gstin' => $f->buyer_gstin, 'value' => abs($t['value']), 'irn' => $f->irn,
                'reason_code' => $reasonCode, 'remarks' => $remarks, 'user_id' => $userId]);
            $old = $f->invoice_no;
            $reset = ['irn' => null, 'irn_status' => null, 'irn_ack_no' => null, 'irn_ack_at' => null, 'irn_qr' => null, 'evat_intdata' => null, 'evat_signature' => null,
                'irn_error' => null, 'irn_attempts' => 0, 'irn_next_try_at' => null, 'irn_retryable' => true];
            if ($f->isCreditNote()) {
                $f->activeTransactions()->update(['is_void' => true, 'void_reason' => "Credit note {$old} cancelled: {$remarks}"]);
                $f->forceFill(['status' => 'cancelled', 'irn_next_try_at' => null, 'irn_retryable' => false])->save();
            } else {
                $f->forceFill(['status' => 'open', 'invoice_no' => null, 'invoice_date' => null, 'closed_at' => null] + $reset)->save();
            }
            ActivityLogger::log('gst', 'cancel', ($f->isCreditNote() ? 'Credit note ' : 'Invoice ')."{$old} cancelled – ".GstCancelledDoc::REASONS[$reasonCode].": {$remarks}", $f);
        });
    }

    // ------------------------------------------------------------------ credit notes

    /** Charge lines of an invoice with what is still creditable. */
    public static function creditable(Folio $inv): Collection
    {
        $credited = FolioTransaction::where('source_type', 'credit_note')->where('is_void', false)
            ->whereIn('source_id', $inv->activeTransactions()->where('type', 'charge')->pluck('id'))
            ->selectRaw('source_id, SUM(-amount) a, SUM(-tax_amount) t')->groupBy('source_id')->get()->keyBy('source_id');

        return $inv->activeTransactions()->where('type', 'charge')->orderBy('business_date')->orderBy('id')->get()->map(function ($t) use ($credited) {
            $done = (float) ($credited[$t->id]->a ?? 0);
            $t->credited = round($done, 2);
            $t->creditable = round((float) $t->amount - $done, 2);
            $t->cat_label = config('pms.charge_categories')[$t->category] ?? ucfirst((string) $t->category);

            return $t;
        })->filter(fn ($t) => (float) $t->amount > 0);
    }

    /**
     * Issue a credit note against an invoice. $amounts: [folio_transaction_id => taxable amount to credit].
     * $settle: 'refund' (with payment_mode_id) or 'city_ledger' (reduces the company's receivable).
     */
    public static function issueCreditNote(Folio $inv, array $amounts, string $reason, ?string $remarks, string $settle, ?int $modeId, ?int $userId = null): Folio
    {
        if (! $inv->isInvoiced() || $inv->isCreditNote()) {
            throw ValidationException::withMessages(['credit' => 'Credit notes can only be issued against an issued invoice.']);
        }
        if (! isset(Folio::NOTE_REASONS[$reason])) {
            throw ValidationException::withMessages(['note_reason' => 'Choose a reason.']);
        }
        if (GstPeriod::isFiled(bdate()->toDateString())) {
            throw ValidationException::withMessages(['credit' => 'The VAT return for the current month is already filed.']);
        }
        $lines = self::creditable($inv)->keyBy('id');
        $rows = [];
        foreach ($amounts as $txId => $amt) {
            $amt = round((float) $amt, 2);
            if ($amt <= 0) {
                continue;
            }
            $t = $lines[$txId] ?? null;
            if (! $t) {
                throw ValidationException::withMessages(['amounts' => 'A selected line does not belong to this invoice.']);
            }
            if ($amt > $t->creditable + 0.001) {
                throw ValidationException::withMessages(['amounts' => "'{$t->description}': at most ".money($t->creditable).' can still be credited.']);
            }
            $tax = ($t->credited == 0 && abs($amt - (float) $t->amount) < 0.001) ? (float) $t->tax_amount : round($amt * (float) $t->tax_rate / 100, 2);
            $rows[] = [$t, $amt, $tax];
        }
        if (! $rows) {
            throw ValidationException::withMessages(['amounts' => 'Enter the amount to credit on at least one line.']);
        }
        $total = round(collect($rows)->sum(fn ($r) => $r[1] + $r[2]), 2);
        $cl = null;
        if ($settle === 'city_ledger') {
            $cl = $inv->activeTransactions()->where('type', 'payment')->where('category', 'city_ledger')->whereNotNull('company_id')->orderByDesc('amount')->first();
            if (! $cl) {
                throw ValidationException::withMessages(['settle' => 'This invoice was not billed to a company account – refund the guest instead.']);
            }
            $billed = (float) $inv->activeTransactions()->where('type', 'payment')->where('category', 'city_ledger')->where('company_id', $cl->company_id)->sum('amount');
            $already = -(float) FolioTransaction::whereIn('folio_id', $inv->creditNotes()->where('status', 'closed')->pluck('id'))->where('is_void', false)
                ->where('type', 'payment')->where('category', 'city_ledger')->sum('amount');
            if ($total > $billed - $already + 0.001) {
                throw ValidationException::withMessages(['settle' => 'Only '.money($billed - $already).' of this invoice is on the company account.']);
            }
        } elseif ($settle === 'refund') {
            $mode = PaymentMode::find($modeId);
            if (! $mode || in_array($mode->type, ['city_ledger', 'online', 'gift_voucher'])) {
                throw ValidationException::withMessages(['payment_mode_id' => 'Choose how the refund is paid (cash, card, mobile money or bank).']);
            }
            try {
                ShiftService::assertOpen(); // refunds come out of the cashier's drawer when shifts are mandatory
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['payment_mode_id' => $e->getMessage()]);
            }
        } else {
            throw ValidationException::withMessages(['settle' => 'Choose how the credit is settled.']);
        }

        $cn = DB::transaction(function () use ($inv, $rows, $reason, $remarks, $settle, $modeId, $cl, $total, $userId) {
            $no = Numbering::next('credit_note');
            $cn = new Folio;
            $cn->forceFill(['folio_no' => $no, 'invoice_no' => $no, 'type' => 'credit_note', 'status' => 'closed', 'invoice_date' => bdate()->toDateString(),
                'closed_at' => now(), 'closed_by' => $userId, 'original_folio_id' => $inv->id, 'note_reason' => $reason, 'note_remarks' => $remarks,
                'reservation_id' => $inv->reservation_id, 'reservation_room_id' => $inv->reservation_room_id, 'guest_id' => $inv->guest_id, 'company_id' => $inv->company_id,
            ] + collect($inv->only(['supply_type', 'buyer_gstin', 'buyer_name', 'buyer_address', 'buyer_state_code', 'pos_state_code']))->map(fn ($v) => $v)->all())->save();
            foreach ($rows as [$t, $amt, $tax]) {
                $cn->transactions()->create(['business_date' => bdate()->toDateString(), 'type' => 'charge', 'category' => $t->category,
                    'description' => 'Credit – '.$t->description, 'amount' => -$amt, 'tax_amount' => -$tax, 'tax_rate' => $t->tax_rate,
                    'source_type' => 'credit_note', 'source_id' => $t->id, 'posted_by' => $userId, 'reference' => $inv->invoice_no]);
            }
            if ($settle === 'refund') {
                $cn->transactions()->create(['business_date' => bdate()->toDateString(), 'type' => 'refund', 'category' => 'settlement', 'description' => "Refund – credit note {$no}",
                    'amount' => $total, 'payment_mode_id' => $modeId, 'posted_by' => $userId, 'reference' => $inv->invoice_no]);
            } else {
                $cn->transactions()->create(['business_date' => bdate()->toDateString(), 'type' => 'payment', 'category' => 'city_ledger', 'description' => "Credit to company account – {$no}",
                    'amount' => -$total, 'company_id' => $cl->company_id, 'payment_mode_id' => $cl->payment_mode_id, 'posted_by' => $userId, 'reference' => $inv->invoice_no]);
            }
            ActivityLogger::log('gst', 'credit_note', "Credit note {$no} for ".money($total)." against {$inv->invoice_no} – ".Folio::NOTE_REASONS[$reason], $cn);

            return $cn;
        });
        if (EInvoiceService::eligible($cn) && ($inv->hasActiveIrn() || setting('gst_einvoice_auto', '1') === '1')) { // E-VAT "REFUND" referencing the invoice
            EInvoiceService::generate($cn->fresh(), $userId);
        }

        return $cn->fresh();
    }

    // ------------------------------------------------------------------ VAT return

    protected static function monthRange(string $period): array
    {
        $s = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();

        return [$s->toDateString(), $s->copy()->endOfMonth()->toDateString()];
    }

    /** Issued (not cancelled) invoices and credit notes dated in the month. */
    public static function documents(string $period): Collection
    {
        [$from, $to] = self::monthRange($period);

        return Folio::with('original:id,invoice_no,invoice_date')->where('status', 'closed')->whereNotNull('invoice_no')
            ->whereBetween('invoice_date', [$from, $to])->orderBy('invoice_date')->orderBy('id')->get();
    }

    /** Direct-settled POS bills of the month split by tax rate (B2C receipts). */
    public static function posLines(string $period): Collection
    {
        return self::posLinesBetween(...self::monthRange($period));
    }

    public static function posLinesBetween(string $from, string $to): Collection
    {
        $out = collect();
        foreach (PosOrder::with('items')->where('status', 'settled')->where('settlement', 'payment')->whereBetween('business_date', [$from, $to])->get() as $o) {
            $items = $o->items->where('is_cancelled', false);
            $sub = (float) $items->sum('amount');
            $weighted = (float) $items->sum(fn ($i) => $i->amount * $i->tax_rate);
            if ($sub <= 0) {
                continue;
            }
            foreach ($items->groupBy(fn ($i) => (float) $i->tax_rate) as $rate => $g) {
                $gross = (float) $g->sum('amount');
                $taxable = round(($sub - (float) $o->discount) * $gross / $sub, 2);
                $tax = $weighted > 0 ? round((float) $o->tax * ($gross * $rate) / $weighted, 2) : 0.0;
                $b = TaxService::breakdown($tax, 'fnb', (float) $rate);
                $out->push(['bill' => $o->bill_no, 'rate' => (float) $rate, 'taxable' => $taxable, 'tax' => $tax,
                    'vat' => $b['VAT'], 'nhil' => $b['NHIL'], 'getfund' => $b['GETFUND'], 'tl' => $b['TL']]);
            }
        }

        return $out;
    }

    /** Input VAT / NHIL / GETFund claimed in the month – debits less credits on the input-tax account (purchases, supplier bills). */
    public static function inputTax(string $period): float
    {
        [$from, $to] = self::monthRange($period);
        $acct = AccountHead::sys('input_tax');

        return $acct ? round((float) GlLine::where('account_head_id', $acct)->where('closing', false)->whereBetween('date', [$from, $to])->sum(DB::raw('debit - credit')), 2) : 0.0;
    }

    /**
     * Monthly VAT return worksheet (GRA) and Tourism Levy return (GTA).
     *  sales   – register of invoices / credit notes (B2B with buyer TIN, B2C) with the component split
     *  rows    – return lines: invoices, credit notes, POS receipts → output VAT, NHIL, GETFund; Tourism Levy
     *  totals  – output (GRA), input, net payable to GRA, tourism levy payable to GTA
     */
    public static function vatReturn(string $period): array
    {
        $sales = [];
        $sum = fn () => ['count' => 0, 'taxable' => 0.0, 'vat' => 0.0, 'nhil' => 0.0, 'getfund' => 0.0, 'tl' => 0.0, 'total' => 0.0];
        $rows = ['b2b' => $sum(), 'b2c' => $sum(), 'credit' => $sum(), 'pos' => $sum()];
        $add = function (string $row, array $t) use (&$rows) {
            $rows[$row]['count']++;
            foreach (['taxable', 'vat', 'nhil', 'getfund', 'tl', 'total'] as $k) {
                $rows[$row][$k] = round($rows[$row][$k] + $t[$k], 2);
            }
        };
        foreach (self::documents($period) as $d) {
            $t = self::componentTotals(self::lines($d));
            $row = $d->isCreditNote() ? 'credit' : ($d->supply_type === 'B2B' ? 'b2b' : 'b2c');
            $add($row, $t);
            $sales[] = ['folio_id' => $d->id, 'no' => $d->invoice_no, 'date' => $d->invoice_date->toDateString(), 'type' => $d->isCreditNote() ? 'Credit note' : 'Invoice',
                'supply' => $d->supply_type ?: 'B2C', 'tin' => $d->buyer_gstin, 'name' => $d->buyer_name, 'against' => $d->original?->invoice_no,
                'sdc' => $d->hasActiveIrn() ? $d->irn : null, 'evat_status' => $d->irn_status] + $t;
        }
        foreach (self::posLines($period) as $p) {
            $add('pos', $p + ['total' => round($p['taxable'] + $p['tax'], 2)]);
        }
        $rows['pos']['count'] = self::posLines($period)->pluck('bill')->unique()->count();
        $out = $sum();
        foreach ($rows as $r) {
            foreach (['taxable', 'vat', 'nhil', 'getfund', 'tl', 'total'] as $k) {
                $out[$k] = round($out[$k] + $r[$k], 2); // credit notes are already negative
            }
        }
        $gra = round($out['vat'] + $out['nhil'] + $out['getfund'], 2);
        $input = self::inputTax($period);
        $totals = ['taxable' => $out['taxable'], 'vat' => $out['vat'], 'nhil' => $out['nhil'], 'getfund' => $out['getfund'], 'output_gra' => $gra,
            'input' => $input, 'net_gra' => round($gra - $input, 2), 'tl_base' => $out['taxable'], 'tl' => $out['tl'], 'count' => count($sales)];

        return ['period' => $period, 'sales' => $sales, 'rows' => $rows, 'totals' => $totals, 'docs' => self::docSummary($period),
            'filed' => GstPeriod::where('period', $period)->first(), 'due' => self::returnDue($period), 'tl_due' => self::levyDue($period)];
    }

    /** GRA: VAT return and payment by the last working day of the month after the period. */
    public static function returnDue(string $period): Carbon
    {
        $d = Carbon::createFromFormat('Y-m-d', $period.'-01')->addMonthNoOverflow()->endOfMonth()->startOfDay();
        while ($d->isWeekend()) {
            $d->subDay();
        }

        return $d;
    }

    /** GTA: Tourism Levy remitted within 30 days after the month of collection. */
    public static function levyDue(string $period): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $period.'-01')->endOfMonth()->startOfDay()->addDays(30);
    }

    /** Document series issued in the month, with cancellations and gaps (numbering audit). */
    public static function docSummary(string $period): array
    {
        [$from, $to] = self::monthRange($period);
        $num = fn ($no) => (int) preg_replace('/\D/', '', (string) $no);
        $series = function (string $label, Collection $issued, Collection $cancelled) use ($num) {
            $all = $issued->concat($cancelled)->filter()->unique()->sortBy($num)->values();
            if ($all->isEmpty()) {
                return null;
            }
            $gaps = [];
            $nums = $all->map($num)->all();
            $prefix = preg_replace('/\d+$/', '', (string) $all->first());
            $width = strlen((string) preg_replace('/^\D+/', '', (string) $all->first()));
            for ($n = min($nums) + 1; $n < max($nums); $n++) {
                if (! in_array($n, $nums)) {
                    $gaps[] = $prefix.str_pad((string) $n, $width, '0', STR_PAD_LEFT);
                }
            }

            return ['label' => $label, 'from' => $all->first(), 'to' => $all->last(), 'total' => $all->count(), 'cancelled' => $cancelled->unique()->count(),
                'net' => $all->count() - $cancelled->unique()->count(), 'gaps' => $gaps];
        };
        $cx = GstCancelledDoc::whereBetween('doc_date', [$from, $to])->get();

        return array_values(array_filter([
            $series('Tax invoices', Folio::where('type', '!=', 'credit_note')->whereNotNull('invoice_no')->where('status', 'closed')->whereBetween('invoice_date', [$from, $to])->pluck('invoice_no'),
                $cx->where('doc_type', 'invoice')->pluck('doc_no')),
            $series('Restaurant receipts (POS)', PosOrder::whereNotNull('bill_no')->where('status', '!=', 'void')->whereBetween('business_date', [$from, $to])->pluck('bill_no'),
                PosOrder::whereNotNull('bill_no')->where('status', 'void')->whereBetween('business_date', [$from, $to])->pluck('bill_no')),
            $series('Credit notes', Folio::where('type', 'credit_note')->where('status', 'closed')->whereBetween('invoice_date', [$from, $to])->pluck('invoice_no'),
                $cx->where('doc_type', 'credit_note')->pluck('doc_no')),
        ]));
    }

    /** Sales register rows for the CSV export (schedule attached to the VAT return on the GRA Taxpayers' Portal). */
    public static function exportRows(string $period): array
    {
        $r = self::vatReturn($period);
        $rows = [['Document', 'Number', 'Date', 'Supply', 'Buyer TIN', 'Buyer', 'Against', 'Taxable value', 'VAT', 'NHIL', 'GETFund', 'Tourism levy', 'Total', 'E-VAT SDC ID']];
        foreach ($r['sales'] as $s) {
            $rows[] = [$s['type'], $s['no'], $s['date'], $s['supply'], $s['tin'], $s['name'], $s['against'], $s['taxable'], $s['vat'], $s['nhil'], $s['getfund'], $s['tl'], $s['total'], $s['sdc']];
        }
        $p = $r['rows']['pos'];
        if ($p['count']) {
            $rows[] = ['POS receipts', $p['count'].' bills', $period, 'B2C', null, 'Restaurant & bar', null, $p['taxable'], $p['vat'], $p['nhil'], $p['getfund'], $p['tl'], $p['total'], null];
        }
        $t = $r['totals'];
        $rows[] = [];
        $rows[] = ['Output VAT', null, null, null, null, null, null, $t['taxable'], $t['vat']];
        $rows[] = ['Output NHIL', null, null, null, null, null, null, null, null, $t['nhil']];
        $rows[] = ['Output GETFund', null, null, null, null, null, null, null, null, null, $t['getfund']];
        $rows[] = ['Input tax (VAT + NHIL + GETFund)', null, null, null, null, null, null, null, $t['input']];
        $rows[] = ['Net payable to GRA', null, null, null, null, null, null, null, $t['net_gra']];
        $rows[] = ['Tourism levy payable to GTA', null, null, null, null, null, null, $t['tl_base'], null, null, null, $t['tl']];

        return $rows;
    }

    /** Pre-filing checks. 'block' => true prevents marking the period filed. */
    public static function checks(string $period, ?array $r = null): array
    {
        $r ??= self::vatReturn($period);
        $out = [];
        if (! self::validTin(self::hotelTin())) {
            $out[] = ['block' => true, 'text' => 'The hotel TIN is missing or invalid – set it in Administration › Hotel settings (GRA TIN, e.g. C0012345678).'];
        }
        if (EInvoiceService::enabled()) {
            foreach ($r['sales'] as $s) {
                if ($s['evat_status'] !== 'generated') {
                    $out[] = ['block' => true, 'folio_id' => $s['folio_id'], 'text' => "{$s['no']} ({$s['name']}) has not been stamped by GRA E-VAT – submit it before filing."];
                }
            }
        }
        if ($n = PosEvatService::unstamped(...self::monthRange($period))) { // v4.4
            $out[] = ['block' => true, 'pos' => true, 'text' => "{$n} restaurant bill(s) settled at the outlet in this month have not been stamped by GRA E-VAT – retry them on the E-VAT screen (Restaurant bills) before filing."];
        }
        foreach ($r['docs'] as $s) {
            if ($s['gaps']) {
                $out[] = ['block' => false, 'text' => "{$s['label']}: number(s) missing from the series – ".implode(', ', array_slice($s['gaps'], 0, 8)).(count($s['gaps']) > 8 ? '…' : '').'. Check they were issued in another month.'];
            }
        }
        $prev = Carbon::createFromFormat('Y-m-d', $period.'-01')->subMonth()->format('Y-m');
        if (! GstPeriod::where('period', $prev)->exists() && Folio::whereNotNull('invoice_no')->where('invoice_date', '<', $period.'-01')->exists()) {
            $out[] = ['block' => false, 'text' => 'The VAT return for '.Carbon::parse($prev.'-01')->format('M Y').' is not marked as filed.'];
        }
        $open = Folio::where('status', 'open')->whereNotNull('company_id')->whereHas('transactions', fn ($q) => $q->where('is_void', false)->where('type', 'charge')
            ->where('business_date', '<=', self::monthRange($period)[1]))->where('type', '!=', 'credit_note')->count();
        if ($open && $period < bdate()->format('Y-m')) {
            $out[] = ['block' => false, 'text' => "{$open} company folio(s) with charges up to the month end are still open – invoices are dated when issued, so they belong to a later return."];
        }

        return $out;
    }

    /** Mark a month's VAT return as filed on the GRA Taxpayers' Portal ($ref = GRA acknowledgement / reference number). */
    public static function file(string $period, string $ref, string $filedOn, ?int $userId = null): GstPeriod
    {
        if ($period >= bdate()->format('Y-m')) {
            throw ValidationException::withMessages(['period' => 'A month can only be marked as filed after it has ended.']);
        }
        if (GstPeriod::where('period', $period)->exists()) {
            throw ValidationException::withMessages(['period' => 'This period is already marked as filed.']);
        }
        $r = self::vatReturn($period);
        if ($block = collect(self::checks($period, $r))->firstWhere('block', true)) {
            throw ValidationException::withMessages(['period' => $block['text']]);
        }
        $p = GstPeriod::create(['period' => $period, 'arn' => strtoupper($ref), 'filed_on' => $filedOn, 'filed_by' => $userId,
            'summary' => $r['totals'] + ['rows' => $r['rows'], 'docs' => collect($r['docs'])->map(fn ($s) => collect($s)->except('gaps'))->all()]]);
        ActivityLogger::log('gst', 'file', 'VAT return for '.Carbon::parse($period.'-01')->format('M Y')." marked as filed (GRA ref {$p->arn})", $p);

        return $p;
    }

    // ------------------------------------------------------------------ dashboard

    public static function alerts(): array
    {
        $prev = bdate()->copy()->startOfMonth()->subMonth();
        $unfiled = ! GstPeriod::where('period', $prev->format('Y-m'))->exists() && Folio::whereNotNull('invoice_no')->whereBetween('invoice_date', [$prev->toDateString(), $prev->copy()->endOfMonth()->toDateString()])->exists();
        $due = self::returnDue($prev->format('Y-m'));

        return ['failed' => Folio::where('irn_status', 'failed')->where('status', 'closed')->count(), 'pending' => Folio::where('irn_status', 'pending')->where('status', 'closed')->count(),
            'pos_failed' => PosEvatService::failedCount(),
            'return_period' => $unfiled ? $prev->format('Y-m') : null, 'return_due' => $due, 'return_overdue' => $unfiled && bdate()->gt($due)];
    }

    public static function attentionCount(): int
    {
        $a = self::alerts();

        return $a['failed'] + $a['pending'] + $a['pos_failed'] + ($a['return_overdue'] ? 1 : 0);
    }

    /** Existing installs: date and snapshot invoices issued before v3.0. */
    public static function backfill(): int
    {
        $n = 0;
        Folio::whereNotNull('invoice_no')->whereNull('invoice_date')->with('company', 'guest')->chunkById(200, function ($fs) use (&$n) {
            foreach ($fs as $f) {
                $date = $f->closed_at?->toDateString() ?? $f->activeTransactions()->max('business_date') ?? $f->updated_at->toDateString();
                $f->forceFill(['invoice_date' => $date] + self::snapshot($f))->save();
                $n++;
            }
        });

        return $n;
    }
}
