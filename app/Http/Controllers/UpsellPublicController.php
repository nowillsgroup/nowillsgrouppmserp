<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\UpsellOffer;
use App\Models\UpsellOrder;
use App\Services\UpsellService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use RuntimeException;

/** v4.1 – the guest's private "enhance your stay" page (link in the pre-arrival e-mail, online check-in and the guest portal). */
class UpsellPublicController extends Controller
{
    protected function reservation(string $token): Reservation
    {
        $r = Reservation::withoutGlobalScopes()->with('hotel')->where('offer_token', $token)->firstOrFail();
        abort_unless($r->hotel?->is_active, 404);
        Tenant::set($r->hotel);
        abort_unless(UpsellService::enabled(), 404);
        $r->load('guest');

        return $r;
    }

    /** In-house guests see the portal offers (add-ons, late check-out); everyone else the pre-arrival ones. */
    protected function channel(Reservation $r): string
    {
        return $r->rooms()->where('status', 'checked_in')->exists() ? 'portal' : 'pre_arrival';
    }

    protected function closedReason(Reservation $r): ?string
    {
        return match (true) {
            in_array($r->status, ['cancelled', 'no_show'], true) => 'This reservation is '.str_replace('_', '-', $r->status).'. Please contact the hotel.',
            $r->status === 'checked_out' || $r->departure_date->lt(bdate()) => 'Your stay has ended – we hope to welcome you again soon.',
            default => null,
        };
    }

    public function show(string $token)
    {
        $r = $this->reservation($token);
        $closed = $this->closedReason($r);
        $channel = $this->channel($r);
        $groups = $closed ? collect() : UpsellService::reservationOffers($r, $channel);
        if (! $closed) {
            UpsellService::opened($r, $channel, $groups->flatMap(fn ($g) => $g['offers'])->map(fn ($x) => $x['offer']->id));
        }

        return view('public.offers', ['hotel' => Tenant::hotel(), 'r' => $r, 'token' => $token, 'closed' => $closed, 'channel' => $channel, 'groups' => $groups,
            'orders' => $r->upsellOrders()->with('offer', 'stay.roomType')->orderByDesc('id')->get(), 'cfg' => UpsellService::cfg()]);
    }

    public function order(Request $request, string $token)
    {
        $r = $this->reservation($token);
        abort_if($this->closedReason($r), 410);
        $d = $request->validate(['offer_id' => 'required|integer', 'stay_id' => 'required|integer', 'note' => 'nullable|string|max:255']);
        $stay = $r->rooms()->whereKey($d['stay_id'])->firstOrFail();
        $offer = UpsellOffer::findOrFail($d['offer_id']);
        try {
            $o = UpsellService::place($stay, $offer, $this->channel($r), $d['note'] ?? null);
        } catch (RuntimeException $e) {
            return redirect()->route('offers.show', $token)->withErrors(['offer' => $e->getMessage()]);
        }

        return redirect()->route('offers.show', $token)->with('success', $o->status === 'confirmed'
            ? "Done! {$offer->name} has been added to your stay (".money($o->amount).' + taxes, charged with your room).'
            : "Thank you – we have received your request for {$offer->name}. The front desk will confirm it before your arrival.");
    }

    public function cancel(string $token, int $oid)
    {
        $r = $this->reservation($token);
        $order = UpsellOrder::where('reservation_id', $r->id)->findOrFail($oid);
        try {
            UpsellService::cancel($order, 'Cancelled by the guest online', true);
        } catch (RuntimeException $e) {
            return redirect()->route('offers.show', $token)->withErrors(['offer' => $e->getMessage()]);
        }

        return redirect()->route('offers.show', $token)->with('success', "{$order->offer->name} has been removed from your stay.");
    }
}
