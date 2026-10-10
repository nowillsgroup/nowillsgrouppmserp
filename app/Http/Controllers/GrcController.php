<?php

namespace App\Http\Controllers;

use App\Models\Guest;
use App\Models\GuestDocument;
use App\Models\ReservationRoom;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Electronic guest registration card: the guest signs on a tablet or touch screen, and ID scans are kept
 * as private files (never web-accessible; served only to authenticated staff of the same hotel).
 */
class GrcController extends Controller
{
    public function sign(ReservationRoom $stay)
    {
        abort_if(in_array($stay->status, ['cancelled', 'no_show']), 422, 'This stay is '.$stay->status.'.');
        $stay->load('reservation.guest.documents', 'reservation.company', 'room', 'roomType', 'ratePlan');

        return view('frontdesk.grc-sign', ['stay' => $stay, 'g' => $stay->reservation->guest]);
    }

    public function saveSignature(Request $request, ReservationRoom $stay)
    {
        abort_if(in_array($stay->status, ['cancelled', 'no_show']), 422);
        $data = $request->validate(['signature' => ['required', 'string', 'max:700000', 'starts_with:data:image/png;base64,'], 'accept' => 'accepted']);
        $bin = base64_decode(substr($data['signature'], strlen('data:image/png;base64,')), true);
        $info = $bin ? @getimagesizefromstring($bin) : false;
        if (! $info || $info[2] !== IMAGETYPE_PNG || $info[0] > 2000 || $info[1] > 1000 || strlen($bin) < 500) {
            return back()->with('error', 'The signature could not be read. Please sign again.');
        }
        $path = 'grc/'.$stay->hotel_id.'/'.$stay->id.'-'.Str::random(12).'.png';
        Storage::disk('local')->put($path, $bin);
        if ($stay->grc_signature_path) {
            Storage::disk('local')->delete($stay->grc_signature_path);
        }
        $stay->forceFill(['grc_signature_path' => $path, 'grc_signed_at' => now()])->save();
        ActivityLogger::log('frontdesk', 'grc_sign', 'Guest signed e-GRC for '.$stay->reservation->reservation_no, $stay);

        return back()->with('success', 'Signature saved to the registration card.');
    }

    public function signature(ReservationRoom $stay)
    {
        abort_unless($stay->grc_signature_path && Storage::disk('local')->exists($stay->grc_signature_path), 404);
        \App\Services\PrivacyService::logAccess($stay->guest ?? $stay->reservation?->guest, 'signature', 'Registration card signature');

        return response()->file(Storage::disk('local')->path($stay->grc_signature_path), ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store']);
    }

    public function upload(Request $request, Guest $guest)
    {
        abort_if($guest->isPrivacyLocked(), 403, 'This profile has been erased or restricted under a privacy request.');
        $data = $request->validate([
            'type' => 'required|in:'.implode(',', array_keys(GuestDocument::TYPES)),
            'file' => 'required|file|max:5120|mimes:jpg,jpeg,png,webp,pdf|mimetypes:image/jpeg,image/png,image/webp,application/pdf',
            'reservation_room_id' => 'nullable|integer|owned:reservation_rooms',
        ]);
        $file = $request->file('file');
        $ext = strtolower($file->guessExtension() ?: 'bin');
        abort_unless(in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf']), 422);
        $path = $file->storeAs('guest-docs/'.$guest->hotel_id.'/'.$guest->id, Str::random(32).'.'.$ext, 'local');
        $doc = $guest->documents()->create(['type' => $data['type'], 'reservation_room_id' => $data['reservation_room_id'] ?? null, 'path' => $path,
            'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 190), 'mime' => $file->getMimeType(), 'size' => $file->getSize(), 'uploaded_by' => auth()->id()]);
        ActivityLogger::log('guests', 'document', 'Uploaded '.GuestDocument::TYPES[$doc->type].' for '.$guest->full_name, $guest);

        return back()->with('success', GuestDocument::TYPES[$doc->type].' uploaded.');
    }

    public function document(GuestDocument $document)
    {
        abort_unless(Storage::disk('local')->exists($document->path), 404);
        ActivityLogger::log('guests', 'document_view', 'Viewed '.(GuestDocument::TYPES[$document->type] ?? $document->type).' of guest #'.$document->guest_id, $document);
        \App\Services\PrivacyService::logAccess($document->guest, 'document', GuestDocument::TYPES[$document->type] ?? $document->type);

        return response()->file(Storage::disk('local')->path($document->path), [
            'Content-Type' => $document->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="'.$document->type.'-'.$document->guest_id.'.'.pathinfo($document->path, PATHINFO_EXTENSION).'"',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    public function destroyDocument(GuestDocument $document)
    {
        Storage::disk('local')->delete($document->path);
        $document->delete();
        ActivityLogger::log('guests', 'document_delete', 'Deleted '.(GuestDocument::TYPES[$document->type] ?? $document->type).' of guest #'.$document->guest_id, $document);

        return back()->with('success', 'Document deleted.');
    }
}
