<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\Visitor;
use App\Services\EInvoiceService;
use App\Support\Tenant;

/** v4.2 – public visitor pass (link the guest shares). Shows the pass number + QR only – never the room number. */
class VisitorPassController extends Controller
{
    public function show(string $token)
    {
        $v = Visitor::withoutGlobalScopes()->where('pre_token', $token)->firstOrFail();
        $hotel = Hotel::find($v->hotel_id);
        abort_unless($hotel?->is_active, 404);
        Tenant::set($hotel);
        $v = Visitor::findOrFail($v->id);

        return response()->view('public.visitor-pass', ['v' => $v, 'hotel' => $hotel, 'qr' => EInvoiceService::qrSvg($v->pass_no),
            'hostFirst' => trim(explode(' ', preg_replace('/^(Mr|Mrs|Ms|Miss|Dr|Prof)\.?\s+/i', '', (string) $v->host_name))[0] ?? '')])
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
    }
}
