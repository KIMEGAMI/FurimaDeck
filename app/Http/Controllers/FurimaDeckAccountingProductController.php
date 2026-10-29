<?php

namespace App\Http\Controllers;

use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\FurimaDeckAccountingEntryService;
use App\Services\FurimaDeckAccountingPurchaseReconciliationService;
use App\Services\FurimaDeckInvoiceTransitionCapService;
use App\Services\FurimaDeckPurchaseSpecialCaseService;
use App\Services\ListingProfitEstimator;
use App\Services\SaleProfitCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

class FurimaDeckAccountingProductController extends Controller
{
    public function purchase(Request $request, Product $product): View
    {
        $this->ensureOwner($request, $product);
        $purchaseEntryExists = $product->accountingEntries()
            ->where('entry_type', AccountingEntry::TYPE_PURCHASE)
            ->exists();
        $hasManualReconciliation = $product->manualPurchaseReconciliations()->exists();
        $reconciliationService = app(FurimaDeckAccountingPurchaseReconciliationService::class);
        $reconciliations = $reconciliationService->forProduct($product)->mapWithKeys(function ($reconciliation, string $provider) use ($reconciliationService, $product): array {
            $evidence = $reconciliation->latestEvidence() ?? [];

            return [$provider => [
                'record' => $reconciliation,
                'is_current' => $reconciliationService->isCurrent($reconciliation, $product, $provider),
                'evidence' => $evidence,
                'allocation' => $reconciliationService->allocationStatus($reconciliation, $product, $provider, $evidence),
            ]];
        });

        return view('furimadeck_accounting.purchase-product', [
            'accountingContext' => true,
            'product' => $product,
            'manualPurchaseReconciliationEligible' => $product->purchase_accounting_enabled
                ? $purchaseEntryExists
                : ! $purchaseEntryExists,
            'canCreatePurchaseEntry' => $product->purchase_accounting_enabled
                || (! $purchaseEntryExists && ! $hasManualReconciliation),
            'hasManualPurchaseReconciliation' => $hasManualReconciliation,
            'manualPurchaseReconciliations' => $reconciliations,
            'purchaseCapReviews' => app(FurimaDeckInvoiceTransitionCapService::class)->reviewsForUser($request->user()),
            'purchasePaymentAccounts' => $this->purchasePaymentAccounts($request),
        ]);
    }

    public function purchaseInfo(Request $request, Product $product): View
    {
        $this->ensureOwner($request, $product);

        return view('furimadeck_accounting.purchase-info', [
            'product' => $product->load('supplier'),
            'canEditPurchaseInfo' => ! $this->purchaseInfoIsLocked($product),
            'suppliers' => $request->user()->suppliers()->orderBy('name')->get(),
            'canManageAccountingEvidence' => $request->user()->hasActiveSubscription(),
        ]);
    }

    public function updatePurchaseInfo(Request $request, Product $product, ListingProfitEstimator $profitEstimator, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureOwner($request, $product);
        if ($this->purchaseInfoIsLocked($product)) {
            throw ValidationException::withMessages([
                'purchase_info' => '販売・会計記録があるため仕入情報を変更できません。記録との不一致を防ぐため、会計データを確認してください。',
            ]);
        }

        $maximumAmount = SaleProfitCalculator::MAX_UNSIGNED_AMOUNT;
        $validated = $request->validate([
            'purchase_date' => ['nullable', 'date'],
            'purchase_unit_cost' => ['required', 'integer', 'min:0', 'max:'.$maximumAmount],
            'purchase_shipping_cost' => ['nullable', 'integer', 'min:0', 'max:'.$maximumAmount],
            'other_purchase_expense' => ['nullable', 'integer', 'min:0', 'max:'.$maximumAmount],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('user_id', $request->user()->id)],
        ]);

