<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingEntry extends Model
{
    protected $fillable = [
        'user_id', 'sale_id', 'transaction_date', 'management_id', 'title', 'marketplace',
        'sale_amount', 'purchase_cost', 'selling_fee', 'shipping_cost', 'other_cost',
        'furimadeck_actual_profit', 'note', 'source_status', 'sync_status',
        'external_transaction_id', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'sale_amount' => 'integer',
            'purchase_cost' => 'integer',
            'selling_fee' => 'integer',
            'shipping_cost' => 'integer',
            'other_cost' => 'integer',
            'furimadeck_actual_profit' => 'integer',
            'synced_at' => 'datetime',
        ];
    }

    public function scopeEligibleForAccounting(Builder $query): Builder
    {
        return $query->whereHas('sale', fn (Builder $saleQuery): Builder => $saleQuery->whereNotIn('status', ['cancelled', 'returned']));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
