<?php

namespace App\Providers;

use App\Contracts\SmsGateway;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\SettingsService;
use App\Services\Sms\LogSmsGateway;
use App\Support\RuntimeSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
        $this->app->bind(SmsGateway::class, fn () => match (config('salon.notifications.sms_driver')) {
            'log' => new LogSmsGateway,
            default => throw new RuntimeException('SMS driver ['.config('salon.notifications.sms_driver').'] is not installed. Set SMS_DRIVER=log or add a gateway driver.'),
        });
    }

    public function boot(): void
    {
        Paginator::useBootstrapFive();

        RuntimeSettings::apply($this->app->make(SettingsService::class));
        // Long-running queue workers must pick up settings saved after they started.
        Queue::before(fn () => RuntimeSettings::apply($this->app->make(SettingsService::class)));

        View::composer('layouts.app', function ($view): void {
            $user = auth()->user();
            $view->with([
                'unreadCount' => $user ? UserNotification::where('user_id', $user->id)->whereNull('read_at')->count() : 0,
                'recentNotifications' => $user ? UserNotification::where('user_id', $user->id)->latest('id')->limit(5)->get() : collect(),
            ]);
        });

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
