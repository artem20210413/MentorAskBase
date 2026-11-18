<?php

namespace App\Http\Middleware;

use App\Services\Auth\AdminSessionGuardService;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminSessionIsValid
{
    public function __construct(private AdminSessionGuardService $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::auth()->user();

        // config('session.serialization') === 'json' — сесія зберігає
        // Carbon-значення як рядок (ISO8601), не як об'єкт; парсимо назад тут.
        $loggedInAt = session('logged_in_at');
        $loggedInAt = $loggedInAt ? Carbon::parse($loggedInAt) : null;

        if ($user && ! $this->guard->isValid($user, $loggedInAt)) {
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect(Filament::getLoginUrl());
        }

        return $next($request);
    }
}
