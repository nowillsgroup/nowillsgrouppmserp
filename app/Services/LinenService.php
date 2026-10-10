<?php

namespace App\Services;

use App\Models\AccountHead;
use App\Models\LinenBatch;
use App\Models\LinenBatchLine;
use App\Models\LinenMovement;
use App\Models\LinenType;
use App\Models\Room;
use App\Models\Supplier;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v2.2 house-linen control. Every piece is always in exactly one place – linen room (clean), floors, soiled or at the
 * laundry – and every change is a movement: purchase → store, issue store → floor, collect floor → soiled,
 * send soiled → laundry (in a batch), return laundry → store, discard / lost (out of stock, valued at unit cost),
 * adjust (physical count). Batches to an external laundry are billed through accounts payable (6130 Laundry & Linen).
 */
class LinenService
{
    /** type => [from, to] */
    public const FLOWS = [
        'purchase' => [null, 'store'], 'issue' => ['store', 'floor'], 'collect' => ['floor', 'soiled'],
        'send' => ['soiled', 'laundry'], 'return' => ['laundry', 'store'], 'discard' => ['*', null], 'lost' => ['*', null],
    ];

    /** Manual moves offered on the stock screen (batches handle send / return). */
    public const MANUAL = ['purchase', 'issue', 'collect', 'discard', 'lost'];

    protected static function col(string $loc): string
    {
        if (! array_key_exists($loc, LinenType::LOCATIONS)) {
            throw new RuntimeException('Unknown linen location.');
        }

        return 'qty_'.$loc;
    }

    /**
     * Record one movement and update the location counts (row-locked).
     * Options: from (for discard / lost), date, batch, reason, value (purchase cost override).
     */
    public static function move(LinenType $t, string $type, int $qty, array $o = []): LinenMovement
    {
        if (! isset(self::FLOWS[$type])) {
            throw new RuntimeException('Unknown linen movement.');
        }
        if ($qty <= 0) {
            throw new RuntimeException('Quantity must be at least 1.');
        }
        [$from, $to] = self::FLOWS[$type];
        if ($from === '*') {
            $from = $o['from'] ?? null;
            self::col((string) $from);
            if (! trim((string) ($o['reason'] ?? ''))) {
                throw new RuntimeException('Give a reason for writing linen off.');
            }
        }

        return DB::transaction(function () use ($t, $type, $qty, $o, $from, $to) {
            $t = LinenType::lockForUpdate()->findOrFail($t->id);
            if ($from && $t->{self::col($from)} < $qty) {
                throw new RuntimeException("Only {$t->{self::col($from)}} {$t->name} ".strtolower(LinenType::LOCATIONS[$from]).' – can\'t move '.$qty.'.');
            }
            if ($from) {
                $t->decrement(self::col($from), $qty);
            }
            if ($to) {
                $t->increment(self::col($to), $qty);
            }
            $value = in_array($type, ['purchase', 'discard', 'lost']) ? round(($type === 'purchase' && isset($o['value']) ? (float) $o['value'] / max(1, $qty) : (float) $t->unit_cost) * $qty, 2) : 0;

            return LinenMovement::create(['linen_type_id' => $t->id, 'date' => $o['date'] ?? bdate()->toDateString(), 'type' => $type, 'qty' => $qty,
                'from_loc' => $from, 'to_loc' => $to, 'linen_batch_id' => $o['batch'] ?? null, 'value' => $value,
                'reason' => ! empty($o['reason']) ? mb_substr(trim($o['reason']), 0, 190) : null, 'user_id' => auth()->id()]);
        });
    }

    /** Physical count of one location: the difference is booked as an adjustment (reason required). */
    public static function count(LinenType $t, string $loc, int $counted, string $reason): ?LinenMovement
    {
        $col = self::col($loc);
        if ($counted < 0) {
            throw new RuntimeException('The count can\'t be negative.');
        }
        if (! trim($reason)) {
            throw new RuntimeException('Give a reason for the count adjustment.');
        }

        return DB::transaction(function () use ($t, $loc, $col, $counted, $reason) {
            $t = LinenType::lockForUpdate()->findOrFail($t->id);
            $diff = $counted - (int) $t->{$col};
            if ($diff === 0) {
                return null;
            }
            $t->forceFill([$col => $counted])->save();

            return LinenMovement::create(['linen_type_id' => $t->id, 'date' => bdate()->toDateString(), 'type' => 'adjust', 'qty' => $diff,
                'from_loc' => $diff < 0 ? $loc : null, 'to_loc' => $diff > 0 ? $loc : null, 'value' => round($diff * (float) $t->unit_cost, 2),
                'reason' => mb_substr(trim($reason), 0, 190), 'user_id' => auth()->id()]);
        });
    }

