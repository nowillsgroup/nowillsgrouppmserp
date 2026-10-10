<?php

namespace App\Http\Controllers;

use App\Models\BusinessSource;
use App\Models\OtaChannel;
use App\Models\RoomType;
use App\Models\Hotel;
use App\Services\ActivityLogger;
use App\Services\IcalService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChannelController extends Controller
{
    public function index()
    {
        return view('channels.index', [
            'channels' => OtaChannel::with('roomType', 'source')->withCount(['reservations as upcoming_count' => fn ($q) => $q->whereIn('status', ['confirmed', 'tentative', 'checked_in'])->whereDate('departure_date', '>', bdate())])->orderBy('name')->get(),
            'types' => RoomType::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
            'sources' => BusinessSource::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    protected function rules(): array
    {
        return ['name' => 'required|string|max:80', 'room_type_id' => 'required|integer|owned:room_types', 'business_source_id' => 'nullable|integer|owned:business_sources',
            'import_url' => 'nullable|url:http,https|max:500', 'is_active' => 'nullable|boolean'];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $c = OtaChannel::create($data + ['is_active' => $request->boolean('is_active', true)]);
        ActivityLogger::log('channels', 'create', "Added channel {$c->name}", $c);

        return redirect()->route('channels.index')->with('success', "Channel {$c->name} added. Paste its export URL into the OTA extranet.");
    }

    public function update(Request $request, OtaChannel $channel)
    {
        $data = $request->validate($this->rules());
        $channel->update($data + ['is_active' => $request->boolean('is_active')]);
        ActivityLogger::log('channels', 'update', "Updated channel {$channel->name}", $channel);

        return redirect()->route('channels.index')->with('success', 'Channel updated.');
    }

    public function destroy(OtaChannel $channel)
    {
        $channel->delete(); // reservations keep their data; ota_channel_id is nulled by FK
        ActivityLogger::log('channels', 'delete', "Deleted channel {$channel->name}");

        return redirect()->route('channels.index')->with('success', 'Channel removed. Existing bookings were kept.');
    }

    public function regenerate(OtaChannel $channel)
    {
        $channel->forceFill(['export_token' => Str::random(32)])->save();

        return back()->with('success', 'Export URL regenerated – update it in the OTA extranet.');
    }

    public function sync(OtaChannel $channel)
    {
        $s = IcalService::sync($channel);

        return back()->with(isset($s['error']) || ! empty($s['conflicts']) ? 'error' : 'success', "{$channel->name}: {$channel->last_sync_status}");
    }

    /** Public, token-protected iCal feed consumed by the OTA. */
    public function feed(string $token)
    {
        $channel = OtaChannel::withoutGlobalScopes()->where('export_token', $token)->firstOrFail();
        Tenant::set(Hotel::findOrFail($channel->hotel_id));

        return response(IcalService::export($channel), 200, ['Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="'.Str::slug($channel->name).'.ics"', 'Cache-Control' => 'no-cache, max-age=0']);
    }
}
