<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingSyncBatch extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'period_from', 'period_to', 'status', 'total_count',
        'processed_count', 'succeeded_count', 'failed_count', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AccountingSyncBatchItem::class, 'batch_id');
    }
}
