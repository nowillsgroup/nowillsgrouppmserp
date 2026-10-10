<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\SurveyInvitation;
use App\Services\FeedbackService;
use App\Support\Tenant;
use Illuminate\Http\Request;

/** v3.1 public post-stay survey reached from the invitation e-mail / SMS. */
class SurveyPublicController extends Controller
{
    protected function load(string $token): SurveyInvitation
    {
        $inv = FeedbackService::findByToken($token);
        abort_unless($inv, 404);
        $hotel = Hotel::whereKey($inv->hotel_id)->where('is_active', true)->firstOrFail();
        Tenant::set($hotel);

        return SurveyInvitation::with('survey', 'guest', 'reservation')->findOrFail($inv->id);
    }

    protected function headers($response)
    {
        return $response->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store, private');
    }

    public function show(Request $request, string $token)
    {
        $inv = $this->load($token);
        $hotel = hotel();
        if ($inv->status === 'completed') {
            $fb = $inv->response;

            return $this->headers(response()->view('public.survey-thanks', ['hotel' => $hotel, 'inv' => $inv, 'token' => $token,
                'links' => $fb ? FeedbackService::reviewLinks($fb) : [], 'fb' => $fb]));
        }
        if (! $inv->isOpen() || ! $inv->survey?->is_active) {
            return $this->headers(response()->view('public.survey-closed', ['hotel' => $hotel, 'inv' => $inv], 410));
        }
        if ($inv->status === 'sent') {
            $inv->forceFill(['status' => 'opened', 'opened_at' => now()])->save();
        }
        $pre = $request->query('nps');

        return $this->headers(response()->view('public.survey', ['hotel' => $hotel, 'inv' => $inv, 'survey' => $inv->survey, 'questions' => $inv->survey->activeQuestions()->get(),
            'token' => $token, 'preNps' => is_numeric($pre) && (int) $pre >= 0 && (int) $pre <= 10 ? (int) $pre : null, 'preview' => false]));
    }

    public function submit(Request $request, string $token)
    {
        $inv = $this->load($token);
        abort_unless($inv->survey?->is_active, 410);
        FeedbackService::submit($inv, $request->except('_token'));

        return redirect()->route('survey.show', $token);
    }

    /** Promoter clicked "share on Google / TripAdvisor": record it, then leave for the configured review page. */
    public function review(string $token, string $site)
    {
        $inv = $this->load($token);
        $fb = $inv->response;
        abort_unless($inv->status === 'completed' && $fb, 404);
        $links = FeedbackService::reviewLinks($fb);
        abort_unless(isset($links[$site]), 404);
        $fb->forceFill(['review_click_site' => $site, 'review_click_at' => $fb->review_click_at ?? now()])->save();

        return redirect()->away($links[$site]);
    }
}
