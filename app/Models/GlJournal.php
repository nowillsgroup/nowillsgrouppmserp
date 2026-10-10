<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class GlJournal extends Model
{
    use BelongsToHotel;

    protected $table = 'gl_journals';

    protected $guarded = ['id', 'hotel_id'];

    public const SOURCES = ['folio_tx' => 'Folio', 'pos' => 'POS bill', 'voucher' => 'Voucher', 'po' => 'Goods receipt', 'grn' => 'Goods receipt (GRN)', 'stock' => 'Stores', 'ap_inv' => 'Supplier bill', 'ap_pay' => 'Supplier payment', 'payroll' => 'Payroll', 'payroll_pay' => 'Salary payment', 'advance' => 'Salary advance', 'gw_payout' => 'Gateway payout', 'asset' => 'Asset purchase', 'asset_dep' => 'Depreciation', 'asset_disp' => 'Asset disposal', 'forex' => 'Currency exchange', 'fx_surr' => 'Forex surrender', 'gv' => 'Gift voucher', 'stat_remit' => 'Statutory remittance', 'bank_rec' => 'Bank reconciliation entry', 'petty' => 'Petty cash', 'exp_claim' => 'Expense claim', 'exp_claim_pay' => 'Expense claim payment', 'tip' => 'Staff tips', 'sc_dist' => 'Service charge distribution', 'club_chg' => 'Club charge', 'club_pay' => 'Club receipt', 'club_dp' => 'Club day pass', 'club_recog' => 'Club revenue recognition', 'lease_chg' => 'Lease charge', 'lease_pay' => 'Lease receipt', 'pkg_day' => 'Package allocation', 'accrual' => 'Accrual / prepayment schedule', 'recurring' => 'Recurring journal', 'ye_close' => 'Year-end closing', 'treasury' => 'Treasury transfer', 'cheque_in' => 'Cheque received', 'cheque_out' => 'Cheque issued', 'owner_fee' => 'Management fees', 'owner_fee_pay' => 'Management fee payment', 'concierge' => 'Concierge commission', 'concierge_settle' => 'Activity vendor settlement', 'manual' => 'Manual journal'];

    protected function casts(): array
    {
        return ['date' => 'date', 'total' => 'decimal:2', 'is_manual' => 'boolean', 'is_closing' => 'boolean'];
    }

    public function lines()
    {
        return $this->hasMany(GlLine::class)->orderByDesc('debit')->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversedBy()
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    /** Link to the source document, when there is one. */
    public function sourceUrl(): ?string
    {
        $k = $this->source_key;

        return match ($this->source_type) {
            'folio_tx' => ($f = FolioTransaction::find($k)?->folio_id) ? route('folios.show', $f) : null,
            'pos' => route('pos.terminal', $k),
            'po' => route('purchases.show', $k),
            'grn' => ($g = \App\Models\GoodsReceipt::find($k)) ? route('purchases.show', $g->purchase_order_id) : null,
            'stock' => route('inventory.ledger', ['from' => $k, 'to' => $k]),
            'voucher' => ($d = Voucher::whereKey($k)->value('date')) ? route('accounts.vouchers', ['from' => Carbon::parse($d)->toDateString(), 'to' => Carbon::parse($d)->toDateString()]) : null,
            'payroll', 'payroll_pay' => route('payroll.show', $k),
            'advance' => route('payroll.advances'),
            'gw_payout' => route('payments.payouts'),
            'asset', 'asset_disp' => \App\Models\Asset::whereKey($k)->exists() ? route('assets.show', $k) : null,
            'asset_dep' => route('assets.depreciation.show', $k),
            'accrual' => \App\Models\Accrual::whereKey((int) $k)->exists() ? route('close.accruals.show', (int) $k) : null, // v5.6
            'recurring' => route('close.recurring', ['open' => (int) $k]),
            'ye_close' => route('close.yearend', ['fy' => (int) $k]),
            'treasury' => \App\Models\TreasuryTransfer::whereKey((int) $k)->exists() ? route('treasury.transfers.show', (int) $k) : null, // v5.7
            'cheque_in' => route('treasury.cheques', ['tab' => 'received', 'open' => (int) $k]),
            'cheque_out' => route('treasury.cheques', ['tab' => 'issued', 'open' => (int) $k]),
            'owner_fee', 'owner_fee_pay' => route('owner.fees'), // v5.8
            'stat_remit' => ($r = \App\Models\StatutoryRemittance::find($k)) ? route('payroll.statutory', ['period' => $r->period, 'tab' => $r->kind]) : null,
            'bank_rec' => ($l = \App\Models\BankStatementLine::find($k)) ? route('bankrec.show', $l->bank_statement_id) : null,
            'petty' => ($t = \App\Models\PettyTxn::find($k)) ? route('petty.show', $t->petty_float_id) : null,
            'tip' => route('sc.index'), // v4.9
            'sc_dist' => route('sc.show', (int) $k),
            'club_chg' => ($c = \App\Models\ClubCharge::find($k)) ? route('club.members.show', $c->club_membership_id) : null, // v5.1
            'club_pay' => ($p = \App\Models\ClubPayment::find($k)) ? route('club.members.show', $p->club_membership_id) : null,
            'club_dp' => route('club.passes'),
            'club_recog' => route('club.billing'),
            'lease_chg' => ($c = \App\Models\LeaseCharge::find($k)) ? route('leases.show', $c->lease_id) : null, // v5.2
            'lease_pay' => ($p = \App\Models\LeasePayment::find($k)) ? route('leases.show', $p->lease_id) : null,
            'exp_claim', 'exp_claim_pay' => \App\Models\ExpenseClaim::find($k) ? route('claims.show', $k) : null,
            default => null,
        };
    }
}
