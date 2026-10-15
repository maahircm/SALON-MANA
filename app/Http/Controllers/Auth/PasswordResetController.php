<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LoginRecorder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function request()
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);
        // Neutral response: never reveal whether an account exists.
        Password::sendResetLink($request->only('email'));

        return back()->with('success', 'If that address belongs to an active account, a reset link has been sent.');
    }

    public function form(Request $request, string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request, LoginRecorder $recorder)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($recorder, $request) {
                abort_unless($user->is_active, 403);
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'must_change_password' => false, 'password_changed_at' => now()])->save();
                $recorder->record('password_reset', $user->id, $user->email, $request);
                event(new PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('success', 'Your password has been reset. Please sign in.')
            : back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
    }

    public function recoveryHelp()
    {
        return view('auth.recovery-help');
    }
}
