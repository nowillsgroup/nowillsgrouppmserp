<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\LoyaltyEntry;
use App\Services\ActivityLogger;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;

class LoyaltyController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->input('q'));
        $members = Guest::where('loyalty_points', '>', 0)
            ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")))
            ->orderByDesc('loyalty_points')->paginate(25)->withQueryString();
        $monthStart = bdate()->copy()->startOfMonth();

        return view('loyalty.index', [
            'members' => $members, 'q' => $q,
            'ledger' => LoyaltyEntry::with('guest', 'folio', 'user')->latest('id')->limit(30)->get(),
            'stats' => [
                'members' => Guest::where('loyalty_points', '>', 0)->count(),
                'outstanding' => (int) Guest::sum('loyalty_points'),
                'earned_mtd' => (int) LoyaltyEntry::where('type', 'earn')->where('created_at', '>=', $monthStart)->sum('points'),
                'redeemed_mtd' => (int) -LoyaltyEntry::where('type', 'redeem')->where('created_at', '>=', $monthStart)->sum('points'),
            ],
            'enabled' => LoyaltyService::enabled(), 'spendPer' => LoyaltyService::spendPerPoint(), 'pointValue' => LoyaltyService::pointValue(), 'minRedeem' => LoyaltyService::minRedeem(),
            'guests' => Guest::orderBy('first_name')->limit(500)->get(['id', 'first_name', 'last_name', 'phone', 'loyalty_points']),
        ]);
    }

    public function adjust(Request $request, Guest $guest)
    {
        $data = $request->validate(['points' => 'required|integer|between:-100000,100000|not_in:0', 'reason' => 'required|string|max:150']);
        LoyaltyService::adjust($guest, (int) $data['points'], $data['reason']);
        ActivityLogger::log('loyalty', 'adjust', "Loyalty adjustment {$data['points']} pts for {$guest->full_name}: {$data['reason']}", $guest);

        return back()->with('success', 'Points adjusted for '.$guest->full_name.'.');
    }

    public function redeem(Request $request, Folio $folio)
    {
        $data = $request->validate(['points' => 'required|integer|min:1|max:10000000']);
        $tx = LoyaltyService::redeem($folio, (int) $data['points']);
        ActivityLogger::log('loyalty', 'redeem', "Redeemed {$data['points']} pts (".money($tx->amount).") on {$folio->folio_no}", $folio);

        return back()->with('success', "{$data['points']} points redeemed – ".money($tx->amount).' discount applied.');
    }
}
