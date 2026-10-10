<?php

namespace App\Http\Controllers;

use App\Models\GuestFeedback;
use App\Models\Hotel;
use App\Support\Tenant;
use Illuminate\Http\Request;

class FeedbackController extends Controller
{
    public function form(string $code)
    {
        $hotel = $this->publicHotel($code);

        return view('public.feedback', ['hotel' => $hotel]);
    }

    public function submit(Request $request, string $code)
    {
        $hotel = $this->publicHotel($code);
        $data = $request->validate([
            'guest_name' => 'nullable|string|max:120', 'contact' => 'nullable|string|max:120',
            'rating_overall' => 'required|integer|between:1,5', 'rating_room' => 'nullable|integer|between:1,5',
            'rating_service' => 'nullable|integer|between:1,5', 'rating_food' => 'nullable|integer|between:1,5',
            'rating_cleanliness' => 'nullable|integer|between:1,5', 'comments' => 'nullable|string|max:2000',
        ]);
        Tenant::set($hotel);
        \App\Services\FeedbackService::analyse(GuestFeedback::create($data + ['source' => 'web']));

        return back()->with('success', 'Thank you! Your feedback helps us serve you better.');
    }

    protected function publicHotel(string $code): Hotel
    {
        $hotel = Hotel::where('code', $code)->where('is_active', true)->firstOrFail();
        \App\Support\Tenant::set($hotel);
        abort_if(\App\Models\Setting::where('key', 'feedback_enabled')->value('value') === '0', 404);

        return $hotel;
    }
}
