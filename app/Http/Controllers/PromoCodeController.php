<?php

namespace App\Http\Controllers;

use App\Models\PromoCode;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Services\ActivityLogger;
use App\Services\CrmService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PromoCodeController extends Controller
{
    public function index()
    {
        $promos = PromoCode::withCount(['campaigns'])->orderByDesc('is_active')->orderBy('code')->get()
            ->each(fn ($p) => $p->value_stats = CrmService::bookingValue(Reservation::where('promo_code_id', $p->id)));

        return view('crm.promos.index', ['promos' => $promos, 'types' => RoomType::pluck('name', 'id')]);
    }

    protected function validated(Request $request, ?PromoCode $p = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->code))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('promo_codes', 'code')->where('hotel_id', hotel()->id)->ignore($p?->id)],
            'name' => 'required|string|max:120', 'discount_type' => 'required|in:percent,amount',
            'value' => ['required', 'numeric', 'min:0.01', $request->discount_type === 'percent' ? 'max:90' : 'max:1000000'],
            'book_from' => 'nullable|date', 'book_to' => 'nullable|date|after_or_equal:book_from',
            'stay_from' => 'nullable|date', 'stay_to' => 'nullable|date|after_or_equal:stay_from',
            'min_nights' => 'required|integer|min:1|max:30', 'max_uses' => 'nullable|integer|min:1', 'per_guest_limit' => 'nullable|integer|min:1|max:50',
            'room_type_ids' => 'nullable|array', 'room_type_ids.*' => 'integer|owned:room_types', 'is_active' => 'boolean',
        ], ['code.regex' => 'Use letters, digits, - and _ only.', 'value.max' => 'A percentage discount cannot exceed 90%.']);
        $data['room_type_ids'] = ! empty($data['room_type_ids']) ? array_values(array_map('intval', $data['room_type_ids'])) : null;
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    public function create()
    {
        return view('crm.promos.form', ['promo' => new PromoCode(['discount_type' => 'percent', 'min_nights' => 1, 'per_guest_limit' => 1, 'is_active' => true,
            'book_from' => bdate()]), 'types' => RoomType::where('is_active', true)->pluck('name', 'id')]);
    }

    public function store(Request $request)
    {
        $p = PromoCode::create($this->validated($request) + ['created_by' => auth()->id()]);
        ActivityLogger::log('crm', 'create', "Created promo code {$p->code} ({$p->offerLabel()})", $p);

        return redirect()->route('crm.promos.index')->with('success', "Promo code {$p->code} created.");
    }

    public function edit(PromoCode $promo)
    {
        return view('crm.promos.form', ['promo' => $promo, 'types' => RoomType::pluck('name', 'id')]);
    }

    public function update(Request $request, PromoCode $promo)
    {
        $promo->update($this->validated($request, $promo));
        ActivityLogger::log('crm', 'update', "Updated promo code {$promo->code}", $promo);

        return redirect()->route('crm.promos.index')->with('success', "Promo code {$promo->code} updated.");
    }

    public function toggle(PromoCode $promo)
    {
        $promo->update(['is_active' => ! $promo->is_active]);
        ActivityLogger::log('crm', 'update', ($promo->is_active ? 'Activated' : 'Deactivated')." promo code {$promo->code}", $promo);

        return back()->with('success', "Promo code {$promo->code} ".($promo->is_active ? 'activated.' : 'deactivated.'));
    }
}
