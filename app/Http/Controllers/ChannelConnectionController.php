<?php

namespace App\Http\Controllers;

use App\Models\BusinessSource;
use App\Models\ChannelConnection;
use App\Models\ChannelMessage;
use App\Models\ChannelRateMap;
use App\Models\ChannelRestriction;
use App\Models\ChannelRoomMap;
use App\Models\Company;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Services\ActivityLogger;
use App\Services\ChannelService;
use App\Services\IcalService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** v2.8 OTA connections: set-up, room / rate mapping, per-channel restrictions, manual pushes, secret rotation, simulator. */
class ChannelConnectionController extends Controller
{
    public const CODE_RE = '/^[A-Za-z0-9_.\-]{1,40}$/';

    protected function rules(?ChannelConnection $c = null): array
    {
        return [
            'name' => 'required|string|max:80',
            'code' => ['required', 'alpha_dash', 'max:20', Rule::unique('channel_connections', 'code')->where('hotel_id', Tenant::id())->ignore($c?->id)],
            'provider' => 'required|in:'.implode(',', array_keys(ChannelConnection::providerOptions())),
            'business_source_id' => 'nullable|owned:business_sources',
            'company_id' => 'nullable|owned:companies',
            'property_code' => 'nullable|string|max:40',
            // Every adapter except the built-in simulator calls out to a URL; without it the connection can be saved
            // but every test/push fails with "endpoint not set", so the form asks up front.
            'endpoint_url' => 'nullable|required_if:provider,'.implode(',', ChannelConnection::endpointProviders()).'|url:http,https|max:500',
            'api_key' => 'nullable|string|max:255',
            'api_secret' => 'nullable|string|max:255',
            'commission_pct' => 'required|numeric|min:0|max:50',
            'rate_markup_pct' => 'required|numeric|min:-50|max:100',
            'rate_rounding' => 'required|in:'.implode(',', array_keys(ChannelConnection::ROUNDING)),
            'payment_model' => 'required|in:'.implode(',', array_keys(ChannelConnection::PAYMENT_MODELS)),
            'push_days' => 'required|integer|min:30|max:400',
            'is_active' => 'nullable|boolean',
        ];
    }

    protected function formData(): array
    {
        return ['sources' => BusinessSource::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'companies' => Company::where('is_active', true)->orderBy('name')->get(['id', 'name', 'type'])];
    }

