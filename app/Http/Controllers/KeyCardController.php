<?php

namespace App\Http\Controllers;

use App\Models\InterfaceCommand;
use App\Models\KeyCard;
use App\Models\KeyLoan;
use App\Models\MasterKey;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Services\InterfaceService;
use App\Services\KeyCardService as Keys;
use App\Support\Tenant;
use Illuminate\Http\Request;
use RuntimeException;

/** v3.7 – door-lock key cards (encode / duplicate / lost / return / read) and the master-key register. */
class KeyCardController extends Controller
{
    public const TABS = ['active' => 'Working cards', 'history' => 'Card history', 'master' => 'Master keys', 'register' => 'Key register'];

    protected function fail(RuntimeException $e)
    {
        return back()->withInput()->with('error', $e->getMessage());
    }

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'active';
        $cards = $keys = $loans = null;
        if (in_array($tab, ['active', 'history'], true)) {
            $cards = KeyCard::with('room', 'stay.guest', 'stay.reservation', 'issuer')
                ->when($tab === 'active', fn ($q) => $q->where('status', 'active'), fn ($q) => $q->where('status', '!=', 'active'))
                ->when(trim((string) $request->query('q')), fn ($q, $t) => $q->where(fn ($w) => $w->where('card_no', 'like', "%{$t}%")->orWhere('holder', 'like', "%{$t}%")->orWhereHas('room', fn ($r) => $r->where('room_no', $t))))
                ->orderByDesc('id')->paginate(50)->withQueryString();
        } elseif ($tab === 'master') {
            $keys = MasterKey::with('openLoan')->orderBy('level')->orderBy('key_no')->get();
        } else {
            $loans = KeyLoan::with('key', 'issuer', 'receiver')->when($request->query('status'), fn ($q, $s) => $s === 'overdue' ? $q->where('status', 'out')->where('due_at', '<', now()) : $q->where('status', $s))
                ->orderByDesc('out_at')->paginate(50)->withQueryString();
        }
        $inHouse = ReservationRoom::with('room', 'guest', 'reservation.guest')->where('status', 'checked_in')->whereNotNull('room_id')->get()
            ->sortBy(fn ($s) => $s->room?->room_no)->values();
        $cardCounts = KeyCard::where('status', 'active')->whereIn('reservation_room_id', $inHouse->pluck('id'))->selectRaw('reservation_room_id, count(*) n')->groupBy('reservation_room_id')->pluck('n', 'reservation_room_id')->all();

        return view('keys.index', [
            'tab' => $tab, 'cards' => $cards, 'keys' => $keys, 'loans' => $loans, 'cfg' => InterfaceService::cfg(), 'counts' => InterfaceService::counts(),
            'inHouse' => $inHouse, 'cardCounts' => $cardCounts, 'read' => session('read_card') ? KeyCard::with('room', 'stay.guest')->find(session('read_card')) : null,
            'staff' => User::where('hotel_id', Tenant::id())->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'lastCommands' => InterfaceCommand::where('system', 'lock')->with('user')->latest('id')->limit(8)->get(),
        ]);
    }

    public function issue(Request $request)
    {
        $max = InterfaceService::cfg()['lock_max_cards'];
        $data = $request->validate(['stay_id' => 'required|integer|owned:reservation_rooms', 'count' => "required|integer|min:1|max:{$max}", 'mode' => 'required|in:new,duplicate',
            'areas' => 'nullable|array', 'areas.*' => 'string|max:20', 'holder' => 'nullable|string|max:80']);
        try {
            $cards = Keys::issue(ReservationRoom::findOrFail($data['stay_id']), (int) $data['count'], $data['mode'], $request->has('areas') ? ($data['areas'] ?? []) : null, $data['holder'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }
        $c = $cards->first();

        return back()->with('success', $cards->count().' card(s) encoded for room '.$c->room->room_no.' ('.$cards->pluck('card_no')->implode(', ').') – valid until '.$c->valid_until->format('d M H:i').'.');
    }

    public function lost(Request $request, KeyCard $card)
    {
        $data = $request->validate(['charge_fee' => 'nullable|boolean', 'fee' => 'nullable|numeric|min:0|max:100000', 'reissue' => 'nullable|boolean']);
        try {
            $new = Keys::markLost($card, $request->boolean('charge_fee'), $request->boolean('reissue'), isset($data['fee']) ? (float) $data['fee'] : null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$card->card_no} cancelled as lost".($new->isNotEmpty() ? " – {$new->count()} replacement card(s) encoded; the old cards stop working on first use of the new key" : '').'.');
    }

    public function returnCard(KeyCard $card)
    {
        try {
            Keys::returnCard($card);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$card->card_no} returned.");
    }

    public function read(Request $request)
    {
        $data = $request->validate(['serial' => 'required|string|max:30']);
        $card = Keys::read($data['serial']);
        if (! $card) {
            return back()->with('warning', 'Card '.strtoupper($data['serial']).' is not known to the PMS (blank, staff or another property\'s card).');
        }

        return back()->with('read_card', $card->id);
    }

    public function saveKey(Request $request, ?MasterKey $mkey = null)
    {
        $data = $request->validate(['key_no' => 'required|string|max:20', 'name' => 'required|string|max:80', 'level' => 'required|in:'.implode(',', array_keys(MasterKey::LEVELS)),
            'scope' => 'nullable|string|max:120', 'department' => 'nullable|string|max:40', 'status' => 'nullable|in:available,retired']);
        try {
            $k = Keys::saveKey($data, $mkey);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return redirect()->route('keys.index', ['tab' => 'master'])->with('success', "Master key {$k->key_no} saved.");
    }

    public function signOut(Request $request, MasterKey $mkey)
    {
        $data = $request->validate(['user_id' => 'nullable|integer|owned:users', 'holder' => 'nullable|string|max:80', 'purpose' => 'nullable|string|max:150', 'hours' => 'nullable|integer|min:1|max:24']);
        try {
            $loan = Keys::signOut($mkey, $data['user_id'] ?? null, $data['holder'] ?? null, $data['purpose'] ?? null, isset($data['hours']) ? (int) $data['hours'] : null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$mkey->key_no} signed out to {$loan->holder} – due back ".$loan->due_at->format('d M H:i').'.');
    }

    public function signIn(Request $request, KeyLoan $loan)
    {
        $data = $request->validate(['note' => 'nullable|string|max:150']);
        try {
            Keys::signIn($loan, $data['note'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('success', "{$loan->key->key_no} signed in.");
    }

    public function lostKey(Request $request, KeyLoan $loan)
    {
        $data = $request->validate(['note' => 'required|string|min:5|max:150']);
        try {
            Keys::reportLost($loan, $data['note']);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return back()->with('warning', "{$loan->key->key_no} recorded as LOST – security has been alerted in the log book; re-key the affected doors.");
    }
}
