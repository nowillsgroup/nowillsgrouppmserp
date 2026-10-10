<?php

namespace App\Http\Controllers;

use App\Models\BusinessSource;
use App\Models\Company;
use App\Models\RoomBlock;
use App\Models\RoomType;
use App\Services\ActivityLogger;
use App\Services\AvailabilityService;
use App\Services\BlockService;
use App\Support\Numbering;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BlockController extends Controller
{
    public function index(Request $request)
    {
        $blocks = RoomBlock::with('lines.roomType', 'company')
            ->when($request->status, fn ($q, $s) => $q->where('status', $s), fn ($q) => $q->whereIn('status', ['tentative', 'definite']))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%$t%")->orWhere('block_no', 'like', "%$t%")))
            ->orderBy('arrival_date')->paginate(25)->withQueryString();
        $blocks->getCollection()->each(fn ($b) => $b->pickup_pct = BlockService::pickupPct($b));

        return view('blocks.index', compact('blocks'));
    }

    protected function formData(?RoomBlock $block = null): array
    {
        return ['block' => $block, 'types' => RoomType::where('is_active', true)->orderBy('name')->get(),
            'companies' => Company::where('is_active', true)->orderBy('name')->pluck('name', 'id'), 'sources' => BusinessSource::orderBy('name')->pluck('name', 'id'),
            'lines' => $block ? $block->lines->pluck('rooms', 'room_type_id')->all() : []];
    }

    public function create()
    {
        return view('blocks.form', $this->formData());
    }

    public function edit(RoomBlock $block)
    {
        abort_unless($block->isHolding(), 422, 'Only tentative/definite blocks can be edited.');

        return view('blocks.form', $this->formData($block->load('lines')));
    }

    protected function validated(Request $request, ?RoomBlock $block = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:150', 'company_id' => 'nullable|integer|owned:companies', 'business_source_id' => 'nullable|integer|owned:business_sources',
            'contact_name' => 'nullable|string|max:120', 'contact_phone' => 'nullable|string|max:30', 'contact_email' => 'nullable|email|max:150',
            'arrival_date' => ($block ? 'required' : 'required|after_or_equal:'.bdate()->toDateString()).'|date',
            'departure_date' => 'required|date|after:arrival_date|before:'.bdate()->copy()->addYears(2)->toDateString(),
            'cutoff_date' => 'required|date|before_or_equal:arrival_date', 'rate' => 'nullable|numeric|min:0|max:10000000',
            'billing_instruction' => 'required|in:guest,company', 'status' => 'required|in:tentative,definite', 'notes' => 'nullable|string|max:2000',
            'lines' => 'required|array', 'lines.*' => 'nullable|integer|min:0|max:500',
        ]);
        $lines = collect($data['lines'])->filter(fn ($n) => (int) $n > 0)->map(fn ($n) => (int) $n);
        $valid = RoomType::whereIn('id', $lines->keys())->pluck('id');
        $lines = $lines->only($valid->all())->all();
        if (! $lines) {
            throw \Illuminate\Validation\ValidationException::withMessages(['lines' => 'Hold at least one room.']);
        }
        BlockService::assertFits($lines, $data['arrival_date'], $data['departure_date'], $block);
        unset($data['lines']);

        return [$data, $lines];
    }

    public function store(Request $request)
    {
        [$data, $lines] = $this->validated($request);
        $block = DB::transaction(function () use ($data, $lines) {
            AvailabilityService::lockRoomInventory(array_keys($lines));
            // Re-check after taking the same physical inventory locks as
            // reservation writers; the preview validation is advisory only.
            BlockService::assertFits($lines, $data['arrival_date'], $data['departure_date']);
            $b = RoomBlock::create($data + ['block_no' => Numbering::next('block'), 'created_by' => auth()->id()]);
            foreach ($lines as $type => $n) {
                $b->lines()->create(['room_type_id' => $type, 'rooms' => $n]);
            }

            return $b;
        });
        ActivityLogger::log('blocks', 'create', "Created group block {$block->block_no} {$block->name} (".array_sum($lines).' rooms)', $block);

        return redirect()->route('blocks.show', $block)->with('success', "Block {$block->block_no} created – ".array_sum($lines).' room(s) held until '.$block->cutoff_date->format('d M').'.');
    }

    public function update(Request $request, RoomBlock $block)
    {
        abort_unless($block->isHolding(), 422);
        [$data, $lines] = $this->validated($request, $block);
        DB::transaction(function () use ($block, $data, $lines) {
            $existingTypes = $block->lines()->pluck('room_type_id')->all();
            AvailabilityService::lockRoomInventory(array_merge($existingTypes, array_keys($lines)));
            // Re-check after locking because another reservation may have
            // consumed availability since the form was opened.
            BlockService::assertFits($lines, $data['arrival_date'], $data['departure_date'], $block);
            $block->update($data);
            $block->lines()->whereNotIn('room_type_id', array_keys($lines))->delete();
            foreach ($lines as $type => $n) {
                $block->lines()->updateOrCreate(['room_type_id' => $type], ['rooms' => $n]);
            }
        });
        ActivityLogger::log('blocks', 'update', "Updated block {$block->block_no}", $block);

        return redirect()->route('blocks.show', $block)->with('success', 'Block updated.');
    }

    public function show(RoomBlock $block)
    {
        $block->load('lines.roomType', 'company', 'source', 'reservations.guest', 'reservations.rooms.room');

        return view('blocks.show', ['block' => $block, 'grid' => BlockService::grid($block), 'pct' => BlockService::pickupPct($block)]);
    }

    public function previewPickup(Request $request, RoomBlock $block)
    {
        $data = $request->validate([
            'names' => 'required|string|max:5000',
            'room_type_id' => 'required|integer|owned:room_types',
            'arrival_date' => 'required|date',
            'departure_date' => 'required|date|after:arrival_date',
        ]);
        $names = collect(preg_split('/\r\n|\n|\r/', $data['names']))->map(fn ($n) => trim(preg_replace('/\s+/', ' ', $n)))->filter()->values();
        try {
            $msg = BlockService::quotePickup($block, $names->all(), (int) $data['room_type_id'], $data['arrival_date'], $data['departure_date']);
        } catch (RuntimeException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $msg]);
        }

        return back()->with('success', $msg);
    }

    public function pickup(Request $request, RoomBlock $block)
    {
        $data = $request->validate(['names' => 'required|string|max:5000', 'room_type_id' => 'required|integer|owned:room_types', 'adults' => 'required|integer|min:1|max:6',
            'arrival_date' => 'required|date', 'departure_date' => 'required|date|after:arrival_date']);
        $names = collect(preg_split('/\r\n|\n|\r/', $data['names']))->map(fn ($n) => trim(preg_replace('/\s+/', ' ', $n)))->filter()->values();
        if ($names->isEmpty() || $names->count() > 100) {
            return back()->withInput()->with('error', 'Enter 1–100 guest names, one per line.');
        }
        $made = BlockService::pickup($block->load('lines'), $names->all(), (int) $data['room_type_id'], (int) $data['adults'], $data['arrival_date'], $data['departure_date']);

        return back()->with('success', count($made).' reservation(s) created from the rooming list.');
    }

    public function status(Request $request, RoomBlock $block)
    {
        $action = $request->validate(['action' => 'required|in:definite,release,cancel'])['action'];
        abort_unless($block->isHolding(), 422, 'Block is already '.$block->status.'.');
        match ($action) {
            'definite' => $block->update(['status' => 'definite']),
            'release' => BlockService::release($block, 'released manually'),
            'cancel' => $block->forceFill(['status' => 'cancelled', 'released_at' => now()])->save(),
        };
        ActivityLogger::log('blocks', $action, ucfirst($action)." block {$block->block_no}", $block);

        return back()->with('success', match ($action) {
            'definite' => 'Block marked definite.', 'release' => 'Unpicked rooms released to general inventory.',
            'cancel' => 'Block cancelled. Reservations already picked up are kept.',
        });
    }
}
