<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'original_name',
        'content_hash',
        'storage_path',
        'size_bytes',
        'status',
        'failure_reason',
        'duplicate_of_document_id',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function duplicateOf(): ?self
    {
        return $this->duplicate_of_document_id
            ? self::find($this->duplicate_of_document_id)
            : null;
    }
}