    /**
     * Send soiled linen to the laundry. lines: linen_type_id => qty. When fewer pieces are marked soiled than are
     * being sent, the difference is collected from the floors first (recorded as a collect movement).
     */
    public static function send(array $d, array $lines): LinenBatch
    {
        $lines = array_filter(array_map('intval', $lines), fn ($q) => $q > 0);
        if (! $lines) {
            throw new RuntimeException('Enter how many pieces of each type are going to the laundry.');
        }
        $supplier = ! empty($d['supplier_id']) ? Supplier::find($d['supplier_id']) : null;
        $date = $d['sent_date'] ?? bdate()->toDateString();
        if ($date > bdate()->toDateString()) {
            throw new RuntimeException('The send date can\'t be in the future.');
        }
        if (! empty($d['expected_date']) && $d['expected_date'] < $date) {
            throw new RuntimeException('The expected return date is before the send date.');
        }

        $batch = DB::transaction(function () use ($d, $lines, $supplier, $date) {
            $b = LinenBatch::create(['batch_no' => Numbering::next('linen_batch'), 'supplier_id' => $supplier?->id, 'sent_date' => $date,
                'expected_date' => $d['expected_date'] ?? null, 'status' => 'sent', 'notes' => $d['notes'] ?? null, 'created_by' => auth()->id()]);
            foreach ($lines as $typeId => $qty) {
                $t = LinenType::where('is_active', true)->find((int) $typeId);
                if (! $t) {
                    throw new RuntimeException('Unknown linen type.');
                }
                $short = $qty - (int) $t->qty_soiled;
                if ($short > 0) {
                    if ($t->qty_floor < $short) {
                        throw new RuntimeException("Only ".($t->qty_soiled + $t->qty_floor)." {$t->name} are soiled or on the floors – can't send {$qty}.");
                    }
                    self::move($t, 'collect', $short, ['date' => $date, 'batch' => $b->id, 'reason' => 'Collected for '.$b->batch_no]);
                }
                self::move($t, 'send', $qty, ['date' => $date, 'batch' => $b->id]);
                $b->lines()->create(['linen_type_id' => $t->id, 'qty_sent' => $qty, 'rate' => $supplier ? (float) $t->wash_rate : 0]);
            }
            ActivityLogger::log('linen', 'send', "Linen batch {$b->batch_no}: ".array_sum($lines).' pcs to '.($supplier?->name ?? 'in-house laundry'), $b);

            return $b;
        });

        return $batch->fresh('lines.linen');
    }

    /**
     * Pieces coming back. lines: batch_line_id => [returned, damaged]. Returned go to the linen room,
     * damaged are discarded (valued). The batch closes by itself when nothing is outstanding.
     */
    public static function receive(LinenBatch $b, array $lines, ?string $date = null): LinenBatch
    {
        if ($b->status === 'closed') {
            throw new RuntimeException("Batch {$b->batch_no} is closed.");
        }
        $date ??= bdate()->toDateString();
        if ($date < $b->sent_date->toDateString() || $date > bdate()->toDateString()) {
            throw new RuntimeException('The return date must be between the send date and today.');
        }
        $any = false;
        DB::transaction(function () use ($b, $lines, $date, &$any) {
            foreach ($b->lines()->lockForUpdate()->get() as $l) {
                $ret = max(0, (int) ($lines[$l->id]['returned'] ?? 0));
                $dmg = max(0, (int) ($lines[$l->id]['damaged'] ?? 0));
                if ($ret + $dmg === 0) {
                    continue;
                }
                if ($ret + $dmg > $l->outstanding()) {
                    throw new RuntimeException("Only {$l->outstanding()} {$l->linen->name} are still at the laundry.");
                }
                $any = true;
                if ($ret) {
                    self::move($l->linen, 'return', $ret, ['date' => $date, 'batch' => $b->id]);
                }
                if ($dmg) {
                    self::move($l->linen, 'discard', $dmg, ['from' => 'laundry', 'date' => $date, 'batch' => $b->id, 'reason' => "Damaged – returned unusable ({$b->batch_no})"]);
                }
                $l->forceFill(['qty_returned' => $l->qty_returned + $ret, 'qty_damaged' => $l->qty_damaged + $dmg])->save();
            }
            if (! $any) {
                throw new RuntimeException('Enter the pieces returned.');
            }
            $b->load('lines');
            $open = $b->outstanding();
            $b->forceFill(['status' => $open ? 'partial' : 'closed', 'closed_date' => $open ? null : $date])->save();
            ActivityLogger::log('linen', 'receive', "Linen batch {$b->batch_no}: received back".($open ? ", {$open} pcs still out" : ' – complete'), $b);
        });

        return $b->fresh('lines.linen');
    }

