<?php

namespace App\Services;

use App\Models\AccountingConnection;
use App\Models\AccountingEntry;

class FurimaDeckPurchasePaymentSourceReadiness
{
    public function isReady(AccountingEntry $entry, string $provider, ?AccountingConnection $connection): bool
    {
        if ($entry->entry_type !== AccountingEntry::TYPE_PURCHASE) {
            return true;
        }
        if (! in_array($provider, ['freee', 'money_forward'], true)) {
            return false;
        }

        $sourceId = trim((string) data_get($entry->expense_breakdown, 'payment_sources.'.$provider, ''));
        $sources = (array) data_get($connection?->settings, 'purchase_payment_accounts', []);
        $source = (array) ($sources[$sourceId] ?? []);
        if ($sourceId === '' || $source === []) {
            return false;
        }

        $accountId = trim((string) ($source['account_id'] ?? ''));
        if ($provider === 'freee') {
            return ctype_digit($sourceId) && $accountId === $sourceId;
        }

        $subAccountId = trim((string) ($source['sub_account_id'] ?? ''));

        return $accountId !== '' && ($subAccountId === '' ? $sourceId === $accountId : $sourceId === $subAccountId);
    }
}
