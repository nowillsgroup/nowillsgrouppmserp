<?php

namespace App\Services;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\OfflineSyncOperation;
use App\Models\Outlet;
use App\Models\PosOrder;
use App\Models\PosTable;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Restaurant ordering that survives a dead connection.
 *
 * A waiter's device keeps the outlet menu, the tables and the running orders as a
 * snapshot. Orders opened and dishes sent while the connection is down are queued
 * as operations by /js/offline-core.js and replayed here through /offline/sync.
 *
 * Two rules make the replay safe:
 *   · every operation carries an id generated on the device, and the id is stored
 *     when it is applied — a replayed order returns the order it already made;
 *   · the order is posted on the business date the device was working, not the
 *     date the sync happened to reach the server, so a 23:50 order synced at
 *     00:10 still belongs to the night it was served.
 *
 * Money is deliberately not taken here: cash, card, MoMo, room posting and VAT
 * all run through the cashier path (shift totals, folio, E-VAT, receipts), so a
 * second implementation could double-charge a guest. Settlement stays an online
 * action; the order itself never waits for a connection.
 */
class OfflinePosService
{
    public const VERSION = 2;

    public const MAX_LINES = 40;

    public const MAX_SIDES = 8;

    public const MAX_MODIFIERS = 20;

    /** How far back a device may post a business date when it reconnects. */
    public const BACKDATE_DAYS = 7;

