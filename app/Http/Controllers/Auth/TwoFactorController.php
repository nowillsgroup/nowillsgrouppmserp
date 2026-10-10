<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Tenant;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * TOTP two-factor authentication: login challenge, self-service enrolment on the profile,
 * recovery codes and administrator reset.
 */
class TwoFactorController extends Controller
{
    // ---------- Login challenge (guest) ----------

    protected function pending(Request $request): ?array
    {
        $p = $request->session()->get('login.2fa');
        if (! $p || $p['expires'] < now()->timestamp) {
            $request->session()->forget('login.2fa');

            return null;
        }

        return $p;
    }

    public function challenge(Request $request)
    {
        if (! $this->pending($request)) {
            return redirect()->route('login')->withErrors(['username' => 'Your sign-in session expired. Please enter your password again.']);
        }

        return view('auth.two-factor');
    }

    public function verify(Request $request)
    {
        $p = $this->pending($request);
        if (! $p) {
            return redirect()->route('login')->withErrors(['username' => 'Your sign-in session expired. Please enter your password again.']);
        }
        $data = $request->validate(['code' => 'nullable|string|max:12', 'recovery_code' => 'nullable|string|max:20']);
        $user = User::find($p['id']);
        if (! $user || ! $user->is_active || ! $user->hasTwoFactor()) {
            $request->session()->forget('login.2fa');

            return redirect()->route('login');
        }
        if ($user->locked_until && $user->locked_until->isFuture()) {
            $request->session()->forget('login.2fa');

            return redirect()->route('login')->withErrors(['username' => 'Account temporarily locked due to failed attempts.']);
        }

        $via = null;
        if (! empty($data['code'])) {
            $step = Totp::verify($user->two_factor_secret, $data['code']);
            if ($step !== null && $step > (int) $user->two_factor_last_step) {
                $user->forceFill(['two_factor_last_step' => $step])->save();
                $via = '';
            }
        } elseif (! empty($data['recovery_code'])) {
            $given = strtolower(trim($data['recovery_code']));
            $codes = $user->two_factor_recovery_codes ?? [];
            foreach ($codes as $i => $c) {
                if (hash_equals($c, $given)) {
                    unset($codes[$i]);
                    $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                    $via = ' using a recovery code ('.count($codes).' left)';
                    break;
                }
            }
        }

        if ($via === null) {
            $user->failed_logins++;
            if ($user->failed_logins >= 5) {
                $user->forceFill(['locked_until' => now()->addMinutes(15), 'failed_logins' => 0])->save();
                $request->session()->forget('login.2fa');
                Tenant::set(Hotel::find($p['hotel_id']));
                ActivityLogger::log('auth', '2fa_lockout', "{$user->username} locked after repeated invalid 2FA codes");

                return redirect()->route('login')->withErrors(['username' => 'Too many invalid codes. The account is locked for 15 minutes.']);
            }
            $user->save();
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check the time on your phone and try again.']);
        }

        $request->session()->forget('login.2fa');

        return LoginController::completeLogin($request, $user, $p['hotel_id'], $p['group'], $p['remember'], ' with 2FA'.$via);
    }

    // ---------- Self-service (authenticated) ----------

    public function show(Request $request)
    {
        $user = $request->user();
        $qr = null;
        $secret = null;
        if (! $user->hasTwoFactor()) {
            $secret = $request->session()->get('2fa.setup') ?? tap(Totp::generateSecret(), fn ($s) => $request->session()->put('2fa.setup', $s));
            $label = $user->username.'@'.(Tenant::hotel()?->code ?? 'PMS');
            $opts = new \chillerlan\QRCode\QROptions(['outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG, 'outputBase64' => true, 'svgAddXmlHeader' => false, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M, 'addQuietzone' => true]);
            $qr = (new \chillerlan\QRCode\QRCode($opts))->render(Totp::uri($secret, $label, 'Nowills PMS'));
        }

        return view('users.two-factor', ['user' => $user, 'qr' => $qr, 'secret' => $secret, 'codes' => session('recovery_codes'),
            'required' => setting('require_2fa_admins', '0') === '1' && $user->isAdministrator()]);
    }

    public function enable(Request $request)
    {
        $data = $request->validate(['code' => 'required|string|max:12']);
        $user = $request->user();
        $secret = $request->session()->get('2fa.setup');
        abort_if($user->hasTwoFactor() || ! $secret, 422, 'Start the set-up again.');
        $step = Totp::verify($secret, $data['code']);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'That code does not match. Scan the QR code again and enter the 6 digits shown in the app.']);
        }
        $codes = Totp::recoveryCodes();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => $codes, 'two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step])->save();
        $request->session()->forget('2fa.setup');
        $request->session()->regenerate();
        ActivityLogger::log('auth', '2fa_enable', "{$user->username} enabled two-factor authentication", $user);

        return redirect()->route('profile.2fa')->with('success', 'Two-factor authentication is on.')->with('recovery_codes', $codes);
    }

    public function recovery(Request $request)
    {
        $request->validate(['password' => 'required|current_password']);
        $user = $request->user();
        abort_unless($user->hasTwoFactor(), 422);
        $codes = Totp::recoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();
        ActivityLogger::log('auth', '2fa_recovery', "{$user->username} regenerated 2FA recovery codes", $user);

        return back()->with('success', 'New recovery codes generated. The old ones no longer work.')->with('recovery_codes', $codes);
    }

    public function disable(Request $request)
    {
        $request->validate(['password' => 'required|current_password']);
        $user = $request->user();
        if (setting('require_2fa_admins', '0') === '1' && $user->isAdministrator()) {
            return back()->with('error', 'Two-factor authentication is mandatory for administrators at this property.');
        }
        self::clear($user);
        ActivityLogger::log('auth', '2fa_disable', "{$user->username} disabled two-factor authentication", $user);

        return back()->with('success', 'Two-factor authentication turned off.');
    }

    /** Administrator: reset a user who lost their phone and recovery codes. */
    public function reset(Request $request, int $id)
    {
        $user = User::where('hotel_id', Tenant::id())->findOrFail($id);
        abort_if($user->is_super_admin && ! $request->user()->is_super_admin, 403);
        self::clear($user);
        ActivityLogger::log('users', '2fa_reset', "Reset two-factor authentication for {$user->username}", $user);

        return back()->with('success', "Two-factor authentication reset for {$user->username}. They can sign in with their password and enrol again.");
    }

    protected static function clear(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null])->save();
    }
}
