<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\HotelGroup;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'hotel_code' => 'required|string|max:30',
            'username' => 'required|string|max:60',
            'password' => 'required|string|max:200',
        ]);
        $group = $request->boolean('group_login');
        $fail = fn () => throw ValidationException::withMessages(['username' => 'Invalid Hotel ID / username / password.']);

        if ($group) {
            $grp = HotelGroup::where('code', $data['hotel_code'])->where('is_active', true)->first() ?? $fail();
            $user = User::where('hotel_group_id', $grp->id)->where('username', $data['username'])->where('is_group_admin', true)->first();
        } else {
            $hotel = Hotel::where('code', $data['hotel_code'])->where('is_active', true)->first() ?? $fail();
            $user = User::where('hotel_id', $hotel->id)->where('username', $data['username'])->first();
        }

        if (! $user) {
            Hash::check($data['password'], '$2y$12$abcdefghijklmnopqrstuuJ0x5Qe2L7d1b8Zb1Wq9aYw7S0cQ5K2e'); // timing equaliser
            $fail();
        }
        if ($user->locked_until && $user->locked_until->isFuture()) {
            throw ValidationException::withMessages(['username' => 'Account temporarily locked due to failed attempts. Try again after '.$user->locked_until->diffForHumans(null, true).'.']);
        }
        if (! Hash::check($data['password'], $user->password)) {
            $user->failed_logins++;
            if ($user->failed_logins >= 5) {
                $user->locked_until = now()->addMinutes(15);
                $user->failed_logins = 0;
            }
            $user->save();
            $fail();
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['username' => 'This account is disabled. Contact your administrator.']);
        }

        $hotelId = $group ? ($user->accessibleHotels()->first()?->id ?? $user->hotel_id) : $hotel->id;

        if ($user->hasTwoFactor()) {
            // Password OK – now ask for the authenticator code. Nothing is signed in yet.
            $request->session()->regenerate();
            $request->session()->put('login.2fa', ['id' => $user->id, 'hotel_id' => $hotelId, 'group' => $group,
                'remember' => $request->boolean('remember'), 'expires' => now()->addMinutes(5)->timestamp]);

            return redirect()->route('login.2fa');
        }

        return self::completeLogin($request, $user, $hotelId, $group, $request->boolean('remember'));
    }

    /** Final step of every successful sign-in (with or without 2FA). */
    public static function completeLogin(Request $request, User $user, int $hotelId, bool $group, bool $remember, string $via = '')
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put('hotel_id', $hotelId);
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip(), 'failed_logins' => 0, 'locked_until' => null])->save();

        Tenant::set(Hotel::find($hotelId));
        ActivityLogger::log('auth', 'login', "{$user->username} signed in".($group ? ' (group login)' : '').$via);

        return redirect()->intended(self::home($user));
    }

    /** Landing page: the dashboard, or the first screen the user's role can open (attendant → My rooms, chef → KDS …). */
    public static function home(User $user): string
    {
        $candidates = [['dashboard.view', 'dashboard'], ['housekeeping.attendant', 'hk.my'], ['pos.kitchen', 'kitchen.index']];
        foreach (config('pms.menu') as $item) {
            foreach ($item['children'] ?? [$item] as $c) {
                if (isset($c['route']) && empty($c['employee'])) {
                    $candidates[] = [$c['perm'], $c['route'], $c['params'] ?? []];
                }
            }
        }
        $candidates[] = ['hr.self', 'me.hr'];
        foreach ($candidates as $c) {
            if (collect(explode(',', $c[0]))->contains(fn ($k) => $user->hasPermission($k))) {
                return route($c[1], $c[2] ?? []);
            }
        }

        return route('profile');
    }

    public function switchHotel(Request $request)
    {
        $id = (int) $request->input('hotel_id');
        abort_unless($request->user()->accessibleHotels()->contains('id', $id), 403);
        $request->session()->put('hotel_id', $id);
        Tenant::set(Hotel::find($id));
        ActivityLogger::log('auth', 'switch_hotel', 'Switched to '.Tenant::hotel()->name);

        return redirect()->to(self::home($request->user()))->with('success', 'Switched to '.Tenant::hotel()->name);
    }

    public function logout(Request $request)
    {
        if ($request->session()->has('hotel_id')) {
            Tenant::set(Hotel::find($request->session()->get('hotel_id')));
            ActivityLogger::log('auth', 'logout', $request->user()->username.' signed out');
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
