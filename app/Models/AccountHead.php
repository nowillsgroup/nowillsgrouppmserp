<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class AccountHead extends Model
{
    use BelongsToHotel;

    protected $table = 'account_heads';

    protected $guarded = ['id', 'hotel_id', 'system_key'];

    public const TYPES = ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expense'];

    protected static array $sysCache = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function vouchers()
    {
        return $this->hasMany(Voucher::class);
    }

    public function lines()
    {
        return $this->hasMany(GlLine::class);
    }

    /** Debit-natured accounts (assets, expenses) show debit balances as positive. */
    public function isDebitNature(): bool
    {
        return in_array($this->type, ['asset', 'expense']);
    }

    public function getLabelAttribute(): string
    {
        return trim(($this->code ? $this->code.' · ' : '').$this->name);
    }

    /** Id of a system account (cash, guest_ledger, tax_payable …) for the current hotel. */
    public static function sys(string $key): int
    {
        $hid = \App\Support\Tenant::id();
        if (! isset(static::$sysCache[$hid])) {
            static::$sysCache[$hid] = static::whereNotNull('system_key')->pluck('id', 'system_key')->all();
        }
        if (! isset(static::$sysCache[$hid][$key])) {
            throw new \RuntimeException("System account '{$key}' is missing – run php artisan pms:upgrade.");
        }

        return static::$sysCache[$hid][$key];
    }

    public static function flushSys(): void
    {
        static::$sysCache = [];
    }
}
