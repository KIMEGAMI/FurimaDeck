<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingSyncBatchItem extends Model
{
    protected $fillable = [
        'batch_id', 'accounting_entry_id', 'management_id', 'title', 'status', 'diagnostic', 'finished_at',
    ];

    protected function casts(): array
    {
        return ['finished_at' => 'datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AccountingSyncBatch::class, 'batch_id');
    }
}
