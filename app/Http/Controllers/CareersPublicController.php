<?php

namespace App\Http\Controllers;

use App\Models\Hotel;
use App\Models\TalentVacancy;
use App\Services\TalentService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use RuntimeException;

/** v6.9 Public careers page (per hotel code): open, advertised vacancies and the application form with CV upload. */
class CareersPublicController extends Controller
{
    protected function hotel(string $code): Hotel
    {
        $hotel = Hotel::where('code', $code)->where('is_active', true)->firstOrFail();
        Tenant::set($hotel);
        abort_unless(TalentService::cfg()['careers'], 404);

        return $hotel;
    }

    protected function job(string $id): TalentVacancy
    {
        $v = TalentService::publicVacancies()->firstWhere('id', (int) $id);
        abort_unless($v, 404, 'This vacancy is closed.');

        return $v;
    }

    public function index(string $code)
    {
        $hotel = $this->hotel($code);

        return response()->view('public.careers', ['hotel' => $hotel, 'jobs' => TalentService::publicVacancies(), 'intro' => TalentService::cfg()['careers_intro']])
            ->header('Cache-Control', 'no-store');
    }

    public function show(string $code, string $job)
    {
        $hotel = $this->hotel($code);

        return response()->view('public.careers-job', ['hotel' => $hotel, 'v' => $this->job($job)])->header('Cache-Control', 'no-store');
    }

    public function apply(Request $r, string $code, string $job)
    {
        $hotel = $this->hotel($code);
        $v = $this->job($job);
        if (filled($r->input('website'))) {   // honeypot
            return back()->with('success', 'Thank you – your application has been received.');
        }
        $r->validate(['name' => 'required|string|min:2|max:120', 'phone' => 'required|string|max:30', 'email' => 'nullable|email|max:150',
            'cv' => 'nullable|file|max:5120|mimes:pdf,doc,docx|mimetypes:application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/zip,application/octet-stream',
            'cover_note' => 'nullable|string|max:3000', 'consent' => 'accepted'], ['consent.accepted' => 'Please agree to the hotel keeping your details for this application.']);
        try {
            $a = TalentService::addApplicant($v, $r->only(['name', 'phone', 'email', 'experience_years', 'expected_salary', 'current_employer', 'cover_note', 'consent']), $r->file('cv'), null, true);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return redirect()->route('careers.index', $hotel->code)->with('success', "Thank you, {$a->name} – your application for {$v->title} has been received (reference {$a->number}). We will contact shortlisted applicants.");
    }
}
