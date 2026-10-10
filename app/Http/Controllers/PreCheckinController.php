<?php

namespace App\Http\Controllers;

use App\Models\GuestDocument;
use App\Models\Reservation;
use App\Models\Setting;
use App\Services\ActivityLogger;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Public, tokenised online (pre-arrival) check-in. The guest confirms their details, arrival time,
 * uploads ID and signs the registration card before arriving; the front desk sees "Pre-checked-in".
 */
class PreCheckinController extends Controller
{
    protected function reservation(string $token): Reservation
    {
        $r = Reservation::withoutGlobalScopes()->with('hotel')->where('checkin_token', $token)->firstOrFail();
        abort_unless($r->hotel?->is_active, 404);
        Tenant::set($r->hotel);
        abort_if(Setting::where('key', 'online_checkin_enabled')->value('value') === '0', 404);
        $r->load('guest', 'rooms.roomType', 'rooms.room');

        return $r;
    }

    /** null = open, otherwise the reason the form is closed */
    protected function closedReason(Reservation $r): ?string
    {
        if (! in_array($r->status, ['confirmed', 'tentative'])) {
            return match ($r->status) {
                'checked_in', 'checked_out' => 'You are already checked in. Enjoy your stay!',
                default => 'This reservation is '.str_replace('_', '-', $r->status).'. Please contact the hotel.',
            };
        }
        if ($r->departure_date->lte(bdate())) {
            return 'This reservation has ended.';
        }
        if ($r->rooms->where('status', 'reserved')->isEmpty()) {
            return 'All rooms on this reservation are already checked in.';
        }

        return null;
    }

    public function show(string $token)
    {
        $r = $this->reservation($token);

        return view('public.precheckin', ['hotel' => Tenant::hotel(), 'r' => $r, 'closed' => $this->closedReason($r), 'token' => $token,
            'terms' => setting('grc_terms', 'I agree to abide by the hotel rules. I am responsible for all charges incurred during my stay.')]);
    }

    public function submit(Request $request, string $token)
    {
        $r = $this->reservation($token);
        abort_if($this->closedReason($r), 410);
        $data = $request->validate([
            'first_name' => 'required|string|max:80', 'last_name' => 'nullable|string|max:80',
            'phone' => 'required|string|max:30', 'email' => 'required|email|max:190',
            'nationality' => 'nullable|string|max:60', 'dob' => 'nullable|date|before:today',
            'address' => 'nullable|string|max:190', 'city' => 'nullable|string|max:80', 'country' => 'nullable|string|max:80',
            'id_type' => 'required|in:'.implode(',', array_keys(config('pms.id_types'))), 'id_number' => 'required|string|max:60',
            'passport_no' => 'nullable|string|max:40', 'visa_expiry' => 'nullable|date',
            'eta' => 'nullable|date_format:H:i', 'special_request' => 'nullable|string|max:500',
            'id_front' => ($r->prechecked_in_at ? 'nullable' : 'required').'|file|max:5120|mimes:jpg,jpeg,png,webp,pdf|mimetypes:image/jpeg,image/png,image/webp,application/pdf',
            'id_back' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,webp,pdf|mimetypes:image/jpeg,image/png,image/webp,application/pdf',
            'signature' => ['required', 'string', 'max:700000', 'starts_with:data:image/png;base64,'],
            'accept' => 'accepted',
            // v3.4 Form C particulars (foreign nationals) – kept on the reservation, copied into the Form C at check-in
            'formc' => 'nullable|array', 'formc.passport_place' => 'nullable|string|max:80', 'formc.passport_issued_on' => 'nullable|date|before_or_equal:today',
            'formc.passport_expires_on' => 'nullable|date|after:today', 'formc.visa_no' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\- ]{4,30}$/'],
            'formc.visa_type' => 'nullable|in:'.implode(',', array_keys(\App\Models\FormCRecord::VISA_TYPES)), 'formc.visa_place' => 'nullable|string|max:80',
            'formc.visa_issued_on' => 'nullable|date|before_or_equal:today', 'formc.arrived_india_on' => 'nullable|date', 'formc.arrived_from' => 'nullable|string|max:120',
            'formc.port_of_arrival' => 'nullable|string|max:80', 'formc.next_destination' => 'nullable|string|max:120',
            'formc.purpose' => 'nullable|in:'.implode(',', array_keys(\App\Models\FormCRecord::PURPOSES)),
            'visa_scan' => 'nullable|file|max:5120|mimes:jpg,jpeg,png,webp,pdf|mimetypes:image/jpeg,image/png,image/webp,application/pdf',
        ], ['formc.visa_no.regex' => 'The visa number may only contain letters, digits and dashes.', 'formc.passport_expires_on.after' => 'Your passport must be valid.', 'id_front.required' => 'Please upload a photo or scan of your ID.', 'signature.required' => 'Please sign in the box.']);