        $fields = ['purchase_date', 'purchase_unit_cost', 'purchase_shipping_cost', 'other_purchase_expense', 'supplier_id'];
        try {
            DB::connection('furimadeck')->transaction(function () use ($request, $product, $validated, $fields, $profitEstimator, $auditLogger): void {
                $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                if ($this->purchaseInfoIsLocked($lockedProduct)) {
                    throw new RuntimeException('販売・会計記録があるため仕入情報を変更できません。');
                }
                $before = $lockedProduct->only($fields);
                $lockedProduct->update([
                    'purchase_date' => $validated['purchase_date'] ?? null,
                    'purchase_unit_cost' => (int) $validated['purchase_unit_cost'],
                    'purchase_shipping_cost' => (int) ($validated['purchase_shipping_cost'] ?? 0),
                    'other_purchase_expense' => (int) ($validated['other_purchase_expense'] ?? 0),
                    'supplier_id' => $validated['supplier_id'] ?? null,
                    'purchase_info_confirmed' => true,
                ]);
                $lockedProduct->refresh();

                $purchaseInfoChanged = collect($fields)->contains(
                    fn (string $field): bool => (string) ($before[$field] ?? '') !== (string) ($lockedProduct->getAttribute($field) ?? ''),
                );
                if ($purchaseInfoChanged && $lockedProduct->purchase_accounting_enabled) {
                    $lockedProduct->accountingEntries()
                        ->where('entry_type', AccountingEntry::TYPE_PURCHASE)
                        ->update(['source_status' => 'review', 'confirmed_at' => null]);
                    app(FurimaDeckAccountingEntryService::class)->refreshNewPurchaseEntry($request->user(), $lockedProduct);
                }

                foreach ($lockedProduct->sales()->where('status', 'pending')->get() as $pendingSale) {
                    $amounts = collect($pendingSale->only([
                        'sold_price', 'cost_basis', 'sales_fee', 'shipping_fee', 'purchase_shipping_cost',
                        'packing_cost', 'repair_cost', 'cleaning_cost', 'other_expense',
                    ]))->map(fn (mixed $value): int => (int) ($value ?? 0))->all();
                    $amounts['cost_basis'] = $lockedProduct->purchase_accounting_enabled
                        ? app(FurimaDeckAccountingEntryService::class)->costBasisForNewSale($request->user(), $lockedProduct)
                        : (int) $lockedProduct->purchase_unit_cost * (int) $pendingSale->quantity;
                    if ($lockedProduct->purchase_accounting_enabled) {
                        $amounts['purchase_shipping_cost'] = 0;
                    }

                    $pendingSale->update([
                        'cost_basis' => $amounts['cost_basis'],
                        'net_profit' => app(SaleProfitCalculator::class)->calculate($amounts),
                    ]);
                }

                foreach ($lockedProduct->listings()->whereNotIn('status', ['sold', 'ended', 'cancelled'])->get() as $listing) {
                    if ($listing->listing_price === null) {
                        $listing->update(['expected_profit' => null, 'expected_margin' => null]);

                        continue;
                    }

                    try {
                        $estimate = $profitEstimator->estimate(
                            $lockedProduct,
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
                    $request->user(),
                    Product::class,
                    $lockedProduct->id,
                    'product.purchase_info.updated',
                    $before,
                    $lockedProduct->fresh()->only($fields),
                    $request->ip(),
                );
            });
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['purchase_info' => $exception->getMessage()]);
        }

        return redirect()->route('furimadeck-accounting.products.purchase-info.edit', $product)->with('accounting_success', '仕入情報を保存しました。商品原価と未終了の出品情報の見込み利益にも反映しました。仕入会計明細がある商品は、証憑を再確認するまで会計データの出力が保留されます。');
    }

