<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->must_change_password && ! $request->routeIs('profile.password*', 'logout')) {
            return redirect()->route('profile.password')->with('warning', 'You must set a new password before continuing.');
        }

        return $next($request);
    }
}
