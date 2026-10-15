<?php

namespace App\Http\Controllers;

use App\Models\FormCRecord;
use App\Models\Guest;
use App\Models\ReservationRoom;
use App\Services\ActivityLogger;
use App\Services\ForexService;
use App\Services\FormCService;
use App\Services\PrivacyService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** v3.4 Form C (FRRO) desk – foreign nationals reported within 24 h of arrival, departures after check-out. */
class FormCController extends Controller
{
    public const TABS = ['due' => 'To submit', 'departures' => 'Departures to report', 'inhouse' => 'In house', 'submitted' => 'Submitted', 'all' => 'All'];

    public function index(Request $request)
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'due';
        $q = FormCRecord::with('stay.room', 'guest', 'reservation')
            ->when($request->filled('q'), fn ($w) => $w->where(fn ($x) => $x->where('form_no', 'like', '%'.$request->q.'%')->orWhere('surname', 'like', '%'.$request->q.'%')
                ->orWhere('given_name', 'like', '%'.$request->q.'%')->orWhere('passport_no', 'like', '%'.$request->q.'%')->orWhere('frro_ref', 'like', '%'.$request->q.'%')))
            ->when($request->filled('nationality'), fn ($w) => $w->where('nationality', $request->nationality));
        match ($tab) {
            'due' => $q->where('status', 'pending')->orderBy('deadline_at'),
            'departures' => $q->where('status', 'submitted')->whereNotNull('departed_at')->whereNull('departure_submitted_at')->orderBy('departed_at'),
            'inhouse' => $q->whereIn('status', ['pending', 'submitted'])->whereNull('departed_at')->orderBy('checked_in_at'),
            'submitted' => $q->where('status', 'submitted')->orderByDesc('submitted_at'),
            default => $q->orderByDesc('checked_in_at'),
        };

