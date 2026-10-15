<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Walks every registered route: guests must be sent to the login page, and a signed-in user who holds no permissions
 * must be refused everywhere except the few self-service pages every user needs.
 */
class RouteSecuritySweepTest extends TestCase
{
    use RefreshDatabase;

    /** Routes a signed-in user without any permission is meant to reach. */
    private const OPEN_TO_ANY_SIGNED_IN_USER = [
        'dashboard', 'profile.edit', 'profile.update', 'profile.password', 'profile.password.update', 'profile.two-factor.enable', 'profile.two-factor.confirm', 'profile.two-factor.disable',
        'logout', 'notifications.index', 'notifications.read', 'notifications.read-all', 'commissions.mine',
    ];

    /** Routes guests may reach (login, password reset, health check, two-factor challenge). */
    private const PUBLIC = ['login', 'password.request', 'password.email', 'password.reset', 'password.update', 'two-factor.challenge', 'recovery.help'];

    /** @return array<int, array{0: string, 1: string, 2: Route}> */
    private function routes(): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $routes = [];
        foreach (RouteFacade::getRoutes() as $route) {
            $uri = $route->uri();
            if ($uri === '/' || str_starts_with($uri, 'storage/') || str_starts_with($uri, '_') || $uri === 'up' || str_starts_with($uri, 'sanctum')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [$method, '/'.preg_replace('/\{[^}]+\}/', '1', $uri), $route];
            }
        }

        return $routes;
    }

    public function test_every_application_route_exists_in_the_sweep(): void
    {
        $this->assertGreaterThan(150, count($this->routes()));
    }

    public function test_guests_are_redirected_to_login_on_every_protected_route(): void
    {
        $offenders = [];
        foreach ($this->routes() as [$method, $uri, $route]) {
            $name = (string) $route->getName();
            if (in_array($name, self::PUBLIC, true) || in_array($uri, ['/login', '/forgot-password', '/reset-password', '/two-factor-challenge'], true) || str_starts_with($uri, '/reset-password')) {
                continue;
            }
            $response = $this->call($method, $uri);
            if ($response->getStatusCode() !== 302 || ! str_contains((string) $response->headers->get('Location'), '/login')) {
                $offenders[] = "{$method} {$uri} -> {$response->getStatusCode()}";
            }
        }
        $this->assertSame([], $offenders, "Routes reachable without signing in:\n".implode("\n", $offenders));
    }

    public function test_a_signed_in_user_without_permissions_is_refused_everywhere_except_self_service_pages(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $offenders = [];
        foreach ($this->routes() as [$method, $uri, $route]) {
            $name = (string) $route->getName();
            if (in_array($name, self::PUBLIC, true) || in_array($name, self::OPEN_TO_ANY_SIGNED_IN_USER, true) || in_array($uri, ['/login', '/forgot-password', '/reset-password', '/two-factor-challenge'], true) || str_starts_with($uri, '/reset-password')) {
                continue;
            }
            $status = $this->call($method, $uri)->getStatusCode();
            $parameterised = str_contains($route->uri(), '{');
            // Parameterised routes may answer 404 for the placeholder id before authorisation runs; anything else but a refusal is a finding.
            $allowed = $parameterised ? [403, 404] : [403];
            if (! in_array($status, $allowed, true)) {
                $offenders[] = "{$method} {$uri} ({$name}) -> {$status}";
            }
        }
        $this->assertSame([], $offenders, "Routes that did not refuse a user with no permissions:\n".implode("\n", $offenders));
    }

    public function test_no_controller_passes_raw_request_input_to_a_model(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if (! str_ends_with((string) $file, '.php')) {
                continue;
            }
            if (preg_match('/(create|fill|update|forceFill|firstOrCreate|updateOrCreate|insert)\(\s*\$request->(all|input)\(\s*\)/', (string) file_get_contents($file))) {
                $offenders[] = str_replace(base_path().'/', '', (string) $file);
            }
        }
        $this->assertSame([], $offenders, 'Unvalidated request data must never reach a model.');
    }

    /**
     * Placeholder ids answer 404 before authorisation, so the sweep above cannot prove parameterised routes are protected.
     * This static check requires every controller action behind a route to authorise explicitly (a gate / policy call, an
     * ability check, or a form request whose authorize() is not simply "return true").
     */
    public function test_every_controller_action_authorises_explicitly(): void
    {
        $exempt = ['App\\Http\\Controllers\\Auth\\', 'App\\Http\\Controllers\\ProfileController', 'App\\Http\\Controllers\\NotificationController', 'App\\Http\\Controllers\\DashboardController'];
        $offenders = [];
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@') || $route->getController() === null) {
                continue;
            }
            [$class, $method] = explode('@', $action);
            if (collect($exempt)->contains(fn ($prefix) => str_starts_with($class, $prefix))) {
                continue;
            }
            $reflection = new \ReflectionMethod($class, $method);
            $source = implode('', array_slice(file($reflection->getFileName()), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
            if (preg_match('/authorize|authorized|->can\(|->cannot\(|abort_(if|unless)|Gate::|isSuperAdmin|hasPermission/', $source)) {
                continue;
            }
            $guardedByRequest = false;
            foreach ($reflection->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof \ReflectionNamedType && is_subclass_of($type->getName(), FormRequest::class)) {
                    $authorize = new \ReflectionMethod($type->getName(), 'authorize');
                    $body = implode('', array_slice(file($authorize->getFileName()), $authorize->getStartLine() - 1, $authorize->getEndLine() - $authorize->getStartLine() + 1));
                    $guardedByRequest = $guardedByRequest || ! preg_match('/return\s+true\s*;/', $body);
                }
            }
            if (! $guardedByRequest) {
                $offenders[] = "{$class}@{$method} (".implode('|', $route->methods()).' /'.$route->uri().')';
            }
        }
        $this->assertSame([], array_values(array_unique($offenders)), "Controller actions without an explicit authorisation check:\n".implode("\n", array_unique($offenders)));
    }
}