    /** Close a batch with pieces still out: they are written off as lost (valued at unit cost). */
    public static function close(LinenBatch $b, string $reason): LinenBatch
    {
        if ($b->status === 'closed') {
            throw new RuntimeException("Batch {$b->batch_no} is already closed.");
        }
        if (! trim($reason)) {
            throw new RuntimeException('Give a reason for closing the batch short.');
        }
        DB::transaction(function () use ($b, $reason) {
            $lost = 0;
            foreach ($b->lines()->lockForUpdate()->get() as $l) {
                if ($n = $l->outstanding()) {
                    self::move($l->linen, 'lost', $n, ['from' => 'laundry', 'batch' => $b->id, 'reason' => "Not returned ({$b->batch_no}): {$reason}"]);
                    $l->forceFill(['qty_lost' => $l->qty_lost + $n])->save();
                    $lost += $n;
                }
            }
            $b->forceFill(['status' => 'closed', 'closed_date' => bdate()->toDateString()])->save();
            ActivityLogger::log('linen', 'close', "Linen batch {$b->batch_no} closed – {$lost} pcs written off as lost: {$reason}", $b);
        });

        return $b->fresh('lines.linen');
    }

    /** External laundry: one supplier bill per closed batch, for the pieces actually returned. */
    public static function bill(LinenBatch $b, array $d)
    {
        $b->load('lines', 'supplier');
        if (! $b->supplier) {
            throw new RuntimeException('In-house batches aren\'t billed.');
        }
        if ($b->status !== 'closed') {
            throw new RuntimeException('Close the batch (everything returned or written off) before billing it.');
        }
        if ($b->supplier_invoice_id) {
            throw new RuntimeException("Batch {$b->batch_no} is already billed.");
        }
        $amount = $b->charge();
        if ($amount <= 0) {
            throw new RuntimeException('Nothing to bill – no per-piece rate on the linen in this batch.');
        }
        ChartOfAccounts::ensureLight();
        $head = AccountHead::sys('laundry_linen');
        $pcs = (int) $b->lines->sum(fn ($l) => $l->qty_returned + $l->qty_damaged);

        return DB::transaction(function () use ($b, $d, $amount, $head, $pcs) {
            $bill = ApService::expenseBill($b->supplier, ['doc_date' => $d['doc_date'] ?? bdate()->toDateString(), 'supplier_ref' => $d['supplier_ref'] ?? null,
                'notes' => "Linen batch {$b->batch_no}", 'lines' => [['account_head_id' => $head, 'description' => "Laundry charges – batch {$b->batch_no} ({$pcs} pcs)",
                    'amount' => $amount, 'tax_rate' => (float) ($d['tax_rate'] ?? 0)]]]);
            $b->forceFill(['supplier_invoice_id' => $bill->id])->save();
            ActivityLogger::log('linen', 'bill', "Linen batch {$b->batch_no} billed: {$bill->doc_no} ".money($bill->total), $b);

            return $bill;
        });
    }

    /** Stock vs par for every active type. */
    public static function parReport()
    {
        $rooms = Room::count();
        $levels = (float) setting('linen_par_levels', '4');

        return LinenType::where('is_active', true)->orderBy('code')->get()->map(function ($t) use ($rooms, $levels) {
            $t->par_qty = $t->par($rooms, $levels);
            $t->total_qty = $t->total();
            $t->short = max(0, $t->par_qty - $t->total_qty);
            $t->cover = $t->par_qty ? round($t->total_qty / $t->par_qty * 100) : null;

            return $t;
        });
    }

    public static function ensureDefaults(): void
    {
        if (LinenType::exists()) {
            return;
        }
        foreach ([
            ['BS-D', 'Bed sheet – double', 2, 650, 18], ['BS-S', 'Bed sheet – single', 0, 450, 14], ['DC', 'Duvet cover', 1, 1400, 40], ['PC', 'Pillow cover', 4, 180, 6],
            ['BT', 'Bath towel', 2, 520, 14], ['HT', 'Hand towel', 2, 220, 8], ['FT', 'Face towel', 2, 120, 5], ['BM', 'Bath mat', 1, 300, 10],
            ['BR', 'Bathrobe', 2, 1600, 45], ['PT', 'Pool towel', 0, 480, 14], ['TC', 'Table cloth (F&B)', 0, 700, 20], ['NP', 'Napkin (F&B)', 0, 90, 4],
        ] as [$c, $n, $per, $cost, $rate]) {
            LinenType::create(['code' => $c, 'name' => $n, 'per_room' => $per, 'unit_cost' => $cost, 'wash_rate' => $rate]);
        }
    }
}