        $sig = base64_decode(substr($data['signature'], strlen('data:image/png;base64,')), true);
        $info = $sig ? @getimagesizefromstring($sig) : false;
        if (! $info || $info[2] !== IMAGETYPE_PNG || $info[0] > 2000 || $info[1] > 1000 || strlen($sig) < 500) {
            return back()->withInput()->withErrors(['signature' => 'The signature could not be read. Please sign again.']);
        }

        DB::transaction(function () use ($r, $data, $request, $sig) {
            $guest = $r->guest;
            $guest->fill(collect($data)->only(['first_name', 'last_name', 'phone', 'email', 'nationality', 'dob', 'address', 'city', 'country', 'id_type', 'id_number', 'passport_no', 'visa_expiry'])
                ->filter(fn ($v) => $v !== null && $v !== '')->all())->save();
            foreach (['id_front' => 'id_front', 'id_back' => 'id_back', 'visa_scan' => 'visa'] as $field => $type) {
                if ($file = $request->file($field)) {
                    $ext = strtolower($file->guessExtension() ?: 'bin');
                    abort_unless(in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf']), 422);
                    $path = $file->storeAs('guest-docs/'.$guest->hotel_id.'/'.$guest->id, Str::random(32).'.'.$ext, 'local');
                    $guest->documents()->create(['type' => $type, 'reservation_room_id' => $r->rooms->first()->id, 'path' => $path,
                        'original_name' => 'Online check-in – '.mb_substr(basename($file->getClientOriginalName()), 0, 150), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => null]);
                }
            }
            foreach ($r->rooms->where('status', 'reserved') as $stay) {
                $path = 'grc/'.$stay->hotel_id.'/'.$stay->id.'-'.Str::random(12).'.png';
                Storage::disk('local')->put($path, $sig);
                if ($stay->grc_signature_path) {
                    Storage::disk('local')->delete($stay->grc_signature_path);
                }
                $stay->forceFill(['grc_signature_path' => $path, 'grc_signed_at' => now()])->save();
            }
            $prefill = \App\Services\FormCService::isForeignNationality($data['nationality'] ?? $guest->nationality) === true
                ? collect($data['formc'] ?? [])->only(\App\Services\FormCService::PREFILL)->map(fn ($v) => is_string($v) ? trim($v) : $v)->filter(fn ($v) => $v !== null && $v !== '')->all() : [];
            if (! empty($data['visa_expiry']) && $prefill) {
                $prefill['visa_expires_on'] = $data['visa_expiry'];
            }
            $r->forceFill(['formc_prefill' => $prefill ?: $r->formc_prefill, 'eta' => $data['eta'] ?? $r->eta, 'prechecked_in_at' => now(),
                'special_request' => trim(($r->special_request ? $r->special_request."\n" : '').($data['special_request'] ?? '')) ?: null])->save();
        });
        ActivityLogger::log('reservations', 'online_checkin', 'Guest completed online check-in for '.$r->reservation_no, $r);

        return redirect()->route('checkin.show', $token)->with('success', 'Thank you! Your online check-in is complete. Just collect your key at the front desk on arrival.');
    }
}
