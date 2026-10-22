<?php

namespace App\Services\Telegram;

use App\Models\User;
use Carbon\CarbonInterface;

/**
 * FR-006b: наростаюча шкала очікування повторної заявки після відхилення.
 */
class ReapplicationCooldownService
{
    /** @var array<int, int> rejection_count => години очікування */
    private const HOURS_BY_ATTEMPT = [
        1 => 24,
        2 => 48,
        3 => 72,
        4 => 24 * 7,
    ];

    private const FALLBACK_HOURS = 24 * 30;

    public function nextAllowedAt(User $user): ?CarbonInterface
    {
        if ($user->rejection_count < 1 || $user->last_rejected_at === null) {
            return null;
        }

        $hours = self::HOURS_BY_ATTEMPT[$user->rejection_count] ?? self::FALLBACK_HOURS;

        return $user->last_rejected_at->copy()->addHours($hours);
    }

    public function canReapplyNow(User $user): bool
    {
        $nextAllowedAt = $this->nextAllowedAt($user);

        return $nextAllowedAt === null || $nextAllowedAt->isPast();
    }
}
