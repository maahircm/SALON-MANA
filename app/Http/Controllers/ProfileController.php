<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\TwoFactorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function __construct(private AuditLogger $audit, private TwoFactorService $twoFactor) {}

    public function edit(Request $request)
    {
        $user = $request->user();
        $setupSecret = null;
        if (! $user->hasTwoFactorEnabled() && $user->two_factor_secret) {
            $setupSecret = $user->two_factor_secret;
        }

        return view('profile.edit', [
            'user' => $user,
            'activities' => $user->loginActivities()->latest('created_at')->limit(10)->get(),
            'setupSecret' => $setupSecret,
            'otpauth' => $setupSecret ? $this->twoFactor->otpauthUri($setupSecret, $user->email, config('salon.name')) : null,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'locale' => ['required', Rule::in(['en', 'so'])],
        ]);
        $user->fill($data)->save();

        return back()->with('success', 'Profile updated.');
    }

    public function password()
    {
        return view('profile.password');
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);
        $user = $request->user();
        $user->forceFill(['password' => $request->input('password'), 'must_change_password' => false, 'password_changed_at' => now()])->save();
        // Invalidate other devices' sessions.
        Auth::logoutOtherDevices($request->input('password'));
        $this->audit->log('auth.password_changed', $user);

        return redirect()->route('profile.edit')->with('success', 'Password changed.');
    }

    public function enableTwoFactor(Request $request)
    {
        $user = $request->user();
        abort_if($user->hasTwoFactorEnabled(), 422);
        $user->forceFill(['two_factor_secret' => $this->twoFactor->generateSecret(), 'two_factor_recovery_codes' => null])->save();

        return redirect()->route('profile.edit')->with('info', 'Scan the key with your authenticator app, then confirm with a code.');
    }

    public function confirmTwoFactor(Request $request)
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();
        abort_unless($user->two_factor_secret && ! $user->hasTwoFactorEnabled(), 422);

        if (! $this->twoFactor->verify($user->two_factor_secret, $request->input('code'))) {
            return back()->withErrors(['code' => 'The code is invalid.']);
        }
        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes['hashed']])->save();
        $this->audit->log('auth.two_factor_enabled', $user);

        return redirect()->route('profile.edit')->with('recovery_codes', $codes['plain'])->with('success', 'Two-factor authentication enabled.');
    }

    public function disableTwoFactor(Request $request)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $user = $request->user();
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit->log('auth.two_factor_disabled', $user);

        return redirect()->route('profile.edit')->with('success', 'Two-factor authentication disabled.');
    }
}
