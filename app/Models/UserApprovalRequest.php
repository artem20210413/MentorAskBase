<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FR-003/FR-004-FR-008: заявка на апрув нового користувача.
 */
#[Fillable([
    'user_id', 'status', 'decided_by_user_id', 'decided_at',
    'moderation_chat_id', 'moderation_message_id', 'last_reminder_at',
])]
class UserApprovalRequest extends Model
{
    protected $casts = [
        'decided_at' => 'datetime',
        'last_reminder_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
