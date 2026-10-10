<?php

namespace App\Services;

use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosTable;
use App\Support\Numbering;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Restaurant table operations: move an order to another table, merge two running orders,
 * and split selected items (or part quantities) into a separate bill.
 */
class PosOpsService
{
    protected static function assertRunning(PosOrder $o, string $what = 'Order'): void
    {
        if (! in_array($o->status, ['open', 'billed'])) {
            throw new RuntimeException("{$what} {$o->order_no} is {$o->status}.");
        }
        if ($o->items()->where('is_pending', true)->where('is_cancelled', false)->exists()) {
            throw new RuntimeException("{$what} {$o->order_no} has guest QR items awaiting confirmation. Accept or reject them first.");
        }
    }

    /** A running order changes after a split/merge – any printed bill is no longer valid. */
    protected static function reopen(PosOrder $o): void
    {
        $o->refresh();
        if ($o->status === 'billed') {
            $o->status = 'open';
        }
        $o->save();
        $o->recalculate();
    }

    protected static function assertUnpaid(PosOrder $o, string $what): void
    {
        if ($o->payments()->whereIn('kind', ['payment', 'refund'])->exists()) {
            throw new RuntimeException("{$what} {$o->order_no} has recorded payments; reverse them before changing its bill structure.");
        }
    }

    public static function moveTable(PosOrder $order, PosTable $table): PosOrder
    {
        self::assertRunning($order);
        if (! $table->is_active) {
            throw new RuntimeException("Table {$table->table_no} is inactive.");
        }
        if ((int) $table->outlet_id !== (int) $order->outlet_id) {
            throw new RuntimeException('Tables can only be changed within the same outlet.');
        }
        if ((int) $order->pos_table_id === (int) $table->id) {
            throw new RuntimeException('The order is already on this table.');
        }
        $busy = PosOrder::where('pos_table_id', $table->id)->whereIn('status', ['open', 'billed'])->first();
        if ($busy) {
            throw new RuntimeException("Table {$table->table_no} already has order {$busy->order_no}. Use Merge instead.");
        }
        $from = $order->table?->table_no;
        $order->update(['pos_table_id' => $table->id, 'order_type' => 'dine_in']);
        ActivityLogger::log('pos', 'move_table', "Moved {$order->order_no} from table ".($from ?? '—')." to {$table->table_no}", $order);

        return $order;
    }

    /** Move every line of $source into $target; $source is closed as "merged". */
    public static function merge(PosOrder $target, PosOrder $source): PosOrder
    {
        if ($target->id === $source->id) {
            throw new RuntimeException('Choose a different order to merge.');
        }
        self::assertRunning($target);
        self::assertRunning($source, 'Order');
        self::assertUnpaid($target, 'Target order');
        self::assertUnpaid($source, 'Source order');
        if ($target->outlet_id !== $source->outlet_id) {
            throw new RuntimeException('Only orders of the same outlet can be merged.');
        }

        return DB::transaction(function () use ($target, $source) {
            $n = $source->items()->update(['pos_order_id' => $target->id]);
            $target->covers = min(255, $target->covers + $source->covers);
            $target->save();
            $source->forceFill(['status' => 'merged', 'merged_into_id' => $target->id, 'discount' => 0, 'void_reason' => 'Merged into '.$target->order_no])->save();
            $source->recalculate();
            self::reopen($target);
            ActivityLogger::log('pos', 'merge', "Merged {$source->order_no} ({$n} line(s)) into {$target->order_no}", $target);

            return $target->fresh();
        });
    }

    /**
     * @param  array<int, float>  $lines  pos_order_item_id => qty to move to the new bill
     */
    public static function split(PosOrder $order, array $lines): PosOrder
    {
        self::assertRunning($order);
        self::assertUnpaid($order, 'Order');
        $lines = array_filter(array_map('floatval', $lines), fn ($q) => $q > 0);
        if (! $lines) {
            throw new RuntimeException('Select at least one item to move to the new bill.');
        }

        return DB::transaction(function () use ($order, $lines) {
            $items = PosOrderItem::where('pos_order_id', $order->id)->where('is_cancelled', false)->whereIn('id', array_keys($lines))->lockForUpdate()->get();
            if ($items->count() !== count($lines)) {
                throw new RuntimeException('Some selected items are not on this order.');
            }
            $live = PosOrderItem::where('pos_order_id', $order->id)->where('is_cancelled', false)->where('is_pending', false)->get();
            $remaining = $live->sum('qty') - array_sum($lines);
            if ($remaining <= 0.001) {
                throw new RuntimeException('Leave at least one item on the original bill.');
            }
            $new = PosOrder::create([
                'order_no' => Numbering::next('pos_order'), 'outlet_id' => $order->outlet_id, 'pos_table_id' => $order->pos_table_id,
                'reservation_room_id' => $order->reservation_room_id, 'order_type' => $order->order_type, 'customer_name' => $order->customer_name,
                'covers' => 1, 'steward' => $order->steward, 'business_date' => bdate(), 'status' => 'open', 'user_id' => auth()->id(), 'split_from_id' => $order->id,
            ]);
            foreach ($items as $it) {
                $q = round($lines[$it->id], 2);
                if ($q > (float) $it->qty + 0.001) {
                    throw new RuntimeException("Only {$it->qty} × {$it->item_name} on the order.");
                }
                $priced = fn ($qty) => $it->is_complimentary ? 0 : round($qty * (float) $it->rate, 2);
                if (abs($q - (float) $it->qty) < 0.001) {
                    $it->update(['pos_order_id' => $new->id]);
                } else {
                    $rest = round((float) $it->qty - $q, 2);
                    $it->update(['qty' => $rest, 'amount' => $priced($rest)]);
                    $copy = $it->replicate(['id']);
                    $copy->fill(['pos_order_id' => $new->id, 'qty' => $q, 'amount' => $priced($q)])->save();
                }
            }
            self::reopen($order);
            $new->recalculate();
            ActivityLogger::log('pos', 'split', "Split ".count($lines)." line(s) from {$order->order_no} into {$new->order_no}", $order);

            return $new;
        });
    }
}
