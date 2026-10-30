<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualProductPurchaseReconciliation extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'provider',
        'product_snapshot_hash',
        'evidence_history',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'evidence_history' => 'encrypted:array',
            'reviewed_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'evidence_history',
        'product_snapshot_hash',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    /** @return array<string, mixed>|null */
    public function latestEvidence(): ?array
    {
        $history = $this->evidence_history;
        if (! is_array($history) || $history === []) {
            return null;
        }

        $latest = end($history);

        return is_array($latest) ? $latest : null;
    }
}
