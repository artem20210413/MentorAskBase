<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnswerFeedback extends Model
{
    use HasFactory;

    protected $table = 'answer_feedback';

    protected $fillable = [
        'query_log_id',
        'rating',
        'comment',
    ];

    public function queryLog(): BelongsTo
    {
        return $this->belongsTo(QueryLog::class);
    }
}