    /** @return array<string, mixed> */
    public static function snapshot(User $user): array
    {
        $canOrder = $user->hasPermission('pos.order');

        $outlets = Outlet::where('is_active', true)->orderBy('name')->get(['id', 'name', 'service_charge_pct']);
        $outletIds = $outlets->pluck('id')->all();

        $tables = PosTable::where('is_active', true)->whereIn('outlet_id', $outletIds)
            ->orderBy('table_no')->get(['id', 'outlet_id', 'table_no', 'seats']);

        $categories = MenuCategory::where(fn ($q) => $q->whereNull('outlet_id')->orWhereIn('outlet_id', $outletIds))
            ->orderBy('sort_order')->get(['id', 'name']);

        $items = MenuItem::with('variants')
            ->where('is_available', true)
            ->whereIn('menu_category_id', $categories->pluck('id'))
            ->where(fn ($q) => $q->whereNull('outlet_id')->orWhereIn('outlet_id', $outletIds))
            ->orderBy('name')->get();

        $running = PosOrder::with('items')
            ->whereIn('status', ['open', 'billed'])
            ->whereIn('outlet_id', $outletIds)
            ->orderBy('id')->limit(200)->get();

        $stays = ReservationRoom::with('room')
            ->where('status', 'checked_in')->whereNotNull('room_id')->get()
            ->sortBy(fn (ReservationRoom $stay) => $stay->room?->room_no)->values();

        $shift = DepartmentShiftService::activeForUser((int) $user->id);

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'business_date' => bdate()->toDateString(),
            'hotel' => Tenant::hotel()?->code,
            'staff' => $user->name,
            'can_order' => $canOrder,
            'shift' => $shift ? [
                'id' => $shift->id,
                'shift' => $shift->shift,
                'opened_at' => $shift->opened_at?->toIso8601String(),
            ] : null,
            'needs_shift' => DepartmentShiftService::requiresPersonalShift($user) && ! $shift,
            'outlets' => $outlets->map(fn (Outlet $outlet) => [
                'id' => $outlet->id,
                'name' => $outlet->name,
                'service_charge_pct' => (float) ($outlet->service_charge_pct ?? 0),
                'tables' => $tables->where('outlet_id', $outlet->id)->map(fn (PosTable $table) => [
                    'id' => $table->id, 'table_no' => $table->table_no, 'seats' => (int) $table->seats,
                ])->values()->all(),
            ])->values()->all(),
            'categories' => $categories->map(fn (MenuCategory $category) => [
                'id' => $category->id, 'name' => $category->name,
            ])->values()->all(),
            'items' => $items->map(fn (MenuItem $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'category_id' => $item->menu_category_id,
                'outlet_id' => $item->outlet_id,
                'price' => (float) $item->price,
                'tax_rate' => (float) $item->tax_rate,
                'sides' => $item->variants->map(fn ($variant) => [
                    'id' => $variant->id, 'name' => $variant->name, 'rate' => (float) $variant->rate,
                ])->values()->all(),
                // v9.30 the dish cannot be rung up without one of its side dishes; the device asks, and refuses
                'side_required' => (bool) $item->side_dish_required,
            ])->values()->all(),
            'orders' => $running->map(fn (PosOrder $order) => self::shape($order))->values()->all(),
            // Room service must name the room it is billed to; a room number is all the
            // device needs, so no guest name leaves the server for this list.
            'stays' => $stays->map(fn (ReservationRoom $stay) => [
                'id' => $stay->id, 'room_no' => $stay->room?->room_no,
            ])->values()->all(),
        ];
    }

    /**
     * pos.order.create — open a new restaurant order on the device's business date.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function createOrder(string $operationId, string $operation, array $payload, ?string $deviceId, User $user): array
    {
        $outletId = (int) ($payload['outlet_id'] ?? 0);
        $tableId = isset($payload['pos_table_id']) && $payload['pos_table_id'] !== null ? (int) $payload['pos_table_id'] : null;
        $orderType = (string) ($payload['order_type'] ?? 'dine_in');
        $covers = max(1, min(50, (int) ($payload['covers'] ?? 1)));
        $stayId = isset($payload['reservation_room_id']) && $payload['reservation_room_id'] !== null ? (int) $payload['reservation_room_id'] : null;
        $customerName = self::text($payload['customer_name'] ?? null, 120);
        $steward = self::text($payload['steward'] ?? null, 60);
        $localRef = self::localRef($payload);

        if (! in_array($orderType, ['dine_in', 'takeaway', 'room_service'], true)) {
            throw ValidationException::withMessages(['payload' => ['Offline orders cover dine-in, takeaway and room service. Delivery waits for a connection.']]);
        }
        if (! $outletId) {
            throw ValidationException::withMessages(['payload.outlet_id' => ['Choose the outlet this order belongs to.']]);
        }
        if ($orderType === 'room_service' && ! $stayId) {
            throw ValidationException::withMessages(['payload.reservation_room_id' => ['Room service needs the in-house room it is billed to.']]);
        }
        $businessDate = self::businessDate($payload);
        $clientCreatedAt = self::clientTimestamp($payload);

        return DB::transaction(function () use ($operationId, $operation, $payload, $deviceId, $user, $outletId, $tableId, $orderType, $covers, $stayId, $customerName, $steward, $businessDate, $clientCreatedAt, $localRef) {
            $existing = OfflineSyncOperation::where('operation_id', $operationId)->lockForUpdate()->first();
            if ($existing) {
                return OfflineSyncService::stored($existing);
            }
            if (! $user->hasPermission('pos.order')) {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', null, null,
                    'Your login cannot open restaurant orders.');
            }
            if (DepartmentShiftService::requiresPersonalShift($user) && ! DepartmentShiftService::activeForUser((int) $user->id)) {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', null, null,
                    'Open your service shift while connected, then send this order again.');
            }

            // The same device may have queued "open" and then several tickets before
            // the connection returned: the first one creates, the rest find it here.
            if ($localRef) {
                $already = PosOrder::with('items')->where('offline_ref', $localRef)->first();
                if ($already) {
                    return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'applied', $already->id,
                        ['order' => self::shape($already), 'joined' => true]);
                }
            }

            $outlet = Outlet::whereKey($outletId)->where('is_active', true)->first();
            if (! $outlet) {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', null, null,
                    'That outlet no longer exists.');
            }

            $data = [
                'outlet_id' => $outlet->id,
                'order_type' => $orderType,
                'covers' => $covers,
                'customer_name' => $customerName,
                'steward' => $steward ?: $user->name,
                'business_date' => $businessDate,
            ];

            if ($tableId) {
                $table = PosTable::whereKey($tableId)->where('is_active', true)->first();
                if (! $table || (int) $table->outlet_id !== (int) $outlet->id) {
                    return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', null, null,
                        'That table is not an active table in this outlet.');
                }
                // Two devices may have opened the same table while offline. The server's
                // running order wins: the second one joins it instead of splitting the bill.
                $running = PosOrder::with('items')->where('pos_table_id', $table->id)
                    ->whereIn('status', ['open', 'billed'])->first();
                if ($running) {
                    return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'conflict', $running->id,
                        ['order' => self::shape($running)],
                        'Table '.$table->table_no.' already had a running order; this device joined it instead of opening a second one.');
                }
                $data['pos_table_id'] = $table->id;
            }

            if ($stayId) {
                $stay = ReservationRoom::with('reservation.guest')->whereKey($stayId)->first();
                if (! $stay || $stay->status !== 'checked_in') {
                    return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', null, null,
                        'That guest is no longer in-house, so the room-service order cannot be billed to the room.');
                }
                $data['reservation_room_id'] = $stay->id;
                $data['customer_name'] = $data['customer_name'] ?: $stay->reservation?->guest?->full_name;
            }

            $order = PosOrder::create($data + [
                'offline_ref' => $localRef,
                'order_no' => Numbering::next('pos_order'),
                'status' => 'open',
                'user_id' => $user->id,
                'department_shift_session_id' => DepartmentShiftService::activeForUser((int) $user->id)?->id,
                'delivery_status' => 'not_applicable',
                'delivery_fee' => 0,
            ]);
            if ($clientCreatedAt) {
                // Keep the moment the guest was served, not the moment the device reconnected.
                $order->forceFill(['created_at' => $clientCreatedAt])->saveQuietly();
            }
            ActivityLogger::log('pos', 'offline_order', "Offline order {$order->order_no} opened for {$outlet->name}", $order);

            return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'applied', $order->id,
                ['order' => self::shape($order->fresh('items'))]);
        });
    }

    /**
     * pos.order.kot — send dishes to the kitchen for an order this device holds.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function addItems(string $operationId, string $operation, array $payload, ?string $deviceId, User $user): array
    {
        $orderId = (int) ($payload['order_id'] ?? 0);
        $localRef = self::localRef($payload);
        $lines = is_array($payload['lines'] ?? null) ? array_values($payload['lines']) : [];
        if (! $orderId && ! $localRef) {
            throw ValidationException::withMessages(['payload.order_id' => ['The order to send dishes to is missing.']]);
        }
        if ($lines === [] || count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages(['payload.lines' => ['Send between 1 and '.self::MAX_LINES.' dishes at a time.']]);
        }

        $clean = [];
        foreach ($lines as $index => $line) {
            $menuItemId = (int) ($line['menu_item_id'] ?? 0);
            $qty = round((float) ($line['qty'] ?? 0), 2);
            if (! $menuItemId || $qty < 0.5 || $qty > 999) {
                throw ValidationException::withMessages(["payload.lines.$index" => ['Each dish needs a menu item and a quantity of at least 0.5.']]);
            }
            $variants = array_values(array_unique(array_map('intval', (array) ($line['variants'] ?? []))));
            $modifiers = array_values(array_unique(array_map('intval', (array) ($line['modifiers'] ?? []))));
            if (count($variants) > self::MAX_SIDES || count($modifiers) > self::MAX_MODIFIERS) {
                throw ValidationException::withMessages(["payload.lines.$index" => ['Too many side dishes or modifiers on one dish.']]);
            }
            $clean[] = [
                'menu_item_id' => $menuItemId,
                'qty' => $qty,
                'variants' => $variants,
                'modifiers' => $modifiers,
                'notes' => self::text($line['notes'] ?? null, 120),
                'hold' => ! empty($line['hold']),
            ];
        }

        return DB::transaction(function () use ($operationId, $operation, $payload, $deviceId, $user, $orderId, $localRef, $clean) {
            $existing = OfflineSyncOperation::where('operation_id', $operationId)->lockForUpdate()->first();
            if ($existing) {
                return OfflineSyncService::stored($existing);
            }
            if (! $user->hasPermission('pos.order')) {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', $orderId, null,
                    'Your login cannot send dishes to the kitchen.');
            }

            $order = $orderId
                ? PosOrder::with('outlet')->whereKey($orderId)->lockForUpdate()->first()
                : PosOrder::with('outlet')->where('offline_ref', $localRef)->lockForUpdate()->first();
            if (! $order && $localRef) {
                // The "open this order" operation is still ahead in the queue. Keep the
                // ticket safe and try again rather than dropping the guest's dishes.
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'retry', null, null,
                    'Waiting for the order to reach the server first; the ticket will be sent again.');
            }
            if (! $order) {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', $orderId, null,
                    'That order no longer exists on the server.');
            }
            if (! in_array($order->status, ['open', 'billed'], true)) {
                return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', $order->id, null,
                    'Order '.$order->order_no.' is already '.$order->status.', so these dishes cannot be added.');
            }

            // Price every line the way the online terminal does: side-dish rates and
            // modifiers first, then the tax-inclusive split, so the bill matches.
            $kot = (int) Numbering::next('kot');
            foreach ($clean as $index => $line) {
                $item = MenuItem::with('variants')->whereKey($line['menu_item_id'])->first();
                if (! $item || ! $item->is_available) {
                    return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', $order->id, null,
                        'A dish in this ticket is no longer on the menu, so nothing from it was sent. Remove it on the device and send again.');
                }
                if ($item->outlet_id && (int) $item->outlet_id !== (int) $order->outlet_id) {
                    return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'rejected', $order->id, null,
                        "{$item->name} is not sold at {$order->outlet->name}.");
                }
                [$modifierSnapshot, $modifierAmount] = PosModifierService::snapshot($item, $line['modifiers'], $index);
                [$sideSnapshot, $sideAmount] = PosMenuVariantService::snapshot($item, $line['variants'], $index);
                $snapshot = array_merge($modifierSnapshot, $sideSnapshot);
                $amount = round($modifierAmount + $sideAmount, 2);
                $rate = TaxService::splitPrice(round((float) $item->price + $amount, 2), (float) $item->tax_rate, 'fnb')['net'];

                $order->items()->create([
                    'menu_item_id' => $item->id,
                    'item_name' => $item->name,
                    'qty' => $line['qty'],
                    'rate' => $rate,
                    'modifier_amount' => $amount,
                    'modifiers' => $snapshot,
                    'tax_rate' => (float) $item->tax_rate,
                    'amount' => round($rate * $line['qty'], 2),
                    'kot_no' => $kot,
                    'notes' => $line['notes'],
                    'kitchen_status' => $line['hold'] ? 'held' : 'new',
                ]);
            }
            $order->status = 'open';
            $order->save();
            $order->recalculate();
            ActivityLogger::log('pos', 'offline_kot', "Offline ticket {$kot} sent to the kitchen for {$order->order_no}", $order);

            return OfflineSyncService::record($operationId, $operation, $payload, $deviceId, $user, 'applied', $order->id,
                ['order' => self::shape($order->fresh('items')), 'kot' => $kot]);
        });
    }

    /** @return array<string, mixed> */
    public static function shape(PosOrder $order): array
    {
        return [
            'id' => $order->id,
            'order_no' => $order->order_no,
            'offline_ref' => $order->offline_ref,
            'status' => $order->status,
            'outlet_id' => $order->outlet_id,
            'pos_table_id' => $order->pos_table_id,
            'order_type' => $order->order_type,
            'covers' => (int) $order->covers,
            'business_date' => $order->business_date?->toDateString(),
            'subtotal' => (float) $order->subtotal,
            'tax' => (float) $order->tax,
            'service_charge' => (float) $order->service_charge,
            'total' => (float) $order->total,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'menu_item_id' => $item->menu_item_id,
                'name' => $item->item_name,
                'qty' => (float) $item->qty,
                'rate' => (float) $item->rate,
                'amount' => (float) $item->amount,
                'kot_no' => $item->kot_no,
                'kitchen_status' => $item->kitchen_status,
                'notes' => $item->notes,
            ])->values()->all(),
        ];
    }

    /** The business date the device was working, never one it could invent. */
    protected static function businessDate(array $payload): string
    {
        return OfflineSyncService::deviceBusinessDate($payload, self::BACKDATE_DAYS)->toDateString();
    }

    /** Preserve when the guest was actually served, within the same back-dating window. */
    protected static function clientTimestamp(array $payload): ?Carbon
    {
        $raw = trim((string) ($payload['created_at'] ?? ''));
        if ($raw === '') {
            return null;
        }
        try {
            $at = Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
        if ($at->gt(now()->addMinutes(5)) || $at->lt(now()->subDays(self::BACKDATE_DAYS))) {
            return null;
        }

        return $at;
    }

    /** A reference the device generated, e.g. 'ref-3f2a...' — never required. */
    protected static function localRef(array $payload): ?string
    {
        $raw = trim((string) ($payload['local_ref'] ?? ''));
        if ($raw === '') {
            return null;
        }
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/', $raw)) {
            throw ValidationException::withMessages(['payload.local_ref' => ['The device reference is not a valid id.']]);
        }

        return $raw;
    }

    protected static function text(mixed $value, int $max): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) ($value ?? ''))));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
