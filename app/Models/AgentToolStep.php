<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentToolStep extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'query_log_id',
        'step_number',
        'tool',
        'input',
        'output',
    ];

    protected $casts = [
        'step_number' => 'integer',
        'created_at' => 'datetime',
    ];

    public function queryLog(): BelongsTo
    {
        return $this->belongsTo(QueryLog::class);
    }
}
