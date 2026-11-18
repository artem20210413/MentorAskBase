<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FR-013/FR-014/FR-012b: кеш AI-резюме документа за мовою.
 */
#[Fillable(['document_id', 'locale', 'summary'])]
class DocumentSummary extends Model
{
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
