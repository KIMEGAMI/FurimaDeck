<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class ProductAcquisitionInfoUpdateService
{
    /**
     * Record and propagate acquisition changes after the product row is updated
     * inside the caller's transaction.
     *
     * @param  array<string, mixed>  $beforeState
     */
    public function afterProductUpdate(
        Product $product,
        User $actor,
        array $beforeState,
        Request $request,
        ListingProfitEstimator $profitEstimator,
        AuditLogger $auditLogger,
    ): bool {
        $before = $this->normalizedValues($beforeState);
        $after = $this->normalizedValues($product->getAttributes());

        if ($before === $after) {
            return false;
        }

        if (app(FurimaDeckPurchaseInfoLock::class)->isLocked($product)) {
            throw ValidationException::withMessages([
                'purchase_info' => '販売・会計記録があるため仕入情報を変更できません。記録との不一致を防ぐため、会計データを確認してください。',
            ]);
        }

        app(ProductInventoryEventService::class)->recordPurchaseInfoChange(
            $product,
            $actor,
            $beforeState,
            '商品管理画面で仕入情報を更新',
        );

        if ($product->purchase_accounting_enabled) {
            $product->accountingEntries()
                ->where('entry_type', AccountingEntry::TYPE_PURCHASE)
                ->update(['source_status' => 'review', 'confirmed_at' => null]);
        }

        foreach ($product->sales()->where('status', 'pending')->get() as $pendingSale) {
            $amounts = Collection::make($pendingSale->only([
                'sold_price', 'cost_basis', 'sales_fee', 'shipping_fee', 'purchase_shipping_cost',
                'packing_cost', 'repair_cost', 'cleaning_cost', 'other_expense',
            ]))->map(fn (mixed $value): int => (int) ($value ?? 0))->all();
            $amounts['cost_basis'] = $product->purchase_accounting_enabled
                ? app(FurimaDeckAccountingEntryService::class)->costBasisForNewSale($actor, $product)
                : (int) $product->purchase_unit_cost * (int) $pendingSale->quantity;
            if ($product->purchase_accounting_enabled) {
                $amounts['purchase_shipping_cost'] = 0;
            }

            $pendingSale->update([
                'cost_basis' => $amounts['cost_basis'],
                'net_profit' => app(SaleProfitCalculator::class)->calculate($amounts),
            ]);
        }

        foreach ($product->listings()->whereNotIn('status', ['sold', 'ended', 'cancelled'])->get() as $listing) {
            if ($listing->listing_price === null) {
                $listing->update(['expected_profit' => null, 'expected_margin' => null]);

                continue;
            }

            try {
                $estimate = $profitEstimator->estimate(
                    $product,
                    max(1, (int) $listing->listing_quantity),
                    (int) $listing->listing_price,
                    (string) $listing->expected_fee_rate,
                    (int) $listing->shipping_fee,
                );
            } catch (InvalidArgumentException|RuntimeException) {
                $estimate = ['expected_profit' => null, 'expected_margin' => null];
            }
            $listing->update($estimate);
        }

        $auditLogger->log(
            $actor,
            Product::class,
            $product->id,
            'product.purchase_info.updated',
            $before,
            $after,
            $request->ip(),
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalizedValues(array $values): array
    {
        $date = $values['purchase_date'] ?? null;

        return [
            'purchase_date' => $date instanceof CarbonInterface ? $date->toDateString() : ($date === null ? null : (string) $date),
            'purchase_unit_cost' => (int) ($values['purchase_unit_cost'] ?? 0),
            'purchase_quantity' => (int) ($values['purchase_quantity'] ?? Product::UNITS_PER_PRODUCT_ID),
            'quantity_available' => (int) ($values['quantity_available'] ?? Product::UNITS_PER_PRODUCT_ID),
            'purchase_shipping_cost' => (int) ($values['purchase_shipping_cost'] ?? 0),
            'other_purchase_expense' => (int) ($values['other_purchase_expense'] ?? 0),
            'supplier_id' => $values['supplier_id'] === null ? null : (int) $values['supplier_id'],
            'purchase_info_confirmed' => (bool) ($values['purchase_info_confirmed'] ?? false),
        ];
    }
}
