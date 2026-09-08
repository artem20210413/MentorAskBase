<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QueryLog extends Model
{
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'conversation_session_id',
        'question',
        'answer',
        'detected_language',
        'answered_in_language',
        'source_document_ids',
        'best_match_score',
        'llm_input_tokens',
        'llm_output_tokens',
    ];

    protected $casts = [
        'source_document_ids' => 'array',
        'best_match_score' => 'integer',
        'llm_input_tokens' => 'integer',
        'llm_output_tokens' => 'integer',
        'created_at' => 'datetime',
    ];

    // FR-010b: токен-метрика — внутрішня, ніколи не потрапляє в API-відповідь
    protected $hidden = [
        'llm_input_tokens',
        'llm_output_tokens',
    ];

    public function conversationSession(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class);
    }

    public function feedback(): HasOne
    {
        return $this->hasOne(AnswerFeedback::class);
    }
}
