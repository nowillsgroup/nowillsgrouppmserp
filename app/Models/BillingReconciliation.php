<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Non-posting, document-level audit workpaper for issued folio invoices and credit notes. */
class BillingReconciliation extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'finalised_at' => 'datetime',
            'reopened_at' => 'datetime',
            'refreshed_at' => 'datetime',
        ];
    }

    public function items()
    {
        return $this->hasMany(BillingReconciliationItem::class)->orderBy('document_date')->orderBy('document_no');
    }

    public function preparer()
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reopener()
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isFinalised(): bool
    {
        return $this->status === 'finalised';
    }

    public function label(): string
    {
        return $this->period_from->format('d M Y').' – '.$this->period_to->format('d M Y');
    }

    /** Totals retain credit notes as negative values in the net figures. */
    public function totals(): array
    {
        $items = $this->items;
        $invoices = $items->where('document_type', 'invoice');
        $credits = $items->where('document_type', 'credit_note');
        $payments = (float) $items->sum('cash_payments');
        $refunds = (float) $items->sum('cash_refunds');
        $city = (float) $invoices->sum('city_ledger_amount') - (float) $credits->sum('city_ledger_amount');

        return [
            'invoices' => round((float) $invoices->sum('document_amount'), 2),
            'credit_notes' => round((float) $credits->sum('document_amount'), 2),
            'net_billed' => round((float) $invoices->sum('document_amount') - (float) $credits->sum('document_amount'), 2),
            'cash_payments' => round($payments, 2),
            'cash_refunds' => round($refunds, 2),
            'net_cash' => round($payments - $refunds, 2),
            'city_ledger_net' => round($city, 2),
            'settled_payments' => round((float) $items->sum('settled_payments'), 2),
            'settled_refunds' => round((float) $items->sum('settled_refunds'), 2),
            'net_evidenced' => round((float) $items->sum('settled_payments') - (float) $items->sum('settled_refunds'), 2),
            'net_cash_variance' => round(($payments - $refunds) - ((float) $items->sum('settled_payments') - (float) $items->sum('settled_refunds')), 2),
            'pending' => $items->where('review_status', 'pending')->count(),
            'exceptions' => $items->where('review_status', 'exception')->count(),
            'not_applicable' => $items->where('review_status', 'not_applicable')->count(),
            'matched' => $items->where('review_status', 'matched')->count(),
            'documents' => $items->count(),
        ];
    }
}
