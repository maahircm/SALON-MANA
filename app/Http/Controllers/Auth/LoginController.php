<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\LoginRecorder;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __construct(private LoginRecorder $recorder, private AuditLogger $audit, private TwoFactorService $twoFactor) {}

    public function show()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $email = strtolower($credentials['email']);
        $throttleKey = $email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, config('salon.login_max_attempts'))) {
            $this->recorder->record('locked', null, $email, $request);
            throw ValidationException::withMessages(['email' => 'Too many login attempts. Try again in '.RateLimiter::availableIn($throttleKey).' seconds.']);
        }

        $user = User::where('email', $email)->first();
        // Always run a hash check so response time does not reveal whether the account exists.
        $valid = Hash::check($credentials['password'], $user->password ?? Hash::make('dummy-password'));

        if (! $user || ! $valid) {
            RateLimiter::hit($throttleKey, config('salon.login_decay_seconds'));
            $this->recorder->record('failed', $user?->id, $email, $request);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        if (! $user->is_active) {
            $this->recorder->record('failed', $user->id, $email, $request);
            throw ValidationException::withMessages(['email' => 'This account has been deactivated. Contact an administrator.']);
        }

        RateLimiter::clear($throttleKey);

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->regenerate();
            $request->session()->put(['two_factor.user_id' => $user->id, 'two_factor.remember' => $request->boolean('remember'), 'two_factor.at' => now()->timestamp]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->completeLogin($request, $user, $request->boolean('remember'));
    }

    public function challenge(Request $request)
    {
        abort_unless($request->session()->has('two_factor.user_id'), 403);

        return view('auth.two-factor-challenge');
    }

    public function verifyChallenge(Request $request)
    {
        $request->validate(['code' => ['nullable', 'string'], 'recovery_code' => ['nullable', 'string']]);
        $userId = $request->session()->get('two_factor.user_id');
        $startedAt = $request->session()->get('two_factor.at', 0);
        abort_unless($userId && now()->timestamp - $startedAt < 600, 403);

        $key = '2fa|'.$userId.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $user = User::findOrFail($userId);
        $ok = false;
        if ($request->filled('code')) {
            $ok = $this->twoFactor->verify($user->two_factor_secret, $request->input('code'));
        } elseif ($request->filled('recovery_code')) {
            $remaining = $this->twoFactor->consumeRecoveryCode($user->two_factor_recovery_codes ?? [], $request->input('recovery_code'));
            if ($remaining !== null) {
                $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();
                $this->audit->log('auth.recovery_code_used', $user);
                $ok = true;
            }
        }

        if (! $ok) {
            RateLimiter::hit($key, 60);
            $this->recorder->record('two_factor_failed', $user->id, $user->email, $request);
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid.']);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('two_factor.remember', false);
        $request->session()->forget(['two_factor.user_id', 'two_factor.at']);

        return $this->completeLogin($request, $user, $remember);
    }

    public function destroy(Request $request)
    {
        if ($user = $request->user()) {
            $this->recorder->record('logout', $user->id, $user->email, $request);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have been signed out.');
    }

    private function completeLogin(Request $request, User $user, bool $remember)
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $this->recorder->record('login', $user->id, $user->email, $request);

        return redirect()->intended(route('dashboard'));
    }
}
