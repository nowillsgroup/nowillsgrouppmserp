<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\RfqQuote;
use App\Models\RfqSupplier;
use App\Services\ProcurementService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * v4.8 – supplier's private quotation page /quote/{token}. The 40-character token in the RFQ e-mail is the only
 * credential; it shows one RFQ to one supplier and accepts / revises their quote until the deadline.
 */
class RfqPublicController extends Controller
{
    protected function invite(string $token): RfqSupplier
    {
        $inv = RfqSupplier::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        $hotel = Hotel::find($inv->hotel_id);
        abort_unless($hotel?->is_active, 404);
        Tenant::set($hotel);
        $inv = RfqSupplier::with('rfq.lines.item', 'supplier')->findOrFail($inv->id);
        abort_if($inv->rfq->status === 'draft', 404);

        return $inv;
    }

    protected function page(RfqSupplier $inv, array $extra = [])
    {
        if (! $inv->viewed_at) {
            $inv->forceFill(['viewed_at' => now()])->saveQuietly();
        }
        $quote = RfqQuote::with('lines')->where('rfq_id', $inv->rfq_id)->where('supplier_id', $inv->supplier_id)->first();

        return response()->view('public.rfq-quote', $extra + ['inv' => $inv, 'rfq' => $inv->rfq, 'quote' => $quote, 'hotel' => hotel(), 'open' => $inv->rfq->acceptingQuotes()])
            ->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow')->header('Cache-Control', 'no-store, private');
    }

    public function show(string $token)
    {
        return $this->page($this->invite($token));
    }

    public function submit(Request $request, string $token)
    {
        $inv = $this->invite($token);
        $data = $request->validate(ProcurementController::quoteRules());
        try {
            ProcurementService::submitQuote($inv, $data, 'portal');
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('rfq.public', $token)->with('success', 'Thank you – your quotation has been received. You can revise it until '.$inv->rfq->respond_by->format('d M Y').'.');
    }

    public function decline(Request $request, string $token)
    {
        $inv = $this->invite($token);
        $data = $request->validate(['reason' => 'nullable|string|max:255']);
        try {
            ProcurementService::decline($inv, $data['reason'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('rfq.public', $token)->with('success', 'Thank you for letting us know.');
    }
}
