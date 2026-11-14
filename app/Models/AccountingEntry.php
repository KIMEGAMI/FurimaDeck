<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingEntry extends Model
{
    private const LEGACY_QUEUED_PROVIDER_STATUSES = ['queued', 'sending'];

    private const LEGACY_UNCERTAIN_PROVIDER_STATUSES = [
        'sync_unknown',
        'legacy_unknown',
        'csv_pending',
        AccountingEntrySync::STATUS_CSV_IMPORTED,
        AccountingEntrySync::STATUS_MANUALLY_RECONCILED,
    ];

    public const TYPE_SALE = 'sale';

    public const TYPE_PURCHASE = 'purchase';

    protected $fillable = [
        'user_id', 'sale_id', 'product_id', 'entry_type', 'transaction_date', 'management_id', 'title', 'marketplace',
        'sale_amount', 'purchase_cost', 'selling_fee', 'shipping_cost', 'other_cost', 'expense_breakdown',
        'purchase_special_case_data',
        'furimadeck_actual_profit', 'note', 'source_status', 'sync_status',
        'external_transaction_id', 'synced_at', 'confirmed_at',
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
            'expense_breakdown' => 'array',
            'purchase_special_case_data' => 'encrypted:array',
            'furimadeck_actual_profit' => 'integer',
            'synced_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    protected $hidden = [
        'purchase_special_case_data',
    ];

    public function scopeEligibleForAccounting(Builder $query): Builder
    {
        return $query->where(function (Builder $entryQuery): void {
            $entryQuery
                ->where(fn (Builder $saleEntry): Builder => $saleEntry
                    ->where('entry_type', self::TYPE_SALE)
                    ->whereHas('sale', fn (Builder $saleQuery): Builder => $saleQuery
                        ->whereColumn('sales.user_id', 'accounting_entries.user_id')
                        ->whereIn('status', Sale::VALID_SOLD_STATUSES)))
                ->orWhere(fn (Builder $purchaseEntry): Builder => $purchaseEntry
                    ->where('entry_type', self::TYPE_PURCHASE)
                    ->whereHas('product', fn (Builder $productQuery): Builder => $productQuery
                        ->whereColumn('products.user_id', 'accounting_entries.user_id')));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function providerSyncs(): HasMany
    {
        return $this->hasMany(AccountingEntrySync::class, 'accounting_entry_id');
    }

    public function providerSync(string $provider): ?AccountingEntrySync
    {
        $sync = $this->relationLoaded('providerSyncs')
            ? $this->providerSyncs->firstWhere('provider', $provider)
            : $this->providerSyncs()->where('provider', $provider)->first();

        if ($sync !== null) {
            return $sync;
        }

        return new AccountingEntrySync([
            'accounting_entry_id' => $this->id,
            'provider' => $provider,
            'status' => $this->providerSyncInitialStatus($provider),
        ]);
    }

    public function providerSyncInitialStatus(string $provider): string
    {
        if ($this->sync_status === 'not_synced') {
            return 'not_synced';
        }
        if ($this->sync_status === 'synced'
            && $this->providerSyncs()->where('status', 'synced')->exists()) {
            return 'not_synced';
        }
        if ($this->sync_status === 'queued'
            && $this->providerSyncs()->whereIn('status', self::LEGACY_QUEUED_PROVIDER_STATUSES)->exists()) {
            return 'not_synced';
        }
        if ($this->sync_status === 'sync_unknown'
            && $this->providerSyncs()->whereIn('status', self::LEGACY_UNCERTAIN_PROVIDER_STATUSES)->exists()) {
            return 'not_synced';
        }
        if ($provider === 'money_forward' && $this->sync_status === 'queued') {
            return 'queued';
        }
        if ($provider === 'money_forward' && $this->sync_status === 'sync_unknown') {
            return 'sync_unknown';
        }

        return 'legacy_unknown';
    }

    public function providerSyncStatus(string $provider): string
    {
        return (string) ($this->providerSync($provider)?->status ?? 'not_synced');
    }

    public function refreshLegacySyncStatus(): void
    {
        $statuses = $this->providerSyncs()->pluck('status');
        $status = match (true) {
            $statuses->contains(fn ($value): bool => in_array($value, self::LEGACY_QUEUED_PROVIDER_STATUSES, true)) => 'queued',
            $statuses->contains(fn ($value): bool => in_array($value, self::LEGACY_UNCERTAIN_PROVIDER_STATUSES, true)) => 'sync_unknown',
            $statuses->contains('synced') => 'synced',
            default => 'not_synced',
        };

        $this->update(['sync_status' => $status]);
    }
}
