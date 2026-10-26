<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Floor;
use App\Models\GuestRequest;
use App\Models\InboxBroadcast;
use App\Models\InboxQuickReply;
use App\Models\InboxRule;
use App\Models\ReservationRoom;
use App\Models\User;
use App\Services\GuestPortalService;
use App\Services\InboxService as IS;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** v7.3 Guest messaging – the shared inbox, dashboard, broadcasts, quick replies, routing rules and settings. */
class InboxController extends Controller
{
    protected function attempt(callable $fn, string $ok)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first())->withInput();
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', is_string($r) ? $r : $ok);
    }

    /** the inbox list for the chosen folder / filters */
    protected function listQuery(Request $r)
    {
        $f = $r->query('f', 'open');
        $q = Conversation::with('guest', 'stay.room', 'assignee', 'lastMessage');
        match ($f) {
            'mine' => $q->whereIn('status', Conversation::ACTIVE)->where('assigned_to', auth()->id()),
            'unassigned' => $q->where('status', 'open')->whereNull('assigned_to'),
            'waiting' => $q->whereIn('status', ['open', 'pending'])->whereNotNull('awaiting_since'),
            'pending' => $q->where('status', 'pending'),
            'snoozed' => $q->where('status', 'snoozed'),
            'closed' => $q->where('status', 'closed'),
            'all' => $q,
            default => $q->whereIn('status', ['open', 'pending']),
        };
        foreach (['channel', 'department'] as $k) {
            if ($r->filled($k)) {
                $q->where($k, $r->query($k));
            }
        }
        if ($r->filled('q')) {
            $t = '%'.trim((string) $r->query('q')).'%';
            $q->where(fn ($w) => $w->where('number', 'like', $t)->orWhere('handle', 'like', $t)->orWhere('contact_name', 'like', $t)
                ->orWhereHas('guest', fn ($g) => $g->where('first_name', 'like', $t)->orWhere('last_name', 'like', $t))
                ->orWhereHas('stay.room', fn ($x) => $x->where('room_no', 'like', $t))
                ->orWhereHas('messages', fn ($m) => $m->where('body', 'like', $t)));
        }

        return $q->orderByRaw("CASE WHEN status IN ('open','pending') AND awaiting_since IS NOT NULL THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN priority = 'high' THEN 0 ELSE 1 END")->orderByDesc('last_message_at')->orderByDesc('id');
    }

    public function index(Request $r)
    {
        IS::sweep();
        $list = $this->listQuery($r)->paginate(40)->withQueryString();
        $c = null;
        if ($r->filled('c')) {
            $c = Conversation::with('guest', 'stay.room', 'stay.roomType', 'reservation', 'assignee', 'closer')->find((int) $r->query('c'));
        }
        $thread = collect();
        $extra = [];
        if ($c) {
            IS::markRead($c);
            $thread = ConversationMessage::with('user', 'broadcast')->where('conversation_id', $c->id)->orderBy('id')->get();
            $stay = $c->stay;
            $extra = [
                'requests' => $stay ? GuestRequest::where('reservation_room_id', $stay->id)->latest('id')->limit(6)->get() : collect(),
                'history' => $c->guest ? Conversation::where('guest_id', $c->guest_id)->where('id', '!=', $c->id)->latest('last_message_at')->limit(5)->get() : collect(),
                'balance' => $stay && $stay->status === 'checked_in' ? rescue(fn () => \App\Models\Folio::where('reservation_room_id', $stay->id)->where('status', 'open')->get()->sum(fn ($f) => $f->balance()), null, false) : null,
                'waOpen' => $c->channel !== 'whatsapp' || IS::whatsappWindowOpen($c),
            ];
        }
        $counts = [];
        foreach (['open', 'mine', 'unassigned', 'waiting', 'pending', 'snoozed'] as $f) {
            $counts[$f] = $this->listQuery(new Request(['f' => $f]))->count();
        }

        return view('inbox.index', ['list' => $list, 'c' => $c, 'thread' => $thread, 'counts' => $counts, 'f' => $r->query('f', 'open'), 'cfg' => IS::cfg(),
            'quick' => InboxQuickReply::where('is_active', true)->orderBy('shortcut')->get(), 'agents' => $this->agents(),
            'canReply' => IS::canReply($r->user()), 'categories' => GuestRequest::CATEGORIES, 'inhouse' => $this->inhouse()] + $extra);
    }

    protected function agents()
    {
        return User::visibleToHotel()->where('is_active', true)->orderBy('name')->get()->filter(fn ($u) => IS::canReply($u))->values();
    }

    protected function inhouse()
    {
        return ReservationRoom::with('room', 'guest', 'reservation.guest')->where('status', 'checked_in')->get()->sortBy(fn ($s) => $s->room?->room_no)->values();
    }

    /** live refresh: counts, the list's newest activity and new lines for the open conversation */
    public function poll(Request $r)
    {
        IS::sweep();
        $out = ['attention' => IS::attentionCount(), 'latest' => (string) Conversation::max('updated_at')];
        if ($r->filled('c') && ($c = Conversation::find((int) $r->query('c')))) {
            $after = (int) $r->query('after', 0);
            $new = ConversationMessage::with('user')->where('conversation_id', $c->id)->where('id', '>', $after)->orderBy('id')->get();
            if ($new->where('direction', 'in')->isNotEmpty()) {
                IS::markRead($c);
            }
            $out['messages'] = $new->map(fn ($m) => ['id' => $m->id, 'html' => view('inbox._msg', ['m' => $m, 'c' => $c])->render()])->values();
            $out['status'] = $c->status;
        }

        return response()->json($out)->header('Cache-Control', 'no-store');
    }

    public function dashboard()
    {
        return view('inbox.dashboard', ['d' => IS::dashboard(), 'cfg' => IS::cfg()]);
    }

    public function show(Conversation $conversation)
    {
        return redirect()->route('inbox.index', ['c' => $conversation->id, 'f' => $conversation->status === 'closed' ? 'closed' : ($conversation->status === 'snoozed' ? 'snoozed' : 'open')]);
    }

    public function reply(Request $r, Conversation $conversation)
    {
        $note = $r->input('mode') === 'note';

        return $this->attempt(function () use ($r, $conversation, $note) {
            $note ? IS::note($conversation, (string) $r->input('body'), $r->user())
                : IS::reply($conversation, (string) $r->input('body'), $r->user(), $r->integer('quick_reply_id') ?: null, $r->input('mode') === 'wait');

            return redirect()->route('inbox.index', ['c' => $conversation->id, 'f' => $r->input('f', 'open')])->with('success', $note ? 'Note added.' : 'Reply sent.');
        }, '');
    }

    public function assign(Request $r, Conversation $conversation)
    {
        $uid = $r->input('user_id') === 'me' ? $r->user()->id : ($r->integer('user_id') ?: null);

        return $this->attempt(fn () => IS::assign($conversation, $uid, $r->user()), 'Assignment updated.');
    }

    public function department(Request $r, Conversation $conversation)
    {
        return $this->attempt(fn () => IS::setDepartment($conversation, (string) $r->input('department'), $r->user()), 'Team updated.');
    }

    public function priority(Request $r, Conversation $conversation)
    {
        return $this->attempt(fn () => IS::setPriority($conversation, (string) $r->input('priority'), $r->user()), 'Priority updated.');
    }

    public function snooze(Request $r, Conversation $conversation)
    {
        $until = match ($r->input('for')) {
            '30m' => now()->addMinutes(30)->toDateTimeString(), '2h' => now()->addHours(2)->toDateTimeString(),
            'tomorrow' => now()->addDay()->setTime(8, 0)->toDateTimeString(), default => (string) $r->input('until'),
        };

        return $this->attempt(fn () => IS::snooze($conversation, $until, $r->user()), 'Snoozed.');
    }

    public function close(Request $r, Conversation $conversation)
    {
        return $this->attempt(function () use ($r, $conversation) {
            IS::close($conversation, $r->user(), $r->boolean('rate', true));

            return redirect()->route('inbox.index', ['f' => $r->input('f', 'open')])->with('success', "Conversation {$conversation->number} closed.");
        }, '');
    }

    public function reopen(Request $r, Conversation $conversation)
    {
        return $this->attempt(function () use ($r, $conversation) {
            IS::reopen($conversation, $r->user());

            return redirect()->route('inbox.index', ['c' => $conversation->id])->with('success', 'Conversation reopened.');
        }, '');
    }

    public function raise(Request $r, Conversation $conversation)
    {
        return $this->attempt(fn () => 'Guest request '.IS::raiseRequest($conversation, $r->only('category', 'item', 'notes', 'preferred_at'), $r->user())->request_no.' raised.', '');
    }

    public function link(Request $r, Conversation $conversation)
    {
        return $this->attempt(fn () => IS::linkGuest($conversation, $r->integer('guest_id'), $r->user()), 'Conversation linked to the guest.');
    }

    /** start a conversation with an in-house guest (portal chat, SMS or e-mail) */
    public function start(Request $r)
    {
        return $this->attempt(function () use ($r) {
            abort_unless(IS::canReply($r->user()), 403);
            $stay = ReservationRoom::with('guest', 'reservation.guest')->where('status', 'checked_in')->find($r->integer('stay_id')) ?? throw new RuntimeException('Choose an in-house guest.');
            $g = $stay->guest ?? $stay->reservation?->guest;
            $ch = (string) $r->input('channel');
            [$channel, $handle] = match ($ch) {
                'portal' => IS::portalOn() && $stay->portal_token ? ['portal', 'stay:'.$stay->id] : throw new RuntimeException('Portal chat is not available for this stay.'),
                'sms' => ($n = \App\Services\MessageService::normalise($g?->phone)) ? ['sms', $n] : throw new RuntimeException('The guest has no valid mobile number.'),
                'email' => $g?->email && filter_var($g->email, FILTER_VALIDATE_EMAIL) ? ['email', mb_strtolower($g->email)] : throw new RuntimeException('The guest has no e-mail address.'),
                default => throw new RuntimeException('Choose portal chat, SMS or e-mail (WhatsApp can only answer a guest who wrote first).'),
            };
            $c = IS::conversationFor($channel, $handle, $g, $stay);

            return redirect()->route('inbox.index', ['c' => $c->id, 'f' => 'all'])->with('success', 'Write your message below.');
        }, '');
    }

    public function guestSearch(Request $r)
    {
        $t = '%'.trim((string) $r->query('q')).'%';
        $list = strlen(trim((string) $r->query('q'))) < 2 ? collect() : \App\Models\Guest::where(fn ($w) => $w->where('first_name', 'like', $t)->orWhere('last_name', 'like', $t)->orWhere('phone', 'like', $t)->orWhere('email', 'like', $t))
            ->orderBy('last_name')->limit(12)->get();

        return response()->json($list->map(fn ($g) => ['id' => $g->id, 'label' => $g->full_name.($g->phone ? ' · '.$g->phone : '').($g->email ? ' · '.$g->email : '')]));
    }

    // ── broadcasts ──

    public function broadcasts()
    {
        return view('inbox.broadcasts', ['list' => InboxBroadcast::with('creator', 'floor')->latest('id')->paginate(25), 'floors' => Floor::orderBy('id')->get(), 'cfg' => IS::cfg(),
            'canSend' => IS::canBroadcast(auth()->user())]);
    }

    public function broadcastPreview(Request $r)
    {
        try {
            $stays = IS::audience((string) $r->query('audience', 'inhouse'), $r->integer('floor_id') ?: null);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $reach = [];
        foreach ($stays as $s) {
            [$ch, $why] = IS::route($s, (string) $r->query('channel', 'best'));
            $reach[$ch ?? 'skip: '.$why] = ($reach[$ch ?? 'skip: '.$why] ?? 0) + 1;
        }

        return response()->json(['count' => $stays->count(), 'reach' => $reach]);
    }

    public function broadcastStore(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $b = IS::broadcast($r->only('title', 'body', 'audience', 'floor_id', 'channel'), $r->user());

            return redirect()->route('inbox.broadcast', $b)->with('success', "Broadcast sent to {$b->sent} guest(s)".($b->skipped ? ", {$b->skipped} could not be reached" : '').($b->failed ? ", {$b->failed} failed" : '').'.');
        }, '');
    }

    public function broadcast(InboxBroadcast $broadcast)
    {
        $broadcast->load('creator', 'floor');
        $msgs = ConversationMessage::with('conversation.guest', 'conversation.stay.room')->where('broadcast_id', $broadcast->id)->orderBy('id')->get();

        return view('inbox.broadcast', ['b' => $broadcast, 'msgs' => $msgs]);
    }

    // ── quick replies ──

    public function replies()
    {
        return view('inbox.replies', ['list' => InboxQuickReply::with('creator')->orderBy('shortcut')->get(), 'vars' => array_keys(IS::vars(null))]);
    }

    protected function qrData(Request $r): array
    {
        $r->merge(['shortcut' => ltrim(trim((string) $r->input('shortcut')), '/')]);
        $d = $r->validate(['shortcut' => 'required|string|max:30|regex:/^[a-z0-9_-]+$/i', 'title' => 'required|string|max:80', 'body' => 'required|string|max:1500',
            'department' => 'nullable|in:'.implode(',', array_keys(Conversation::DEPARTMENTS))], ['shortcut.regex' => 'The shortcut may only contain letters, digits, - and _.']);

        return $d + ['shortcut' => $d['shortcut'], 'is_active' => $r->boolean('is_active')];
    }

    public function replyStore(Request $r)
    {
        return $this->attempt(function () use ($r) {
            $d = $this->qrData($r);
            $d['shortcut'] = mb_strtolower(ltrim($d['shortcut'], '/'));
            if (InboxQuickReply::where('shortcut', $d['shortcut'])->exists()) {
                throw new RuntimeException("The shortcut /{$d['shortcut']} is already used.");
            }
            InboxQuickReply::create($d + ['created_by' => $r->user()->id, 'uses' => 0]);
        }, 'Quick reply saved.');
    }

    public function replyUpdate(Request $r, InboxQuickReply $quickreply)
    {
        return $this->attempt(function () use ($r, $quickreply) {
            $d = $this->qrData($r);
            $d['shortcut'] = mb_strtolower(ltrim($d['shortcut'], '/'));
            if (InboxQuickReply::where('shortcut', $d['shortcut'])->where('id', '!=', $quickreply->id)->exists()) {
                throw new RuntimeException("The shortcut /{$d['shortcut']} is already used.");
            }
            $quickreply->update($d);
        }, 'Quick reply updated.');
    }

    public function replyDestroy(InboxQuickReply $quickreply)
    {
        $quickreply->delete();

        return back()->with('success', 'Quick reply deleted.');
    }

    // ── routing rules ──

    public function rules()
    {
        return view('inbox.rules', ['list' => InboxRule::orderBy('sort')->orderBy('id')->get(), 'categories' => GuestRequest::CATEGORIES, 'vars' => array_keys(IS::vars(null))]);
    }

    protected function ruleData(Request $r): array
    {
        $d = $r->validate(['name' => 'required|string|max:80', 'keywords' => 'required|string|max:400', 'department' => 'nullable|in:'.implode(',', array_keys(Conversation::DEPARTMENTS)),
            'request' => 'nullable|string|max:90', 'reply' => 'nullable|string|max:1000', 'sort' => 'nullable|integer|min:0|max:999']);
        $cat = $item = null;
        if (! empty($d['request'])) {
            [$cat, $item] = array_pad(explode('|', $d['request'], 2), 2, null);
            if (! isset(GuestRequest::CATEGORIES[$cat]) || ! in_array($item, GuestPortalService::items($cat), true)) {
                throw new RuntimeException('Choose a request from the list.');
            }
        }
        $words = collect(explode(',', $d['keywords']))->map(fn ($w) => trim($w))->filter()->unique()->implode(', ');
        if ($words === '') {
            throw new RuntimeException('Enter at least one keyword.');
        }
        if (! $cat && empty($d['department']) && empty($d['reply']) && ! $r->boolean('urgent')) {
            throw new RuntimeException('A rule has to do something: route, mark urgent, raise a request or reply.');
        }

        return ['name' => $d['name'], 'keywords' => $words, 'department' => $d['department'] ?? null, 'urgent' => $r->boolean('urgent'), 'request_category' => $cat,
            'request_item' => $item, 'reply' => trim((string) ($d['reply'] ?? '')) ?: null, 'sort' => (int) ($d['sort'] ?? 10), 'is_active' => $r->boolean('is_active')];
    }

    public function ruleStore(Request $r)
    {
        return $this->attempt(fn () => InboxRule::create($this->ruleData($r)), 'Rule saved.');
    }

    public function ruleUpdate(Request $r, InboxRule $inboxrule)
    {
        return $this->attempt(fn () => $inboxrule->update($this->ruleData($r)), 'Rule updated.');
    }

    public function ruleDestroy(InboxRule $inboxrule)
    {
        $inboxrule->delete();

        return back()->with('success', 'Rule deleted.');
    }

    /** try a sample message against the rules (no message is stored) */
    public function ruleTest(Request $r)
    {
        $text = (string) $r->input('text');
        $rule = InboxRule::where('is_active', true)->orderBy('sort')->orderBy('id')->get()->first(fn ($x) => $x->matches($text));

        return back()->withInput()->with($rule ? 'success' : 'error', $rule ? "“{$rule->name}” would handle it: ".$rule->actionsLabel().'.' : 'No rule matches – the message would go to the default team for a person to answer.');
    }

    // ── settings ──

    public function settings()
    {
        return view('inbox.settings', ['cfg' => IS::cfg(), 'hasSecret' => (bool) IS::webhookSecret(),
            'hooks' => ['generic' => route('inbox.webhook', ['generic', hotel()->code]), 'twilio' => route('inbox.webhook', ['twilio', hotel()->code])]]);
    }

    public function saveSettings(Request $r)
    {
        return $this->attempt(fn () => IS::saveSettings($r->all(), $r->user()), 'Guest messaging settings saved.');
    }

    public function secret(Request $r)
    {
        return $this->attempt(fn () => redirect()->route('inbox.settings')->with('success', 'New webhook secret created – copy it into your gateway now; it is shown only once.')
            ->with('secret', IS::rotateSecret($r->user())), '');
    }

    /** provider → hotel, stateless: authenticated by the signature, never by a session */
    public function webhook(Request $r, string $provider, string $code)
    {
        $hotel = \App\Models\Hotel::where('code', $code)->where('is_active', true)->first();
        abort_unless($hotel, 404);
        \App\Support\Tenant::set($hotel);
        [$status, $body] = IS::webhook($provider, $r->getContent(), $r->post(), array_change_key_case(array_map(fn ($v) => $v[0] ?? '', $r->headers->all())), $r->fullUrl());

        if ($provider === 'twilio' && $status === 200) {
            return response('<?xml version="1.0" encoding="UTF-8"?><Response></Response>', 200, ['Content-Type' => 'text/xml']);
        }

        return response()->json($body, $status);
    }
}