    public function create()
    {
        return view('channel-manager.form', ['c' => new ChannelConnection(['push_days' => 365, 'is_active' => true, 'provider' => 'hub', 'commission_pct' => 15, 'rate_markup_pct' => 0])] + $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $c = DB::transaction(function () use ($data) {
            $c = new ChannelConnection;
            $c->fill($this->attributes($data))->save();
            $this->linkAccounts($c);
            ActivityLogger::log('channels', 'create', "Channel connection {$c->name} ({$c->code}) created", $c);

            return $c;
        });

        return redirect()->route('cm.connections.show', $c)->with('success', "Connection {$c->name} created – map room types and rate plans below, then run a full sync.");
    }

    protected function validated(Request $request, ?ChannelConnection $c = null): array
    {
        $data = $request->validate($this->rules($c));
        if (! empty($data['endpoint_url'])) {
            try {
                IcalService::assertSafeUrl($data['endpoint_url']);
            } catch (Throwable $e) {
                throw ValidationException::withMessages(['endpoint_url' => str_replace('Import URL', 'Endpoint URL', $e->getMessage())]);
            }
        }
        $data['is_active'] = $request->boolean('is_active');
        $data['code'] = strtoupper($data['code']);

        return $data;
    }

    protected function attributes(array $data): array
    {
        $attrs = collect($data)->except(['api_key', 'api_secret'])->all();
        if (! empty($data['api_key'])) {   // blank on edit = keep the stored key
            $attrs['api_key'] = $data['api_key'];
        }
        if (! empty($data['api_secret'])) {   // blank on edit = keep the stored vendor secret
            $attrs['api_secret'] = $data['api_secret'];
        }

        return $attrs;
    }

    /** Every connection books through a business source (reports) and an OTA account (commission, channel-collect billing). */
    protected function linkAccounts(ChannelConnection $c): void
    {
        if (! $c->business_source_id) {
            $c->business_source_id = BusinessSource::firstOrCreate(['name' => $c->name], ['type' => 'ota', 'commission_pct' => $c->commission_pct, 'is_active' => true])->id;
        }
        if (! $c->company_id) {
            $c->company_id = Company::firstOrCreate(['name' => $c->name], ['type' => 'ota', 'commission_pct' => $c->commission_pct, 'credit_limit' => 0, 'credit_days' => 30, 'is_active' => true])->id;
        }
        $c->save();
        // CommissionService accrues the OTA account's commission % at check-out
        Company::whereKey($c->company_id)->update(['commission_pct' => $c->commission_pct]);
    }

    public function show(ChannelConnection $chconn)
    {
        $c = $chconn->load('source', 'company', 'roomMaps', 'rateMaps', 'restrictions.roomType', 'restrictions.creator');
        $types = RoomType::where('is_active', true)->orderBy('base_rate')->get();

        return view('channel-manager.show', [
            'c' => $c, 'types' => $types, 'plans' => RatePlan::where('is_active', true)->orderBy('rate')->get()->groupBy('room_type_id'),
            'pending' => ChannelService::pendingCount($c), 'stats' => ChannelService::stats(30)->get($c->id, ['bookings' => 0, 'nights' => 0, 'revenue' => 0, 'commission' => 0, 'cancelled' => 0]),
            'pushes' => $c->pushes()->with('user')->latest('id')->limit(10)->get(),
            'messages' => ChannelMessage::with('reservation')->where('channel_connection_id', $c->id)->latest('id')->limit(10)->get(),
            'preview' => ChannelService::ari($c, bdate()->copy()->startOfDay(), 7),
            'restrictions' => $c->restrictions->filter(fn ($r) => $r->date_to->gte(bdate()))->sortBy('date_from'),
            'simBookings' => $c->isSimulator() ? $c->reservations()->with('guest')->whereIn('status', ['confirmed', 'tentative'])->latest('id')->limit(15)->get() : collect(),
        ]);
    }

    public function edit(ChannelConnection $chconn)
    {
        return view('channel-manager.form', ['c' => $chconn] + $this->formData());
    }

    public function update(Request $request, ChannelConnection $chconn)
    {
        $data = $this->validated($request, $chconn);
        DB::transaction(function () use ($chconn, $data) {
            $wasActive = $chconn->is_active;
            $pricing = ['rate_markup_pct', 'rate_rounding', 'push_days', 'provider', 'endpoint_url'];
            $chconn->fill($this->attributes($data));
            if ((! $wasActive && $chconn->is_active) || $chconn->isDirty($pricing)) {
                $chconn->full_sync_due = true;
                $chconn->next_retry_at = null;
            }
            $chconn->save();
            $this->linkAccounts($chconn);
            ActivityLogger::log('channels', 'update', "Channel connection {$chconn->name} updated", $chconn);
        });

        return redirect()->route('cm.connections.show', $chconn)->with('success', 'Connection saved.');
    }

    public function destroy(ChannelConnection $chconn)
    {
        if ($chconn->reservations()->exists()) {
            return back()->with('error', "{$chconn->name} has delivered bookings – pause it instead of deleting (history and reports keep the link).");
        }
        ActivityLogger::log('channels', 'delete', "Channel connection {$chconn->name} deleted", $chconn);
        $chconn->delete();

        return redirect()->route('cm.index')->with('success', 'Connection deleted.');
    }

    public function mapping(Request $request, ChannelConnection $chconn)
    {
        $data = $request->validate([
            'rooms' => 'array', 'rooms.*.code' => ['nullable', 'string', 'regex:'.self::CODE_RE], 'rooms.*.max_sell' => 'nullable|integer|min:0|max:999', 'rooms.*.active' => 'nullable|boolean',
            'rates' => 'array', 'rates.*.*' => ['nullable', 'string', 'regex:'.self::CODE_RE],
        ], ['regex' => 'Channel codes may use letters, digits, dot, dash and underscore only (max 40).']);
        $types = RoomType::pluck('id')->flip();
        $plans = RatePlan::get(['id', 'room_type_id'])->keyBy('id');
        $rooms = collect($data['rooms'] ?? [])->filter(fn ($r, $t) => isset($types[$t]));
        $codes = $rooms->pluck('code')->filter()->map(fn ($x) => mb_strtoupper($x));
        if ($codes->count() !== $codes->unique()->count()) {
            throw ValidationException::withMessages(['rooms' => 'Each room type needs its own channel room code.']);
        }
        foreach ($data['rates'] ?? [] as $t => $byPlan) {
            $rc = collect($byPlan)->filter()->map(fn ($x) => mb_strtoupper($x));
            if ($rc->count() !== $rc->unique()->count()) {
                throw ValidationException::withMessages(['rates' => 'Rate codes must be unique within a room type.']);
            }
            if ($rc->isNotEmpty() && empty($rooms[$t]['code'])) {
                throw ValidationException::withMessages(['rates' => 'Map the room type before its rate plans.']);
            }
        }
        DB::transaction(function () use ($chconn, $rooms, $data, $types, $plans) {
            foreach ($rooms as $typeId => $r) {
                $map = ChannelRoomMap::firstOrNew(['channel_connection_id' => $chconn->id, 'room_type_id' => $typeId]);
                if (empty($r['code'])) {
                    $map->exists && $map->delete();
                    ChannelRateMap::where('channel_connection_id', $chconn->id)->where('room_type_id', $typeId)->get()->each->delete();

                    continue;
                }
                $map->fill(['channel_room_code' => strtoupper($r['code']), 'max_sell' => ($r['max_sell'] ?? '') === '' ? null : (int) $r['max_sell'], 'is_active' => ! empty($r['active'])]);
                $map->isDirty() && $map->save();
            }
            foreach ($data['rates'] ?? [] as $typeId => $byPlan) {
                if (! isset($types[$typeId])) {
                    continue;
                }
                foreach ($byPlan as $planKey => $code) {
                    $planId = $planKey === 'bar' ? null : (int) $planKey;
                    if ($planId && (! isset($plans[$planId]) || $plans[$planId]->room_type_id !== (int) $typeId)) {
                        continue;
                    }
                    $map = ChannelRateMap::where('channel_connection_id', $chconn->id)->where('room_type_id', $typeId)
                        ->when($planId, fn ($q) => $q->where('rate_plan_id', $planId), fn ($q) => $q->whereNull('rate_plan_id'))->first();
                    if (! $code) {
                        $map?->delete();

                        continue;
                    }
                    $map ??= new ChannelRateMap(['channel_connection_id' => $chconn->id, 'room_type_id' => $typeId, 'rate_plan_id' => $planId]);
                    $map->channel_rate_code = strtoupper($code);
                    $map->isDirty() && $map->save();
                }
            }
            $chconn->forceFill(['full_sync_due' => true])->save();
            ActivityLogger::log('channels', 'mapping', "Room / rate mapping updated for {$chconn->name}", $chconn);
        });

        return redirect()->route('cm.connections.show', $chconn)->with('success', 'Mapping saved – a full ARI refresh is queued for this channel.');
    }

    public function restrict(Request $request, ChannelConnection $chconn)
    {
        $max = bdate()->copy()->addDays(ChannelService::HORIZON)->toDateString();
        $data = $request->validate([
            'room_type_id' => 'nullable|owned:room_types',
            'date_from' => 'required|date|after_or_equal:'.bdate()->toDateString(),
            'date_to' => 'required|date|after_or_equal:date_from|before_or_equal:'.$max,
            'min_stay' => 'nullable|integer|min:1|max:30', 'reason' => 'nullable|string|max:150',
        ]);
        $data['stop_sell'] = $request->boolean('stop_sell');
        $data['closed_to_arrival'] = $request->boolean('closed_to_arrival');
        if (! $data['stop_sell'] && ! $data['closed_to_arrival'] && empty($data['min_stay'])) {
            throw ValidationException::withMessages(['stop_sell' => 'Choose stop-sell, a minimum stay or closed-to-arrival.']);
        }
        $r = $chconn->restrictions()->create($data + ['created_by' => auth()->id()]);
        ActivityLogger::log('channels', 'restrict', "{$chconn->name}: {$r->summary()} ".Carbon::parse($data['date_from'])->format('d M').' – '.Carbon::parse($data['date_to'])->format('d M Y')
            .' ('.($r->roomType?->name ?? 'all rooms').')', $chconn);

        return redirect()->route('cm.connections.show', $chconn)->with('success', 'Restriction added – it goes out with the next push.');
    }

    public function unrestrict(ChannelConnection $chconn, ChannelRestriction $chrestr)
    {
        abort_unless($chrestr->channel_connection_id === $chconn->id, 404);
        ActivityLogger::log('channels', 'unrestrict', "{$chconn->name}: removed {$chrestr->summary()} {$chrestr->date_from->format('d M')} – {$chrestr->date_to->format('d M Y')}", $chconn);
        $chrestr->delete();

        return back()->with('success', 'Restriction removed.');
    }

    public function push(Request $request, ChannelConnection $chconn)
    {
        if (! $chconn->is_active) {
            return back()->with('error', "{$chconn->name} is paused – activate it first.");
        }
        $p = ChannelService::push($chconn, $request->boolean('full'), auth()->id());
        if (! $p) {
            return back()->with('success', 'Nothing to push – the channel is up to date.');
        }

        return back()->with($p->status === 'ok' ? 'success' : 'error', $p->status === 'ok'
            ? ucfirst($p->kind)." push sent: {$p->room_days} room-night(s) in {$p->requests} request(s)."
            : "Push failed: {$p->error} – it will be retried automatically.");
    }

    /** Probe the channel endpoint through its driver (v7.37 – no state changes). */
    public function test(ChannelConnection $chconn)
    {
        $res = ChannelService::testConnection($chconn);

        return back()->with($res['ok'] ? 'success' : 'error', ($res['ok'] ? 'Connection test passed. ' : 'Connection test failed. ').$res['message']);
    }

    public function rotate(ChannelConnection $chconn)
    {
        $chconn->rotateSecret();
        ActivityLogger::log('channels', 'rotate_secret', "Shared secret rotated for {$chconn->name}", $chconn);

        return back()->with('warning', 'New shared secret generated – update it at the channel now; messages signed with the old secret are refused.');
    }

    /** v7.39 Pull-mode sync — fetch new/changed/cancelled bookings from pull channels (Booking.com EPS). */
    public function pull(ChannelConnection $chconn)
    {
        try {
            $s = \App\Services\ChannelService::pull($chconn->fresh());
        } catch (\Throwable $e) {
            return redirect()->route('cm.connections.show', $chconn)->with('error', $e->getMessage());
        }

        return redirect()->route('cm.connections.show', $chconn)->with($s['rejected'] ? 'warning' : 'success',
            "Pull complete: {$s['pulled']} message(s) – {$s['applied']} applied"
            .($s['duplicates'] ? ", {$s['duplicates']} duplicate(s)" : '')
            .($s['rejected'] ? ", {$s['rejected']} rejected" : '')
            .($s['reservations'] ? ' → '.implode(', ', $s['reservations']) : '').'.');
    }

    public function simulate(Request $request, ChannelConnection $chconn)
    {
        abort_unless($chconn->isSimulator(), 403, 'The simulator is only available on the built-in test channel.');
        $data = $request->validate([
            'event' => 'required|in:new,modify,cancel',
            'booking_id' => 'nullable|required_unless:event,new|string|max:60',
            'room_map_id' => 'exclude_if:event,cancel|required|integer',
            'rate_code' => 'nullable|string|max:40',
            'arrival' => 'exclude_if:event,cancel|required|date|after_or_equal:'.bdate()->toDateString(),
            'departure' => 'exclude_if:event,cancel|required|date|after:arrival',
            'rooms' => 'exclude_if:event,cancel|required|integer|min:1|max:5',
            'adults' => 'exclude_if:event,cancel|required|integer|min:1|max:6',
            'first_name' => 'exclude_if:event,cancel|required|string|max:80', 'last_name' => 'nullable|string|max:80', 'email' => 'nullable|email|max:255',
            'currency' => 'exclude_if:event,cancel|nullable|string|size:3|exists:currencies,code',   // v7.50 the currency the OTA quotes in
        ]);
        abort_unless($data['event'] === 'cancel' || $chconn->roomMaps()->whereKey($data['room_map_id'])->exists(), 404);
        foreach (['arrival', 'departure'] as $k) {
            isset($data[$k]) && $data[$k] = Carbon::parse($data[$k])->toDateString();
        }
        $data['booking_id'] = $data['booking_id'] ?? null;
        [$code, $body] = ChannelService::simulate($chconn, $data);
        ChannelService::push($chconn->fresh());   // the test OTA "sees" the new availability right away

        $flash = match (true) {
            $code === 200 && ($body['status'] ?? '') === 'duplicate' => ['warning', 'Duplicate message – already processed.'],
            $code === 200 => ['success', 'Channel '.$data['event'].' accepted'.(isset($body['reservation_no']) ? ' → '.$body['reservation_no'] : '').(empty($body['warnings']) ? '.' : ' (warnings: '.implode(' ', $body['warnings']).')')],
            $code === 202 => ['warning', 'Delivered but needs attention: '.($body['error'] ?? $body['reason'] ?? 'see the OTA inbox')],
            default => ['error', 'Rejected: '.($body['error'] ?? 'invalid message')],
        };

        return redirect()->route('cm.connections.show', $chconn)->with($flash[0], $flash[1]);
    }
}
