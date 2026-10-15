<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class PayComponent extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const KINDS = ['earning' => 'Earning', 'deduction' => 'Deduction', 'employer' => 'Employer contribution'];

    public const CALCS = ['fixed' => 'Fixed amount', 'percent_basic' => '% of basic', 'percent_gross' => '% of gross', 'slab' => 'Slab on gross', 'tax' => 'PAYE (GRA monthly bands)', 'system' => 'System (calculated)'];

    /** Codes the payroll engine computes itself. */
    public const SYSTEM = ['BASIC', 'OT', 'OTH', 'BONUS', 'ADV', 'OT_TAX', 'BONUS_TAX'];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'cap_base' => 'decimal:2', 'gross_ceiling' => 'decimal:2', 'slabs' => 'array',
            'prorate' => 'boolean', 'taxable' => 'boolean', 'tax_relief' => 'boolean', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function expenseHead()
    {
        return $this->belongsTo(AccountHead::class, 'expense_head_id');
    }

    public function liabilityHead()
    {
        return $this->belongsTo(AccountHead::class, 'liability_head_id');
    }

    public function isSystem(): bool
    {
        return in_array($this->code, self::SYSTEM, true);
    }

    public function ruleLabel(?float $override = null): string
    {
        $v = $override ?? (float) $this->value;
        $pct = rtrim(rtrim(number_format($v, 2), '0'), '.').'%';

        return match ($this->calc) {
            'fixed' => money($v).($this->prorate && $this->kind === 'earning' ? ' (pro-rata)' : ''),
            'percent_basic' => $pct.' of basic'.($this->cap_base ? ' (max base '.money($this->cap_base).')' : '').($this->tax_relief ? ' · PAYE relief' : ''),
            'percent_gross' => $pct.' of gross'.($this->gross_ceiling ? ' while gross ≤ '.money($this->gross_ceiling) : ''),
            'slab' => collect($this->slabs ?? [])->map(fn ($s) => ($s[0] === null ? 'above' : '≤ '.number_format($s[0])).': '.number_format($s[1]))->implode(' · '),
            'tax' => 'GRA PAYE – the bands in force in the payroll month, on taxable pay less SSNIT and personal reliefs (non-resident 25%, casual worker 5% final, junior overtime 5% / 10%)',
            default => match ($this->code) { 'BASIC' => 'Employee monthly basic, pro-rata', 'OT' => 'Overtime hours from attendance',
                    'OTH' => 'Hours worked on a gazetted public holiday (Act 651 s. 77)', 'ADV' => 'Salary advance instalment',
                    'OT_TAX' => 'Final tax on a junior employee\'s overtime (GRA PAYE)',
                    'BONUS' => 'One-off bonus / ex-gratia payment entered on a payslip',
                    'BONUS_TAX' => 'Final tax on a bonus / ex-gratia payment: 5% up to 15% of the year\'s basic salary, the excess on the bands · non-resident 20% (GRA)',
                    default => 'Calculated' },
        };
    }
}
