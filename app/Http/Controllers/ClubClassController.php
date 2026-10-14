<?php

namespace App\Http\Controllers;

use App\Models\ClubBooking;
use App\Models\ClubClass;
use App\Models\ClubMember;
use App\Models\ClubPlan;
use App\Models\Employee;
use App\Services\ActivityLogger;
use App\Services\ClubClassService;
use App\Services\ClubService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** v5.1 Class timetable, bookings, waitlist and attendance. */
class ClubClassController extends Controller
{
    public function index(Request $request)
    {
        $week = ClubClassService::week($request->query('week', bdate()->toDateString()));

        return view('club.classes', ['week' => $week, 'classes' => ClubClass::with('employee')->orderBy('weekday')->orderBy('start_time')->get(),
            'employees' => Employee::where('hotel_id', \App\Support\Tenant::id())->where('is_active', true)->orderBy('name')->get(['id', 'name', 'emp_code', 'designation'])]);
    }

    public function save(Request $request, ?ClubClass $clubclass = null)
    {
        $d = $request->validate(['name' => 'required|string|max:80', 'facility' => ['required', Rule::in(array_keys(ClubPlan::FACILITIES))], 'employee_id' => 'nullable|integer',
            'instructor' => 'nullable|string|max:80', 'weekday' => 'required|integer|between:1,7', 'start_time' => 'required|date_format:H:i', 'duration_min' => 'required|integer|min:15|max:240',
            'capacity' => 'required|integer|min:1|max:200', 'residents' => 'nullable|boolean', 'is_active' => 'nullable|boolean']);
        $d['residents'] = $request->boolean('residents');
        $d['is_active'] = $request->boolean('is_active');
        if ($clubclass && ((int) $d['weekday'] !== (int) $clubclass->weekday || $d['start_time'] !== substr($clubclass->start_time, 0, 5))
            && ClubBooking::where('club_class_id', $clubclass->id)->whereDate('date', '>=', bdate()->toDateString())->whereIn('status', ['booked', 'waitlist'])->exists()) {
            return back()->with('error', 'This class has upcoming bookings – cancel them before moving the day or time, or add a new class.');
        }
        $c = $clubclass ? tap($clubclass)->update($d) : ClubClass::create($d);
        ActivityLogger::log('club', 'class_saved', "Class {$c->name} (".ClubClass::DAYS[$c->weekday].' '.$c->timeLabel().') saved', $c);

        return back()->with('success', "{$c->name} saved.");
    }

    public function session(ClubClass $clubclass, string $date)
    {
        $d = Carbon::parse($date);
        abort_unless((int) $d->isoWeekday() === (int) $clubclass->weekday, 404);
        $bookings = ClubBooking::with('member.membership', 'stay.room')->where('club_class_id', $clubclass->id)->whereDate('date', $d->toDateString())
            ->orderByRaw("field(status,'booked','attended','no_show','waitlist','cancelled')")->orderBy('position')->orderBy('id')->get();

        return view('club.session', ['c' => $clubclass->load('employee'), 'date' => $d, 'bookings' => $bookings, 'start' => ClubClassService::sessionStart($clubclass, $d->toDateString()),
            'taken' => $bookings->whereIn('status', ['booked', 'attended', 'no_show'])->count()]);
    }

    public function book(Request $request, ClubClass $clubclass, string $date)
    {
        $d = $request->validate(['card' => 'nullable|string|max:40', 'room_no' => 'nullable|string|max:20']);
        try {
            $p = null;
            if (! empty($d['card'])) {
                $found = ClubService::lookup($d['card']);
                if ($found->count() !== 1) {
                    throw new RuntimeException($found->isEmpty() ? 'No member found for "'.$d['card'].'".' : 'Several members match – scan the card or use the card number.');
                }
                $p = $found->first();
            } elseif (empty($d['room_no'])) {
                throw new RuntimeException('Scan a member card or enter a room number.');
            }
            $b = ClubClassService::book($clubclass, $date, $p, $d['room_no'] ?? null, $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with($b->status === 'waitlist' ? 'warning' : 'success', $b->status === 'waitlist' ? "The class is full – {$b->name} is number {$b->position} on the waitlist." : "{$b->name} booked on {$clubclass->name}.");
    }

    public function cancel(Request $request, ClubBooking $clubbooking)
    {
        try {
            $r = ClubClassService::cancel($clubbooking->load('klass'), $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with($r['late'] ? 'warning' : 'success', ($r['late'] ? 'Late cancellation – recorded as a no-show.' : 'Booking cancelled.').($r['promoted'] ? " {$r['promoted']->name} moved up from the waitlist." : ''));
    }

    public function attendance(Request $request, ClubClass $clubclass, string $date)
    {
        $d = $request->validate(['mark' => 'array', 'mark.*' => 'in:attended,no_show']);
        try {
            $n = ClubClassService::attendance($clubclass, $date, $d['mark'] ?? []);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Register saved ({$n} change(s)).");
    }
}
