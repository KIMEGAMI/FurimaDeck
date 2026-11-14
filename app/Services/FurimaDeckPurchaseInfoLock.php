<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\Product;

class FurimaDeckPurchaseInfoLock
{
    public function isLocked(Product $product): bool
    {
        if ($product->manualPurchaseReconciliations()->exists() || $product->sales()->where('status', '!=', 'pending')->exists()) {
            return true;
        }

        $purchaseEntries = $product->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE);
        if ($purchaseEntries->exists() && ! $product->purchase_accounting_enabled) {
            return true;
        }

        return $purchaseEntries
            ->where(function ($query): void {
                $query->whereNotNull('external_transaction_id')
                    ->orWhere('sync_status', '!=', 'not_synced')
                    ->orWhereHas('providerSyncs', fn ($syncs) => $syncs->whereIn('status', [
                        'queued', 'sending', 'synced', 'sync_unknown', 'legacy_unknown', 'csv_pending',
                        AccountingEntrySync::STATUS_CSV_IMPORTED,
                        AccountingEntrySync::STATUS_MANUALLY_RECONCILED,
                    ]));
            })
            ->exists();
    }
}
