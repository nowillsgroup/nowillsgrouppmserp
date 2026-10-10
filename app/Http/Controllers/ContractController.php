<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\RateContract;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomType;
use App\Services\ActivityLogger;
use App\Services\ContractService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * v2.7 Corporate & travel-agent rate contracts: negotiated net rates or % off the public rate, seasons, blackout
 * dates, room allotments with release days, last-room availability, access codes for the booking engine,
 * maker-checker activation, renewal with uplift and production tracking against the room-night target.
 */
class ContractController extends Controller
{
    public const TABS = ['all' => 'All', 'active' => 'Active', 'draft' => 'Awaiting approval', 'expiring' => 'Expiring', 'suspended' => 'Suspended', 'expired' => 'Expired'];

    public function index(Request $request)
    {
        $tab = array_key_exists($request->tab, self::TABS) ? $request->tab : 'all';
        $today = bdate()->toDateString();
        $alertTo = bdate()->copy()->addDays((int) setting('contract_expiry_alert_days', '60'))->toDateString();
        $all = RateContract::with('company', 'allotments')->withCount('reservations')->orderByDesc('valid_to')->get()
            ->each(fn ($c) => $c->prod = ContractService::production($c));
        $pick = fn ($c) => match ($tab) {
            'active' => in_array($c->state(), ['active', 'upcoming']),
            'draft' => $c->status === 'draft',
            'expiring' => $c->status === 'active' && $c->valid_to->toDateString() >= $today && $c->valid_to->toDateString() <= $alertTo,
            'suspended' => $c->status === 'suspended',
            'expired' => $c->state() === 'expired',
            default => true,
        };
        $live = $all->filter(fn ($c) => $c->state() === 'active');

        return view('contracts.index', [
            'contracts' => $all->filter($pick)->values(), 'tab' => $tab, 'alerts' => ContractService::alerts(),
            'counts' => collect(array_keys(self::TABS))->mapWithKeys(fn ($t) => [$t => $all->filter(function ($c) use ($t, $today, $alertTo) {
                return match ($t) {
                    'active' => in_array($c->state(), ['active', 'upcoming']), 'draft' => $c->status === 'draft', 'suspended' => $c->status === 'suspended',
                    'expiring' => $c->status === 'active' && $c->valid_to->toDateString() >= $today && $c->valid_to->toDateString() <= $alertTo,
                    'expired' => $c->state() === 'expired', default => true,
                };
            })->count()]),
            'stats' => ['live' => $live->count(), 'nights' => $live->sum(fn ($c) => $c->prod['nights']), 'revenue' => $live->sum(fn ($c) => $c->prod['revenue']),
                'allotted' => $live->sum(fn ($c) => $c->allotments->filter(fn ($a) => $a->date_from->lte(bdate()) && $a->date_to->gte(bdate()))->sum('rooms'))],
        ]);
    }

    protected function formData(RateContract $c): array
    {
        return ['contract' => $c, 'companies' => Company::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type', 'commission_pct'])->mapWithKeys(fn ($co) => [$co->id => $co->name.' ('.str_replace('_', ' ', $co->type).')']),
            'types' => RoomType::where('is_active', true)->orderBy('base_rate')->get(['id', 'name', 'base_rate'])];
    }

    public function create(Request $request)
    {
        $from = bdate()->copy()->startOfMonth();

        return view('contracts.form', $this->formData(new RateContract([
            'company_id' => $request->integer('company_id') ?: null, 'pricing' => 'net', 'meal_plan' => 'CP', 'cancel_days' => 1,
            'valid_from' => $from, 'valid_to' => $from->copy()->addYear()->subDay(), 'lra' => false,
        ])));
    }

    public function store(Request $request)
    {
        [$data, $children] = $this->validated($request);
        $c = DB::transaction(function () use ($data, $children) {
            $c = RateContract::create($data + ['status' => 'draft', 'created_by' => auth()->id()]);
            $this->syncChildren($c, $children);

            return $c;
        });
        ActivityLogger::log('contracts', 'create', "Drafted contract {$c->code} for {$c->company->name} ({$c->basisLabel()})", $c);

        return redirect()->route('contracts.show', $c)->with('success', "Contract {$c->code} saved as a draft – it needs approval before it applies.");
    }

    public function show(RateContract $contract)
    {
        $contract->load('company', 'rates.roomType', 'blackouts', 'allotments.roomType', 'creator', 'approver', 'renewedFrom');
        $from = bdate()->copy()->startOfDay();

        return view('contracts.show', [
            'c' => $contract, 'prod' => ContractService::production($contract),
            'grid' => ContractService::allotmentGrid($contract, $from, 30), 'gridFrom' => $from,
            'usage' => $contract->allotments->mapWithKeys(fn ($a) => [$a->id => ContractService::allotmentUsage($a)]),
            'bookings' => Reservation::with('guest', 'rooms.roomType')->where('rate_contract_id', $contract->id)->orderByDesc('arrival_date')->limit(25)->get(),
            'renewals' => RateContract::where('renewed_from_id', $contract->id)->get(['id', 'code', 'status']),
            'publicRates' => RoomType::pluck('base_rate', 'id'),
        ]);
    }

