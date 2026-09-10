<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $fillable = ['batch_uuid', 'filename', 'format', 'total_rows', 'success_rows', 'failed_rows', 'status'];

    protected function casts(): array
    {
        return ['total_rows' => 'integer', 'success_rows' => 'integer', 'failed_rows' => 'integer'];
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
