<?php

namespace App\Http\Middleware;

use App\Services\Auth\AdminSessionGuardService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminSessionIsValid
{
    public function __construct(private AdminSessionGuardService $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        if ($user && ! $this->guard->isValid($user, session('logged_in_at'))) {
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect(Filament::getLoginUrl());
        }

        return $next($request);
    }
}