    public function edit(RateContract $contract)
    {
        if (! $contract->isEditable()) {
            return redirect()->route('contracts.show', $contract)->with('error', 'Active contracts are locked. Suspend it to amend – the changes then need re-approval.');
        }

        return view('contracts.form', $this->formData($contract->load('rates', 'blackouts', 'allotments')));
    }

    public function update(Request $request, RateContract $contract)
    {
        abort_unless($contract->isEditable(), 403, 'Active or expired contracts cannot be edited.');
        [$data, $children] = $this->validated($request, $contract);
        DB::transaction(function () use ($contract, $data, $children) {
            $contract->update($data);
            $this->syncChildren($contract, $children);
        });
        ActivityLogger::log('contracts', 'update', "Updated contract {$contract->code}", $contract);

        return redirect()->route('contracts.show', $contract)->with('success', "Contract {$contract->code} updated.");
    }

    public function destroy(RateContract $contract)
    {
        abort_unless($contract->status === 'draft', 403, 'Only draft contracts can be deleted.');
        if (Reservation::where('rate_contract_id', $contract->id)->exists()) {
            return back()->with('error', 'This contract has bookings and cannot be deleted.');
        }
        DB::transaction(function () use ($contract) {
            $contract->rates()->delete();
            $contract->blackouts()->delete();
            $contract->allotments()->delete();
            $contract->delete();
        });
        ActivityLogger::log('contracts', 'delete', "Deleted draft contract {$contract->code}", null);

        return redirect()->route('contracts.index')->with('success', "Draft {$contract->code} deleted.");
    }

    public function activate(RateContract $contract)
    {
        ContractService::activate($contract);

        return back()->with('success', "Contract {$contract->code} is now active".($contract->valid_from->gt(bdate()) ? ' from '.$contract->valid_from->format('d M Y') : '').'.');
    }

    public function suspend(Request $request, RateContract $contract)
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:200']);
        ContractService::suspend($contract, $data['reason']);