        return view('formc.index', ['tab' => $tab, 'records' => $q->paginate(30)->withQueryString(), 'alerts' => FormCService::alerts(), 'cfg' => FormCService::cfg(),
            'check' => FormCService::nationalityCheck(), 'enabled' => FormCService::enabled(),
            'nationalities' => FormCRecord::whereNotNull('nationality')->distinct()->orderBy('nationality')->pluck('nationality')]);
    }

    public function show(FormCRecord $formc)
    {
        $formc->load('stay.room', 'stay.roomType', 'reservation', 'guest', 'submitter', 'departureUser');
        $scans = FormCService::scans($formc);

        return view('formc.show', ['f' => $formc, 'missing' => FormCService::missing($formc), 'warnings' => FormCService::warnings($formc), 'scans' => $scans,
            'cfg' => FormCService::cfg(), 'visaExempt' => FormCService::visaExempt($formc->nationality),
            'others' => FormCRecord::where('reservation_room_id', $formc->reservation_room_id)->where('id', '!=', $formc->id)->get()]);
    }

    public function update(Request $request, FormCRecord $formc)
    {
        $data = $request->validate([
            'surname' => 'nullable|string|max:80', 'given_name' => 'nullable|string|max:80', 'gender' => 'nullable|in:M,F,X', 'dob' => 'nullable|date',
            'nationality' => 'nullable|string|max:60', 'passport_no' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9 ]{5,30}$/'], 'passport_place' => 'nullable|string|max:80',
            'passport_issued_on' => 'nullable|date', 'passport_expires_on' => 'nullable|date', 'visa_no' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9\- ]{4,30}$/'],
            'visa_type' => 'nullable|in:'.implode(',', array_keys(FormCRecord::VISA_TYPES)), 'visa_place' => 'nullable|string|max:80', 'visa_issued_on' => 'nullable|date',
            'visa_expires_on' => 'nullable|date', 'arrived_india_on' => 'nullable|date', 'arrived_from' => 'nullable|string|max:120', 'port_of_arrival' => 'nullable|string|max:80',
            'next_destination' => 'nullable|string|max:120', 'purpose' => 'nullable|in:'.implode(',', array_keys(FormCRecord::PURPOSES)), 'address_abroad' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:30', 'notes' => 'nullable|string|max:1000', 'sync_profile' => 'nullable|boolean',
        ], ['passport_no.regex' => 'The passport number may only contain letters and digits.', 'visa_no.regex' => 'The visa number may only contain letters, digits and dashes.']);
        $sync = (bool) ($data['sync_profile'] ?? false);
        unset($data['sync_profile']);
        try {
            $f = FormCService::save($formc, $data, $sync);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['formc' => $e->getMessage()]);
        }
        $left = count(FormCService::missing($f));

        return redirect()->route('fg.formc.show', $f)->with('success', $left ? "Saved – {$left} item(s) still needed before filing." : 'Saved – ready to file on the FRRO portal.');
    }

    public function submit(Request $request, FormCRecord $formc)
    {
        $data = $request->validate(['frro_ref' => 'required|string|max:40', 'submitted_at' => 'nullable|date', 'late_reason' => 'nullable|string|max:255']);
        try {
            FormCService::submit($formc, $data['frro_ref'], $data['submitted_at'] ?? null, $data['late_reason'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['submit' => $e->getMessage()]);
        }

        return redirect()->route('fg.formc.show', $formc)->with('success', "Form C {$formc->form_no} recorded as submitted to the FRRO.");
    }

    public function departure(Request $request, FormCRecord $formc)
    {
        $data = $request->validate(['departure_ref' => 'nullable|string|max:40', 'departure_submitted_at' => 'nullable|date']);
        try {
            FormCService::reportDeparture($formc, $data['departure_ref'] ?? null, $data['departure_submitted_at'] ?? null);
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['departure' => $e->getMessage()]);
        }

        return back()->with('success', "Departure of {$formc->name()} recorded as reported.");
    }

    public function exempt(Request $request, FormCRecord $formc)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        try {
            FormCService::exempt($formc, $data['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['exempt' => $e->getMessage()]);
        }

        return back()->with('success', "Form C {$formc->form_no} marked exempt.");
    }

    public function reopen(Request $request, FormCRecord $formc)
    {
        $data = $request->validate(['reason' => 'required|string|max:255']);
        try {
            FormCService::reopen($formc, $data['reason']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['reopen' => $e->getMessage()]);
        }

        return back()->with('success', "Form C {$formc->form_no} re-opened for correction.");
    }

    /** Open a Form C for a stay – the primary guest (if missing) or a companion sharing the room. */
    public function create(Request $request, ReservationRoom $stay)
    {
        $data = $request->validate(['companion' => 'nullable|boolean', 'given_name' => 'nullable|string|max:80', 'surname' => 'nullable|string|max:80', 'nationality' => 'nullable|string|max:60']);
        $companion = (bool) ($data['companion'] ?? false);
        $stay->load('reservation.guest', 'guest');
        try {
            if ($companion) {
                if (FormCService::isForeignNationality($data['nationality'] ?? null) !== true) {
                    throw new RuntimeException('Enter the companion’s (foreign) nationality.');
                }
                if (blank($data['given_name'] ?? null)) {
                    throw new RuntimeException('Enter the companion’s name.');
                }
                $f = FormCService::createForStay($stay, null, true, collect($data)->only(['given_name', 'surname', 'nationality'])->all());
            } else {
                $g = FormCService::stayGuest($stay);
                if (! FormCService::isForeign($g)) {
                    throw new RuntimeException('The guest is not recorded as a foreign national – set the nationality first.');
                }
                if (FormCRecord::where('reservation_room_id', $stay->id)->where('is_companion', false)->exists()) {
                    throw new RuntimeException('This stay already has a Form C for the guest.');
                }
                $f = FormCService::createForStay($stay, $g);
            }
        } catch (RuntimeException $e) {
            return back()->withErrors(['formc' => $e->getMessage()]);
        }

        return redirect()->route('fg.formc.show', $f)->with('success', "Form C {$f->form_no} opened – due by ".$f->deadline_at->format('d M H:i').'.');
    }

    public function nationality(Request $request, Guest $guest)
    {
        $data = $request->validate(['nationality' => 'required|string|max:60']);
        abort_if($guest->isPrivacyLocked(), 403);
        $n = FormCService::setNationality($guest, $data['nationality']);

        return back()->with('success', 'Nationality saved'.($n ? " – {$n} Form C opened." : '.'));
    }

    public function print(FormCRecord $formc)
    {
        $formc->load('stay.room', 'reservation', 'guest', 'submitter');
        PrivacyService::logAccess($formc->guest, 'print', 'Form C '.$formc->form_no);
        ActivityLogger::log('formc', 'print', "Form C {$formc->form_no} printed", $formc);

        return view('formc.print', ['f' => $formc, 'hotel' => hotel()]);
    }

    public function export(Request $request)
    {
        $from = Carbon::parse($request->query('from', bdate()->copy()->subDays(30)->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', bdate()->toDateString()))->endOfDay();
        $rows = FormCService::exportRows(FormCRecord::with('stay.room')->whereBetween('checked_in_at', [$from, $to])->orderBy('checked_in_at')->get());
        ActivityLogger::log('formc', 'export', 'Form C register exported '.$from->format('d M').' – '.$to->format('d M Y').' ('.(count($rows) - 1).' records)');
        PrivacyService::logAccess(null, 'export', 'Form C register '.$from->toDateString().' – '.$to->toDateString());

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            foreach ($rows as $r) {
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v, $r));
            }
            fclose($out);
        }, 'form-c-register-'.$from->toDateString().'-'.$to->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ───────── shared settings page (Form C + forex) ─────────

    public function settings()
    {
        abort_unless(can_do('formc.manage') || can_do('forex.manage'), 403);

        return view('formc.settings', ['c' => FormCService::cfg(), 'x' => ForexService::cfg()]);
    }

    public function saveSettings(Request $request)
    {
        abort_unless(can_do('formc.manage') || can_do('forex.manage'), 403);
        if ($request->input('section') === 'forex') {
            abort_unless(can_do('forex.manage'), 403);
            $d = $request->validate([
                'cash_cap_usd' => 'required|integer|min:0|max:10000', 'resident_cap_usd' => 'required|integer|min:0|max:10000', 'aggregate_days' => 'required|integer|min:1|max:90',
                'id_copy_inr' => 'required|integer|min:0|max:100000', 'max_margin' => 'required|numeric|min:0|max:15', 'fee_pct' => 'required|numeric|min:0|max:5',
                'surrender_days' => 'required|integer|min:0|max:30', 'licence' => 'nullable|string|max:80', 'franchiser' => 'nullable|string|max:120',
                'channel_basis' => 'required|in:reference,buy', 'channel_stale_days' => 'required|integer|min:0|max:90',
                'ap_basis' => 'required|in:reference,buy', 'ap_stale_days' => 'required|integer|min:0|max:90', 'ap_rate_date' => 'required|in:document,business',
            ], ['cash_cap_usd.max' => 'Keep the cash limit at USD 10,000 or less – larger purchases belong at a bank.', 'resident_cap_usd.max' => 'Keep the cash limit at USD 10,000 or less.']);
            foreach (['enabled', 'allow_residents', 'carry_rates', 'channel_convert', 'ap_convert', 'ap_pay', 'ap_pay_deal'] as $b) {
                $d[$b] = $request->boolean($b);
            }
            ForexService::saveSettings($d);
        } else {
            abort_unless(can_do('formc.manage'), 403);
            $d = $request->validate([
                'deadline_hours' => 'required|integer|min:1|max:24', 'alert_hours' => 'required|integer|min:0|max:23', 'departure_hours' => 'required|integer|min:1|max:168',
                'visa_exempt' => ['nullable', 'string', 'max:255', 'regex:/^[\pL ,.\'()-]*$/u'], 'alert_email' => 'nullable|email|max:190',
            ], ['deadline_hours.max' => 'The law requires the report within 24 hours of arrival – the deadline can only be shorter.']);
            if ((int) $d['alert_hours'] >= (int) $d['deadline_hours']) {
                throw ValidationException::withMessages(['alert_hours' => 'The warning must come before the deadline.']);
            }
            $d['visa_exempt'] = collect(explode(',', (string) ($d['visa_exempt'] ?? '')))->map(fn ($x) => trim($x))->filter()->unique()->implode(',');
            foreach (['enabled', 'require_scans', 'block_checkout'] as $b) {
                $d[$b] = $request->boolean($b);
            }
            FormCService::saveSettings($d);
        }

        return back()->with('success', 'Settings saved.');
    }
}
