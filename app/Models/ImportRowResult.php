<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRowResult extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['row_number', 'row_json', 'errors_json', 'is_valid'];

    protected function casts(): array
    {
        return ['row_json' => 'array', 'errors_json' => 'array', 'is_valid' => 'boolean', 'created_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}
