<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $fillable = ['batch_uuid', 'filename', 'original_filename', 'format', 'total_rows', 'success_rows', 'skipped_rows', 'failed_rows', 'status', 'started_at', 'completed_at'];

    protected function casts(): array
    {
        return ['total_rows' => 'integer', 'success_rows' => 'integer', 'skipped_rows' => 'integer', 'failed_rows' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rowResults(): HasMany
    {
        return $this->hasMany(ImportRowResult::class);
    }
}
