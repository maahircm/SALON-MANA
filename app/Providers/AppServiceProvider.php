<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        Password::defaults(fn () => Password::min(config('salon.password_min_length'))->letters()->mixedCase()->numbers());

        // Permission keys from config/rbac.php are Gate abilities. Super Administrator passes everything;
        // any other user needs the permission through a role. Unknown abilities fall through to policies.
        Gate::before(function (User $user, string $ability) {
            if (! $user->is_active) {
                return false;
            }
            if ($user->isSuperAdmin()) {
                return true;
            }

            return $this->isCataloguedPermission($ability) ? $user->hasPermission($ability) : null;
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(config('salon.login_max_attempts'))
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('sensitive', fn (Request $request) => Limit::perMinute(10)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()));
    }

    private function isCataloguedPermission(string $ability): bool
    {
        static $keys = null;
        $keys ??= collect(config('rbac.catalog'))->flatMap(fn ($g) => array_keys($g))->flip();

        return $keys->has($ability);
    }
}
