<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** v5.7 A cheque leaf written (for a supplier payment, or standalone with its own journal), spoilt, stopped or gone stale. */
class ChequeIssued extends Model
{
    use BelongsToHotel;

    protected $table = 'cheques_issued';

    public const STATUSES = ['issued' => 'Issued – not presented', 'cleared' => 'Cleared', 'void' => 'Void / spoilt', 'stopped' => 'Stopped', 'stale' => 'Stale'];

    public const TONES = ['issued' => 'amber', 'cleared' => 'green', 'void' => 'slate', 'stopped' => 'red', 'stale' => 'red'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'cheque_date' => 'date', 'cleared_on' => 'date', 'voided_at' => 'datetime'];
    }

    public function book()
    {
        return $this->belongsTo(ChequeBook::class, 'cheque_book_id');
    }

    public function payment()
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    public function head()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class, 'gl_journal_id');
    }

    public function issuer()
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function staleOn(): Carbon
    {
        return $this->cheque_date->copy()->addMonthsNoOverflow(ChequeReceived::STALE_MONTHS);
    }
}
