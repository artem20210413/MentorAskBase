<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FR-019/FR-019a/FR-019b: одноразовий код для входу на веб-форму.
 */
#[Fillable(['user_id', 'code_hash', 'expires_at', 'attempts', 'consumed_at', 'invalidated_at'])]
class OneTimeCode extends Model
{
    public const UPDATED_AT = null;

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'invalidated_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