    public function updatePurchase(Request $request, Product $product): RedirectResponse
    {
        $this->ensureOwner($request, $product);
        if (! $product->purchase_accounting_enabled && (
            $product->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->exists()
            || $product->manualPurchaseReconciliations()->exists()
        )) {
            throw ValidationException::withMessages([
                'purchase_accounting' => 'この商品には既存の仕入会計記録があります。重複を防ぐため新規仕入として登録できません。',
            ]);
        }

        $amounts = [
            'purchase_unit_cost' => (int) $product->purchase_unit_cost * (int) $product->purchase_quantity,
            'purchase_shipping_cost' => (int) $product->purchase_shipping_cost,
            'other_purchase_expense' => (int) $product->other_purchase_expense,
        ];
        $purchasePaymentAccounts = $this->purchasePaymentAccounts($request);
        $specialCaseEnabled = $request->boolean('purchase_special_case.enabled');
        $rules = [
            'confirm_new_purchase' => $product->purchase_accounting_enabled ? ['sometimes', 'accepted'] : ['required', 'accepted'],
            'purchase_evidence' => ['required', 'array:purchase_unit_cost,purchase_shipping_cost,other_purchase_expense'],
            'purchase_special_case' => ['nullable', 'array:enabled,license_number,counterparty_name,counterparty_address,confirmations'],
            'purchase_special_case.enabled' => ['nullable', 'boolean'],
            'purchase_special_case.license_number' => ['nullable', 'string', 'max:100'],
            'purchase_special_case.counterparty_name' => ['nullable', 'string', 'max:255'],
            'purchase_special_case.counterparty_address' => ['nullable', 'string', 'max:1000'],
            'purchase_special_case.confirmations' => ['nullable', 'array:dealer_license,qualifying_used_goods,resale_inventory,supplier_unqualified,not_specific_metal_scrap'],
            'purchase_special_case.confirmations.dealer_license' => ['sometimes', 'accepted'],
            'purchase_special_case.confirmations.qualifying_used_goods' => ['sometimes', 'accepted'],
            'purchase_special_case.confirmations.resale_inventory' => ['sometimes', 'accepted'],
            'purchase_special_case.confirmations.supplier_unqualified' => ['sometimes', 'accepted'],
            'purchase_special_case.confirmations.not_specific_metal_scrap' => ['sometimes', 'accepted'],
        ];

        if ($specialCaseEnabled) {
            foreach (['license_number', 'counterparty_name', 'counterparty_address'] as $field) {
                $rules['purchase_special_case.'.$field] = ['required', 'string', 'max:'.($field === 'counterparty_address' ? '1000' : ($field === 'counterparty_name' ? '255' : '100'))];
            }
            foreach (app(FurimaDeckPurchaseSpecialCaseService::class)->requiredConfirmations($product->purchase_date?->toDateString()) as $confirmation) {
                $rules['purchase_special_case.confirmations.'.$confirmation] = ['required', 'accepted'];
            }
        }

        foreach ($amounts as $expenseKey => $amount) {
            $required = $amount > 0;
            $rules['purchase_evidence.'.$expenseKey] = [Rule::requiredIf($required), 'nullable', 'array:document_type,tax_category,invoice_issuer,reviewed,cap_review_id'];
            $rules['purchase_evidence.'.$expenseKey.'.document_type'] = [Rule::requiredIf($required), 'nullable', Rule::in(Product::PURCHASE_DOCUMENT_TYPES)];
            $rules['purchase_evidence.'.$expenseKey.'.tax_category'] = [Rule::requiredIf($required), 'nullable', Rule::in(Product::PURCHASE_TAX_CATEGORIES)];
            $rules['purchase_evidence.'.$expenseKey.'.invoice_issuer'] = [Rule::requiredIf($required), 'nullable', Rule::in(Product::PURCHASE_INVOICE_ISSUERS)];
            $rules['purchase_evidence.'.$expenseKey.'.reviewed'] = [$required ? 'accepted' : 'nullable'];
            $rules['purchase_evidence.'.$expenseKey.'.cap_review_id'] = [
                'nullable', 'integer',
                Rule::exists('accounting_purchase_cap_reviews', 'id')->where('user_id', $request->user()->id),
            ];
        }

        foreach (['freee', 'money_forward'] as $provider) {
            $rules['purchase_payment_sources.'.$provider] = [
                'nullable', 'string', 'max:255', Rule::in(array_keys($purchasePaymentAccounts[$provider] ?? [])),
            ];
        }

        if ($product->purchase_date === null) {
            throw ValidationException::withMessages(['purchase_date' => '会計データを作成する前に、会計データの仕入情報画面で仕入日を登録してください。']);
        }
        if ((int) $product->purchase_quantity !== 1) {
            throw ValidationException::withMessages(['purchase_accounting' => '仕入の正確な配賦を確認できるまで、会計対象の仕入は1商品1点に限定します。']);
        }

        $validated = $request->validate($rules);
        $maximumAmount = SaleProfitCalculator::MAX_UNSIGNED_AMOUNT;
        if (array_sum($amounts) <= 0 || array_sum($amounts) > $maximumAmount) {
            throw ValidationException::withMessages(['purchase_accounting' => '仕入合計が会計データの保存可能範囲外です。会計データの仕入情報画面で金額を確認してください。']);
        }

        $evidenceByExpense = [];
        foreach ($amounts as $expenseKey => $amount) {
            if ($amount <= 0) {
                continue;
            }
            $evidence = $validated['purchase_evidence'][$expenseKey];
            $evidenceByExpense[$expenseKey] = [
                'document_type' => $evidence['document_type'],
                'tax_category' => $evidence['tax_category'],
                'invoice_issuer' => $evidence['invoice_issuer'],
                'date_matches_entry_confirmed' => true,
                'reviewed_at' => now()->toIso8601String(),
                ...(isset($evidence['cap_review_id']) && (string) $evidence['cap_review_id'] !== ''
                    ? ['cap_review_id' => (int) $evidence['cap_review_id']]
                    : []),
            ];
        }

        $specialCaseData = null;
        if ($specialCaseEnabled) {
            if (($evidenceByExpense['purchase_unit_cost']['invoice_issuer'] ?? null) !== 'unqualified') {
                throw ValidationException::withMessages(['purchase_special_case.enabled' => 'この特例は商品代の証憑発行者が適格請求書発行事業者ではない場合に限り選択してください。']);
            }
            $specialCase = $validated['purchase_special_case'] ?? [];
            foreach (['license_number', 'counterparty_name', 'counterparty_address'] as $field) {
                if (trim((string) ($specialCase[$field] ?? '')) === '') {
                    throw ValidationException::withMessages(['purchase_special_case.'.$field => '古物商等の特例に必要な情報を入力してください。']);
                }
            }
            $confirmations = app(FurimaDeckPurchaseSpecialCaseService::class)->requiredConfirmations($product->purchase_date->toDateString());
            foreach ($confirmations as $confirmation) {
                if (! $request->boolean('purchase_special_case.confirmations.'.$confirmation)) {
                    throw ValidationException::withMessages(['purchase_special_case.confirmations.'.$confirmation => '特例の適用要件を確認してください。']);
                }
            }
            $specialCaseData = [
                'scheme' => 'antique_dealer',
                'license_number' => trim($specialCase['license_number']),
                'counterparty_name' => trim($specialCase['counterparty_name']),
                'counterparty_address' => trim($specialCase['counterparty_address']),
                'confirmations' => array_fill_keys($confirmations, true),
                'confirmed_at' => now()->toIso8601String(),
            ];
        }

        $paymentSources = (array) ($product->purchase_payment_sources ?? []);
        foreach ($purchasePaymentAccounts as $provider => $accounts) {
            if ($accounts === []) {
                continue;
            }
            $sourceId = trim((string) ($validated['purchase_payment_sources'][$provider] ?? ''));
            if ($sourceId === '') {
                throw ValidationException::withMessages(['purchase_payment_sources.'.$provider => '会計設定で登録した候補から、実際の支払元を選択してください。']);
            }
            $paymentSources[$provider] = $sourceId;
        }
        ksort($paymentSources);

        try {
            DB::connection('furimadeck')->transaction(function () use ($request, $product, $evidenceByExpense, $specialCaseData, $paymentSources): void {
                $lockedProduct = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
                if (! $lockedProduct->purchase_accounting_enabled && (
                    $lockedProduct->accountingEntries()->where('entry_type', AccountingEntry::TYPE_PURCHASE)->exists()
                    || $lockedProduct->manualPurchaseReconciliations()->exists()
                )) {
                    throw new RuntimeException('この商品には既存の仕入会計記録があります。重複を防ぐため新規仕入として登録できません。');
                }
                $lockedProduct->update([
                    'purchase_accounting_enabled' => true,
                    'purchase_evidence' => $evidenceByExpense,
                    'purchase_special_case_data' => $specialCaseData,
                    'purchase_payment_sources' => $paymentSources,
                    'purchase_document_type' => null,
                    'purchase_tax_category' => null,
                    'purchase_invoice_issuer' => null,
                    'purchase_document_reviewed_at' => null,
                ]);
                app(FurimaDeckAccountingEntryService::class)->refreshNewPurchaseEntry($request->user(), $lockedProduct->refresh());
            });
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['purchase_accounting' => $exception->getMessage()]);
        }

        return redirect()->route('furimadeck-accounting.products.purchase.edit', $product)->with('success', '仕入会計データを保存しました。会計サービスへはまだ送信されていません。');
    }

    /** @return array<string, array<string, string>> */
    private function purchasePaymentAccounts(Request $request): array
    {
        $accounts = ['freee' => [], 'money_forward' => []];
        $connections = $request->user()->accountingConnections()
            ->where('status', 'connected')
            ->whereIn('provider', array_keys($accounts))
            ->get()
            ->keyBy('provider');

        foreach (array_keys($accounts) as $provider) {
            $settings = (array) ($connections->get($provider)?->settings ?? []);
            foreach ((array) ($settings['purchase_payment_accounts'] ?? []) as $sourceId => $source) {
                $label = is_array($source) ? (string) ($source['name'] ?? '') : (string) $source;
                if ($label !== '') {
                    $accounts[$provider][(string) $sourceId] = $label;
                }
            }
        }

        return $accounts;
    }

    private function ensureOwner(Request $request, Product $product): void
    {
        abort_unless($product->user_id === $request->user()->id, 404);
    }

    private function purchaseInfoIsLocked(Product $product): bool
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
