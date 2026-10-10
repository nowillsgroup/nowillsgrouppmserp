<?php

namespace App\Http\Controllers;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosTable;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Services\PosModifierService;
use App\Support\Numbering;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public QR table menu. Guests scan the code on their table, browse the menu and
 * place orders. Orders arrive on the POS as *pending* lines: they are not billed and
 * no KOT is printed until staff accept them.
 */
class QrMenuController extends Controller
{
    public const MAX_PENDING_LINES = 40;

    protected function table(string $token): PosTable
    {
        $table = PosTable::withoutGlobalScopes()->with('hotel')->where('qr_token', $token)->where('is_active', true)->firstOrFail();
        abort_unless($table->hotel?->is_active, 404);
        Tenant::set($table->hotel);
        abort_if(Setting::where('key', 'qr_menu_enabled')->value('value') === '0', 404);
        $table->load('outlet');
        abort_unless($table->outlet?->is_active, 404);

        return $table;
    }

    protected function openOrder(PosTable $table): ?PosOrder
    {
        return PosOrder::where('pos_table_id', $table->id)->whereIn('status', ['open', 'billed'])->latest('id')->first();
    }

    public function show(string $token)
    {
        $table = $this->table($token);
        $cats = MenuCategory::where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $table->outlet_id))->orderBy('sort_order')->get();
        $items = MenuItem::with(['modifierGroups' => fn ($q) => $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhere('outlet_id', $table->outlet_id))->with('modifiers')])
            ->where('is_available', true)->whereIn('menu_category_id', $cats->pluck('id'))
            ->where(fn ($q) => $q->whereNull('outlet_id')->orWhere('outlet_id', $table->outlet_id))->orderBy('name')->get();
        $cats = $cats->filter(fn ($c) => $items->contains('menu_category_id', $c->id))->values();

        return view('public.qrmenu', ['table' => $table, 'hotel' => Tenant::hotel(), 'cats' => $cats, 'items' => $items, 'allergenMap' => \App\Services\AllergenService::map($items),
            'order' => $this->openOrder($table)?->load('items')]);
    }

    public function order(Request $request, string $token)
    {
        $table = $this->table($token);
        $data = $request->validate([
            'items' => 'required|array|min:1|max:30', 'items.*.id' => 'required|integer|owned:menu_items',
            'items.*.qty' => 'required|integer|min:1|max:20', 'items.*.notes' => 'nullable|string|max:120',
            'items.*.modifiers' => 'nullable|array|max:20', 'items.*.modifiers.*' => 'integer|owned:menu_modifiers',
            'guest_name' => 'nullable|string|max:60', 'website' => 'nullable|size:0', // honeypot
            'allergens' => 'nullable|array', 'allergens.*' => 'string|in:'.implode(',', array_keys(\App\Services\AllergenService::ALLERGENS)), // v5.0
        ]);
        $order = DB::transaction(function () use ($data, $table) {
            $order = PosOrder::where('pos_table_id', $table->id)->whereIn('status', ['open', 'billed'])->lockForUpdate()->latest('id')->first();
            if ($order?->status === 'billed') {
                throw ValidationException::withMessages(['items' => 'Your bill has already been generated. Please ask our staff to add more items.']);
            }
            if ($order && $order->items()->where('is_pending', true)->where('is_cancelled', false)->count() >= self::MAX_PENDING_LINES) {
                throw ValidationException::withMessages(['items' => 'Your previous order is still being confirmed by our staff. Please wait a moment.']);
            }
            $order ??= PosOrder::create([
                'order_no' => Numbering::next('pos_order'), 'outlet_id' => $table->outlet_id, 'pos_table_id' => $table->id,
                'order_type' => 'dine_in', 'source' => 'qr', 'customer_name' => $data['guest_name'] ?? null,
                'business_date' => Tenant::hotel()->business_date, 'status' => 'open', 'covers' => 1,
            ]);
            if (! empty($data['allergens'])) { // v5.0 allergies the guest ticked are added to the order and re-flag earlier lines
                \App\Services\AllergenService::setOrderAllergies($order, array_merge((array) $order->allergens, $data['allergens']), $order->allergy_note);
            }
            $guest = \App\Services\AllergenService::clean($order->allergens);
            foreach ($data['items'] as $index => $line) {
                $m = MenuItem::with(['recipes.inventoryItem', 'modifierGroups' => fn ($q) => $q->where(fn ($w) => $w->whereNull('outlet_id')->orWhere('outlet_id', $table->outlet_id))->with('modifiers')])
                    ->whereKey($line['id'])->where('is_available', true)->first();
                if (! $m || ($m->outlet_id && (int) $m->outlet_id !== (int) $table->outlet_id)) {
                    throw ValidationException::withMessages(["items.{$index}.id" => 'One of the selected dishes is no longer available.']);
                }
                [$modifierSnapshots, $modifierAmount] = PosModifierService::snapshot($m, $line['modifiers'] ?? [], $index);
                $rate = round((float) $m->price + $modifierAmount, 2);
                $order->items()->create(['menu_item_id' => $m->id, 'item_name' => $m->name, 'qty' => $line['qty'], 'rate' => $rate,
                    'modifier_amount' => $modifierAmount, 'modifiers' => $modifierSnapshots, 'tax_rate' => $m->tax_rate,
                    'amount' => round($rate * $line['qty'], 2), 'kot_no' => null, 'is_pending' => true, 'notes' => $line['notes'] ?? null,
                    'allergy_flag' => $guest ? \App\Services\AllergenService::flagText(\App\Services\AllergenService::conflicts($m, $guest)) : null]);
            }
            ActivityLogger::log('pos', 'qr_order', 'QR self-order: '.count($data['items'])." line(s) at table {$table->table_no}", $order);

            return $order;
        });

        return redirect()->route('qrmenu.show', $token)->with('ordered', true)->with('order_no', $order->order_no);
    }

    /** Lightweight polling endpoint so the guest sees when the kitchen accepts. */
    public function status(string $token)
    {
        $order = $this->openOrder($this->table($token));

        return response()->json(['status' => $order?->status, 'total' => (float) ($order->total ?? 0),
            'items' => $order ? $order->items()->get()->map(fn ($i) => ['name' => $i->item_name, 'qty' => (float) $i->qty,
                'state' => $i->is_cancelled ? 'cancelled' : ($i->is_pending ? 'pending' : 'accepted')]) : []]);
    }
}
