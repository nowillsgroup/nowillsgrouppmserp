<?php

namespace App\Http\Controllers;

use App\Models\DmAdStat;
use App\Models\DmCampaign;
use App\Models\DmChannel;
use App\Models\DmChannelStat;
use App\Models\DmInfluencer;
use App\Models\DmLink;
use App\Models\DmLinkClick;
use App\Models\DmPost;
use App\Models\PromoCode;
use App\Models\Reservation;
use App\Models\User;
use App\Services\DigitalMarketingService as DM;
use Carbon\Carbon;
use Illuminate\Http\Request;
use RuntimeException;

/** v6.2 Digital marketing: dashboard, social content calendar with approval, paid campaigns, tracked links, channels & listings, influencers. */
class DigitalMarketingController extends Controller
{
    protected function attempt(callable $fn, string $ok)
    {
        try {
            $r = $fn();
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return $r instanceof \Symfony\Component\HttpFoundation\Response ? $r : back()->with('success', is_string($r) ? $r : $ok);
    }

    protected function month(Request $r): Carbon
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $r->query('month')) ? Carbon::parse($r->query('month').'-01') : bdate()->copy()->startOfMonth();
    }

    protected function people()
    {
        return User::where('hotel_id', hotel()->id)->where('is_active', true)->orderBy('name')->get()
            ->filter(fn ($u) => ((! $u->is_super_admin && ! $u->is_group_admin) || $u->id === auth()->id()) && ($u->hasPermission('digital.content') || $u->hasPermission('digital.manage')))->values();
    }

    protected function lookups(): array
    {
        return ['socialChannels' => DmChannel::where('is_active', true)->orderBy('name')->get()->filter(fn ($c) => $c->kind() === 'social')->values(),
            'campaignList' => DmCampaign::whereIn('status', ['draft', 'active', 'paused'])->orderBy('name')->get(), 'linkList' => DmLink::where('is_active', true)->orderBy('name')->get(),
            'people' => $this->people()];
    }

    // ------------------------------------------------------------------ dashboard

    public function index(Request $r)
    {
        $month = $this->month($r);

        return view('digital.index', ['month' => $month, 'k' => DM::kpis($month), 'alerts' => DM::alerts(), 'trend' => DM::trend(6),
            'upcoming' => DmPost::with('author')->whereIn('status', ['review', 'approved'])->where('scheduled_at', '>=', now()->subDay())->orderBy('scheduled_at')->limit(8)->get(),
            'topPosts' => DmPost::where('status', 'published')->where('published_at', '>=', now()->subDays(60))->orderByDesc('engagements')->limit(5)->get(),
            'campaigns' => DmCampaign::with('channel')->where('status', 'active')->orderBy('start_date')->get()->map(fn ($c) => [$c, DM::campaignStats($c)]),
            'channels' => DmChannel::where('is_active', true)->orderBy('name')->get()]);
    }

    // ------------------------------------------------------------------ content calendar

    public function calendar(Request $r)
    {
        $month = $this->month($r);
        $status = array_key_exists((string) $r->query('status'), DmPost::STATUSES) ? $r->query('status') : null;
        $list = DmPost::with('author', 'reviewer')->when($status, fn ($q) => $q->where('status', $status), fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->whereBetween('scheduled_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])->orderBy('scheduled_at')->get();

        return view('digital.calendar', ['month' => $month, 'status' => $status, 'days' => DM::calendar($month, $status), 'list' => $list,
            'queue' => DmPost::with('author')->where('status', 'review')->orderBy('scheduled_at')->get(), 'channelsById' => DmChannel::get()->keyBy('id')] + $this->lookups());
    }

    public function post(DmPost $dmpost)
    {
        return view('digital.post', ['p' => $dmpost->load('author', 'reviewer', 'campaign', 'link'), 'chs' => $dmpost->channelModels(),
            'canEdit' => in_array($dmpost->status, ['draft', 'rejected'], true) && (DM::canManage(auth()->user()) || ($dmpost->author_id === auth()->id() && DM::canContent(auth()->user())))] + $this->lookups());
    }

    public function postStore(Request $r)
    {
        try {
            $p = DM::savePost($r->all(), null, $r->user());
            $msg = 'Draft saved.';
            if ($r->boolean('submit')) {
                $msg = DM::submitPost($p, $r->user());
            }
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('digital.post', $p)->with('success', $msg);
    }

    public function postUpdate(Request $r, DmPost $dmpost)
    {
        return $this->attempt(function () use ($r, $dmpost) {
            DM::savePost($r->all(), $dmpost, $r->user());

            return $r->boolean('submit') ? DM::submitPost($dmpost->fresh(), $r->user()) : 'Post updated.';
        }, 'Post updated.');
    }

    public function postSubmit(Request $r, DmPost $dmpost)
    {
        return $this->attempt(fn () => DM::submitPost($dmpost, $r->user()), 'Sent for approval.');
    }

    public function postReview(Request $r, DmPost $dmpost)
    {
        return $this->attempt(fn () => DM::reviewPost($dmpost, (string) $r->decision, $r->note, $r->user()), 'Saved.');
    }

    public function postPublish(Request $r, DmPost $dmpost)
    {
        return $this->attempt(fn () => DM::publishPost($dmpost, $r->post_url, $r->user()), 'Marked as published.');
    }

    public function postStats(Request $r, DmPost $dmpost)
    {
        return $this->attempt(fn () => DM::recordPostStats($dmpost, $r->all(), $r->user()), 'Results saved.');
    }

    public function postCancel(Request $r, DmPost $dmpost)
    {
        return $this->attempt(fn () => DM::cancelPost($dmpost, $r->user()), 'Post cancelled.');
    }

    // ------------------------------------------------------------------ paid campaigns

    public function campaigns(Request $r)
    {
        $status = array_key_exists((string) $r->query('status'), DmCampaign::STATUSES) ? $r->query('status') : null;
        $rows = DmCampaign::with('channel', 'owner')->when($status, fn ($q) => $q->where('status', $status))->orderByRaw("FIELD(status,'active','paused','draft','ended')")->orderByDesc('start_date')->get()
            ->map(fn ($c) => [$c, DM::campaignStats($c)]);

        return view('digital.campaigns', ['rows' => $rows, 'status' => $status, 'adChannels' => DmChannel::orderBy('name')->get(), 'promos' => PromoCode::orderBy('code')->get(), 'people' => $this->people()]);
    }

    public function campaign(DmCampaign $dmcampaign)
    {
        $c = $dmcampaign->load('channel', 'owner', 'promo');

        return view('digital.campaign', ['c' => $c, 's' => DM::campaignStats($c), 'days' => $c->adStats()->orderByDesc('date')->get(),
            'links' => $c->links()->get()->map(fn ($l) => [$l, DM::linkStats($l)]), 'posts' => $c->posts()->where('status', '!=', 'cancelled')->orderByDesc('scheduled_at')->get(),
            'adChannels' => DmChannel::orderBy('name')->get(), 'promos' => PromoCode::orderBy('code')->get(), 'people' => $this->people()]);
    }

    public function campaignStore(Request $r)
    {
        try {
            $c = DM::saveCampaign($r->all(), null, $r->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('digital.campaign', $c)->with('success', "{$c->number} created as a draft – activate it when it goes live.");
    }

    public function campaignUpdate(Request $r, DmCampaign $dmcampaign)
    {
        return $this->attempt(fn () => DM::saveCampaign($r->all(), $dmcampaign, $r->user()), 'Campaign updated.');
    }

    public function campaignStatus(Request $r, DmCampaign $dmcampaign)
    {
        return $this->attempt(fn () => DM::setCampaignStatus($dmcampaign, (string) $r->status, $r->user()), 'Campaign status updated.');
    }

    public function adStat(Request $r, DmCampaign $dmcampaign)
    {
        return $this->attempt(fn () => DM::saveAdStat($dmcampaign, $r->all(), $r->user())['created'] ? 'Results added.' : 'Results for that day updated.', 'Saved.');
    }

    public function adStatDelete(Request $r, DmAdStat $dmadstat)
    {
        return $this->attempt(fn () => DM::deleteAdStat($dmadstat, $r->user()), 'Day removed.');
    }

    public function import(Request $r, DmCampaign $dmcampaign)
    {
        $file = $r->file('file');
        if (! $file || ! $file->isValid() || $file->getSize() > 2 * 1024 * 1024 || ! in_array(strtolower($file->getClientOriginalExtension()), ['csv', 'txt'], true)) {
            return back()->with('error', 'Choose the CSV export (up to 2 MB).');
        }

        return $this->attempt(function () use ($r, $dmcampaign, $file) {
            $x = DM::importCsv($dmcampaign, (string) file_get_contents($file->getRealPath()), $r->user());

            return "Imported {$x['imported']} new day(s), updated {$x['updated']}, skipped {$x['skipped']}.".($x['errors'] ? ' '.implode(' ', $x['errors']) : '');
        }, 'Imported.');
    }

    // ------------------------------------------------------------------ tracked links

    public function links(Request $r)
    {
        $q = trim((string) $r->query('q'));
        $links = DmLink::with('campaign', 'creator')->when($q !== '', fn ($x) => $x->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('code', 'like', "%$q%")->orWhere('utm_campaign', 'like', "%$q%")))
            ->orderByDesc('is_active')->orderByDesc('last_click_at')->orderByDesc('id')->get();
        $b = Reservation::whereIn('dm_link_id', $links->pluck('id')->all() ?: [0])->where('status', '!=', 'cancelled')->selectRaw('dm_link_id, count(*) n')->groupBy('dm_link_id')->pluck('n', 'dm_link_id');

        return view('digital.links', ['links' => $links, 'bookings' => $b, 'q' => $q, 'campaignList' => DmCampaign::where('status', '!=', 'ended')->orderBy('name')->get(),
            'clicks30' => DmLinkClick::where('clicked_at', '>=', now()->subDays(30))->count()]);
    }

    public function link(DmLink $dmlink)
    {
        return view('digital.link', ['l' => $dmlink->load('campaign', 'creator'), 's' => DM::linkStats($dmlink), 'qr' => $this->qrSvg($dmlink->shortUrl()),
            'recent' => $dmlink->clickLog()->orderByDesc('clicked_at')->limit(15)->get(), 'res' => $dmlink->reservations()->with('guest')->latest('id')->limit(15)->get(),
            'campaignList' => DmCampaign::where('status', '!=', 'ended')->orWhere('id', $dmlink->campaign_id)->orderBy('name')->get()]);
    }

    protected function qrSvg(string $text): string
    {
        $o = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG, 'outputBase64' => false,
            'svgAddXmlHeader' => false, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M, 'addQuietzone' => true]);

        return (new \chillerlan\QRCode\QRCode($o))->render($text);
    }

    public function qr(DmLink $dmlink)
    {
        return response('<?xml version="1.0" encoding="UTF-8"?>'."\n".$this->qrSvg($dmlink->shortUrl()), 200, ['Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="qr-'.$dmlink->code.'.svg"']);
    }

    public function linkStore(Request $r)
    {
        try {
            $l = DM::saveLink($r->all(), null, $r->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('digital.link', $l)->with('success', 'Tracked link ready: '.$l->shortUrl());
    }

    public function linkUpdate(Request $r, DmLink $dmlink)
    {
        return $this->attempt(fn () => DM::saveLink($r->all(), $dmlink, $r->user()), 'Link updated.');
    }

    public function linkToggle(Request $r, DmLink $dmlink)
    {
        return $this->attempt(fn () => DM::toggleLink($dmlink, $r->user()) ?? ($dmlink->fresh()->is_active ? 'Link re-activated.' : 'Link paused – it still redirects but no longer tracks.'), 'Saved.');
    }

    /** public short link */
    public function go(Request $r, string $dlcode)
    {
        return DM::click($dlcode, $r);
    }

    // ------------------------------------------------------------------ channels & listings

    public function channels()
    {
        $all = DmChannel::with('owner', 'auditor')->orderByDesc('is_active')->orderBy('name')->get();
        $prev = DmChannelStat::whereIn('channel_id', $all->pluck('id')->all() ?: [0])->where('month', bdate()->copy()->startOfMonth()->subMonthNoOverflow()->toDateString())->pluck('followers', 'channel_id');

        return view('digital.channels', ['groups' => $all->groupBy(fn ($c) => $c->kind()), 'prev' => $prev, 'people' => $this->people()]);
    }

    public function channel(DmChannel $dmchannel)
    {
        return view('digital.channel', ['c' => $dmchannel->load('owner', 'auditor'), 'stats' => $dmchannel->stats()->orderByDesc('month')->limit(12)->get(), 'people' => $this->people(),
            'posts' => DmPost::whereJsonContains('channels', $dmchannel->id)->where('status', 'published')->orderByDesc('published_at')->limit(10)->get(),
            'campaigns' => DmCampaign::where('channel_id', $dmchannel->id)->orderByDesc('start_date')->get()]);
    }

    public function channelStore(Request $r)
    {
        try {
            $c = DM::saveChannel($r->all(), null, $r->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('digital.channel', $c)->with('success', "{$c->name} added.");
    }

    public function channelUpdate(Request $r, DmChannel $dmchannel)
    {
        return $this->attempt(fn () => DM::saveChannel($r->all(), $dmchannel, $r->user()), 'Channel updated.');
    }

    public function channelStats(Request $r, DmChannel $dmchannel)
    {
        return $this->attempt(fn () => DM::recordChannelStats($dmchannel, $r->all(), $r->user()) ? 'Monthly figures saved.' : null, 'Saved.');
    }

    public function channelAudit(Request $r, DmChannel $dmchannel)
    {
        return $this->attempt(fn () => 'Audit saved – listing health '.DM::auditListing($dmchannel, (array) $r->input('h', []), $r->all(), $r->user()).'%.', 'Saved.');
    }

    // ------------------------------------------------------------------ influencers

    public function influencers(Request $r)
    {
        $status = array_key_exists((string) $r->query('status'), DmInfluencer::STATUSES) ? $r->query('status') : null;
        $rows = DmInfluencer::with('owner', 'link')->when($status, fn ($q) => $q->where('status', $status))
            ->orderByRaw("FIELD(status,'hosted','agreed','proposed','delivered','cancelled')")->orderBy('stay_from')->get()->map(fn ($i) => [$i, DM::influencerStats($i)]);

        return view('digital.influencers', ['rows' => $rows, 'status' => $status, 'linkList' => DmLink::orderBy('name')->get(), 'people' => $this->people()]);
    }

    public function influencer(DmInfluencer $dminfluencer)
    {
        return view('digital.influencer', ['i' => $dminfluencer->load('owner', 'link', 'reservation.guest'), 's' => DM::influencerStats($dminfluencer),
            'linkList' => DmLink::orderBy('name')->get(), 'people' => $this->people()]);
    }

    public function influencerStore(Request $r)
    {
        try {
            $i = DM::saveInfluencer($r->all(), null, $r->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return redirect()->route('digital.influencer', $i)->with('success', "{$i->number} {$i->name} added as a proposal.");
    }

    public function influencerUpdate(Request $r, DmInfluencer $dminfluencer)
    {
        return $this->attempt(fn () => DM::saveInfluencer($r->all(), $dminfluencer, $r->user()), 'Collaboration updated.');
    }

    public function influencerStatus(Request $r, DmInfluencer $dminfluencer)
    {
        return $this->attempt(fn () => DM::setInfluencerStatus($dminfluencer, (string) $r->status, $r->all(), $r->user()) ?? "{$dminfluencer->number} is now ".strtolower($dminfluencer->fresh()->statusLabel()).'.', 'Saved.');
    }

    public function influencerResults(Request $r, DmInfluencer $dminfluencer)
    {
        return $this->attempt(fn () => DM::saveInfluencerResults($dminfluencer, $r->all(), $r->user()), 'Results saved.');
    }

    // ------------------------------------------------------------------ settings

    public function settings()
    {
        return view('digital.settings', ['cfg' => DM::cfg()]);
    }

    public function settingsSave(Request $r)
    {
        return $this->attempt(fn () => DM::saveSettings($r->all()), 'Digital marketing settings saved.');
    }
}
