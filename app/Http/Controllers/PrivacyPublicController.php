<?php

namespace App\Http\Controllers;

use App\Mail\PrivacyVerify;
use App\Models\Hotel;
use App\Models\PrivacyRequest;
use App\Services\MailService;
use App\Services\PrivacyService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Public privacy notice + request form (per hotel). Requests stay "unverified" until the e-mailed link is clicked. */
class PrivacyPublicController extends Controller
{
    protected function hotel(string $code): Hotel
    {
        $hotel = Hotel::where('code', $code)->where('is_active', true)->firstOrFail();
        Tenant::set($hotel);

        return $hotel;
    }

    public function show(string $code)
    {
        $hotel = $this->hotel($code);

        return response()->view('public.privacy', ['hotel' => $hotel, 'settings' => PrivacyService::settings()])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function submit(Request $request, string $code)
    {
        $hotel = $this->hotel($code);
        if (filled($request->input('website'))) {   // honeypot – bots fill every field
            return back()->with('success', 'Thank you. Please check your e-mail to confirm the request.');
        }
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(PrivacyRequest::TYPES))], 'requester_name' => 'required|string|min:2|max:120',
            'requester_email' => 'required|email|max:150', 'requester_phone' => 'nullable|string|max:30', 'booking_ref' => 'nullable|string|max:40',
            'relationship' => ['required', Rule::in(['self', 'nominee', 'authorised'])], 'details' => 'nullable|string|max:3000', 'confirm' => 'accepted',
        ], ['confirm.accepted' => 'Please confirm the information is accurate.']);
        unset($data['confirm']);
        // at most 3 open web requests per e-mail address – stops the form being used to flood the hotel
        if (PrivacyRequest::whereIn('status', ['unverified', 'open'])->where('requester_email', $data['requester_email'])->count() >= 3) {
            return back()->withInput()->withErrors(['requester_email' => 'There are already open requests for this e-mail address – we will be in touch.']);
        }
        [$req, $token] = PrivacyService::publicSubmit($data);
        MailService::send($req->requester_email, new PrivacyVerify($req, $hotel, route('privacy.public.verify', [$hotel->code, $token])), 'privacy_verify', $req);

        return back()->with('success', "Thank you. We have sent a confirmation link to {$req->requester_email} – your request ({$req->request_no}) starts once you click it (the link is valid for 48 hours).");
    }

    public function verify(string $code, string $token)
    {
        $hotel = $this->hotel($code);
        $req = PrivacyService::verifyEmail($token);
        abort_unless($req, 404, 'This confirmation link is invalid or has expired – please submit the request again.');

        return response()->view('public.privacy-confirmed', ['hotel' => $hotel, 'req' => $req])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
