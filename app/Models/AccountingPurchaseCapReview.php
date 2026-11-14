<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPurchaseCapReview extends Model
{
    protected $fillable = [
        'user_id',
        'period_from',
        'period_to',
        'counterparty_fingerprint',
        'counterparty_name',
        'external_qualifying_purchase_amount',
        'reviewed_through',
        'furimadeck_history_complete',
        'external_history_complete',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'counterparty_name' => 'encrypted',
            'period_from' => 'date',
            'period_to' => 'date',
            'external_qualifying_purchase_amount' => 'integer',
            'reviewed_through' => 'date',
            'furimadeck_history_complete' => 'boolean',
            'external_history_complete' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'counterparty_fingerprint',
        'counterparty_name',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
