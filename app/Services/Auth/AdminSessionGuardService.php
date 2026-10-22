<?php

namespace App\Services\Auth;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * contracts/web-auth.md: FR-023a — негайна інвалідація сесії при зміні
 * ролі/статусу, незалежно від стандартного таймауту `SESSION_LIFETIME`.
 */
class AdminSessionGuardService
{
    public function isValid(User $user, ?CarbonInterface $loggedInAt): bool
    {
        if ($user->status !== 'approved' || ! $user->hasAnyRole(['admin', 'super_admin'])) {
            return false;
        }

        if ($user->sessions_invalidated_at === null) {
            return true;
        }

        if ($loggedInAt === null) {
            return false;
        }

        return $loggedInAt->greaterThan($user->sessions_invalidated_at);
    }
}
