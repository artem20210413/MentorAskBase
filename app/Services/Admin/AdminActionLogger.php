<?php

namespace App\Services\Admin;

use App\Models\AdminActionLog;
use App\Models\User;

/**
 * FR-026: базовий журнал адміністративних дій.
 */
class AdminActionLogger
{
    public function log(User $actor, ?User $target, string $action, array $metadata = []): AdminActionLog
    {
        return AdminActionLog::create([
            'actor_user_id' => $actor->id,
            'target_user_id' => $target?->id,
            'action' => $action,
            'metadata' => $metadata,
        ]);
    }
}