        return back()->with('success', "Contract {$contract->code} suspended – new bookings get public rates; existing bookings keep their price.");
    }

    public function renew(Request $request, RateContract $contract)
    {
        $request->merge(['code' => strtoupper(trim((string) $request->code))]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('rate_contracts', 'code')->where('hotel_id', hotel()->id)],
            'uplift' => 'required|numeric|min:-50|max:100',
        ], ['code.regex' => 'Use letters, digits, - and _ only.']);
        $new = ContractService::renew($contract, $data['code'], (float) $data['uplift']);

        return redirect()->route('contracts.show', $new)->with('success', "Renewal {$new->code} drafted ({$new->valid_from->format('d M Y')} – {$new->valid_to->format('d M Y')}). Review and send for approval.");
    }

    public function letter(RateContract $contract)
    {
        $contract->load('company', 'rates.roomType', 'blackouts', 'allotments.roomType', 'approver');

        return view('contracts.letter', ['c' => $contract, 'publicRates' => RoomType::pluck('base_rate', 'id')]);
    }

    // ------------------------------------------------------------------ validation

    /** @return array{0: array, 1: array{rates: array, blackouts: array, allotments: array}} */
    protected function validated(Request $request, ?RateContract $c = null): array
    {
        $blank = fn ($rows, $keys) => array_values(array_filter((array) $rows, fn ($r) => is_array($r) && collect($keys)->contains(fn ($k) => ($r[$k] ?? '') !== '' && ($r[$k] ?? null) !== null)));
        $request->merge([
            'code' => strtoupper(trim((string) $request->code)),
            'access_code' => $request->filled('access_code') ? strtoupper(trim((string) $request->access_code)) : null,
            'rates' => $blank($request->rates, ['room_type_id', 'rate']),
            'blackouts' => $blank($request->blackouts, ['date_from', 'date_to']),
            'allotments' => $blank($request->allotments, ['room_type_id', 'rooms']),
        ]);
        $hid = hotel()->id;
        $data = $request->validate([
            'company_id' => 'required|integer|owned:companies',
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('rate_contracts', 'code')->where('hotel_id', $hid)->ignore($c?->id)],
            'name' => 'required|string|max:120',
            'valid_from' => 'required|date', 'valid_to' => 'required|date|after_or_equal:valid_from',
            'pricing' => 'required|in:net,discount',
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:90', Rule::requiredIf($request->pricing === 'discount')],
            'meal_plan' => ['required', Rule::in(array_keys(RateContract::MEAL_PLANS))],
            'inclusions' => 'nullable|string|max:255', 'lra' => 'boolean',
            'access_code' => ['nullable', 'string', 'min:4', 'max:20', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('rate_contracts', 'access_code')->where('hotel_id', $hid)->ignore($c?->id)],
            'cancel_days' => 'required|integer|min:0|max:60', 'commission_pct' => 'nullable|numeric|min:0|max:50',
            'room_night_target' => 'nullable|integer|min:0|max:100000', 'notes' => 'nullable|string|max:2000',
            'rates' => 'array|max:60', 'rates.*.room_type_id' => 'required|integer|owned:room_types', 'rates.*.rate' => 'required|numeric|min:1|max:10000000',
            'rates.*.double_rate' => 'nullable|numeric|min:1|max:10000000', 'rates.*.extra_adult_rate' => 'nullable|numeric|min:0|max:10000000',
            'rates.*.season_name' => 'nullable|string|max:60', 'rates.*.season_from' => 'nullable|date|required_with:rates.*.season_to',
            'rates.*.season_to' => 'nullable|date|required_with:rates.*.season_from|after_or_equal:rates.*.season_from',
            'blackouts' => 'array|max:60', 'blackouts.*.date_from' => 'required|date', 'blackouts.*.date_to' => 'required|date|after_or_equal:blackouts.*.date_from',
            'blackouts.*.reason' => 'nullable|string|max:120',
            'allotments' => 'array|max:60', 'allotments.*.room_type_id' => 'required|integer|owned:room_types', 'allotments.*.rooms' => 'required|integer|min:1|max:500',
            'allotments.*.date_from' => 'required|date', 'allotments.*.date_to' => 'required|date|after_or_equal:allotments.*.date_from',
            'allotments.*.release_days' => 'required|integer|min:0|max:90',
        ], [
            'code.regex' => 'Use letters, digits, - and _ only.', 'access_code.regex' => 'Use letters, digits, - and _ only.',
            'access_code.unique' => 'Another contract already uses this booking-engine code.', 'discount_pct.required' => 'Enter the discount for a % off contract.',
            'rates.*.room_type_id.required' => 'Pick the room type on every rate line.', 'rates.*.rate.required' => 'Enter the rate on every rate line.',
            'allotments.*.room_type_id.required' => 'Pick the room type on every allotment line.',
        ]);
        $vf = Carbon::parse($data['valid_from']);
        $vt = Carbon::parse($data['valid_to']);
        $errors = [];
        if ($data['pricing'] === 'net' && empty($data['rates'])) {
            $errors['rates'] = 'A net-rate contract needs at least one rate line.';
        }
        // one open (all-year) line per room type, and seasons of the same room type must not overlap
        foreach (collect($data['rates'])->groupBy('room_type_id') as $typeId => $lines) {
            $name = RoomType::find($typeId)?->name;
            if ($lines->filter(fn ($l) => empty($l['season_from']))->count() > 1) {
                $errors['rates'] = "{$name}: only one all-period rate line is allowed – use seasons for other periods.";
            }
            $seasons = $lines->filter(fn ($l) => ! empty($l['season_from']))->sortBy('season_from')->values();
            for ($i = 1; $i < $seasons->count(); $i++) {
                if ($seasons[$i]['season_from'] <= $seasons[$i - 1]['season_to']) {
                    $errors['rates'] = "{$name}: seasons {$seasons[$i - 1]['season_from']} and {$seasons[$i]['season_from']} overlap.";
                }
            }
        }
        foreach ($data['allotments'] as $i => $a) {
            if (Carbon::parse($a['date_from'])->lt($vf) || Carbon::parse($a['date_to'])->gt($vt)) {
                $errors["allotments.$i.date_from"] = 'Allotment dates must fall within the contract validity.';
            }
            $inv = Room::where('room_type_id', $a['room_type_id'])->where('is_active', true)->count();
            if ($a['rooms'] > $inv) {
                $errors["allotments.$i.rooms"] = 'Allotment of '.$a['rooms'].' exceeds the '.$inv.' '.RoomType::find($a['room_type_id'])?->name.' room(s) in inventory.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $children = ['rates' => $data['rates'], 'blackouts' => $data['blackouts'], 'allotments' => $data['allotments']];
        unset($data['rates'], $data['blackouts'], $data['allotments']);
        $data['lra'] = $request->boolean('lra');
        $data['discount_pct'] = (float) ($data['discount_pct'] ?? 0);

        return [$data, $children];
    }

    protected function syncChildren(RateContract $c, array $children): void
    {
        $c->rates()->delete();
        $c->blackouts()->delete();
        $c->allotments()->delete();
        foreach ($children['rates'] as $r) {
            $c->rates()->create(collect($r)->only(['room_type_id', 'season_name', 'season_from', 'season_to', 'rate', 'double_rate', 'extra_adult_rate'])
                ->map(fn ($v) => $v === '' ? null : $v)->all());
        }
        foreach ($children['blackouts'] as $b) {
            $c->blackouts()->create(collect($b)->only(['date_from', 'date_to', 'reason'])->all());
        }
        foreach ($children['allotments'] as $a) {
            $c->allotments()->create(collect($a)->only(['room_type_id', 'date_from', 'date_to', 'rooms', 'release_days'])->all());
        }
    }
}
