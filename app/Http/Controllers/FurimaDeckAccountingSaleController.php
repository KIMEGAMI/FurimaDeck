<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\AuditLogger;
use App\Services\FurimaDeckInvoiceTransitionCapService;
use App\Services\FurimaDeckSaleExpenseAccountingService;
use App\Services\SaleLifecycleService;
use App\Services\SaleProfitCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

class FurimaDeckAccountingSaleController extends Controller
{
    /** @var array<string, string> */
    private const SALE_AMOUNT_FIELDS = [
        'cost_basis' => '仕入原価',
        'purchase_shipping_cost' => '販売記録の仕入送料',
        'packing_cost' => '梱包費',
        'repair_cost' => '修理費',
        'cleaning_cost' => '清掃費',
        'other_expense' => 'その他費用',
    ];

    public function edit(
        Request $request,
        Sale $sale,
        SaleLifecycleService $lifecycle,
        FurimaDeckSaleExpenseAccountingService $expenses,
        FurimaDeckInvoiceTransitionCapService $capReviews,
    ): View {
        $this->ensureOwner($request, $sale);
        abort_unless(in_array($sale->status, Sale::VALID_SOLD_STATUSES, true), 404);

        $expenseFields = array_filter(
            FurimaDeckSaleExpenseAccountingService::EXPENSES,
            fn (string $field): bool => (int) $sale->{$field} > 0,
            ARRAY_FILTER_USE_KEY,
        );

        return view('furimadeck_accounting.sale-expenses', [
            'sale' => $sale->loadMissing('product', 'marketplace'),
            'detailsLocked' => $lifecycle->amountCorrectionBlockReason($request->user(), $sale) !== null,
            'saleAmountFields' => self::SALE_AMOUNT_FIELDS,
            'expenseLabels' => FurimaDeckSaleExpenseAccountingService::EXPENSES,
            'expenseFields' => $expenseFields,
            'paymentSourceOptions' => $expenses->paymentSourceOptions($request->user()),
            'purchaseCapReviews' => $capReviews->reviewsForUser($request->user()),
            'documentTypes' => FurimaDeckSaleExpenseAccountingService::DOCUMENT_TYPE_LABELS,
            'taxCategories' => FurimaDeckSaleExpenseAccountingService::TAX_CATEGORY_LABELS,
            'invoiceIssuers' => FurimaDeckSaleExpenseAccountingService::INVOICE_ISSUER_LABELS,
        ]);
    }

    public function update(
        Request $request,
        Sale $sale,
        FurimaDeckSaleExpenseAccountingService $expenses,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $this->ensureOwner($request, $sale);
        abort_unless(in_array($sale->status, Sale::VALID_SOLD_STATUSES, true), 404);

        $rules = [
            'accounting_expense_details' => ['nullable', 'array:'.implode(',', array_keys(FurimaDeckSaleExpenseAccountingService::EXPENSES))],
        ];
        foreach (FurimaDeckSaleExpenseAccountingService::EXPENSES as $field => $_label) {
            $rules['accounting_expense_details.'.$field] = ['nullable', 'array:document_type,tax_category,invoice_issuer,payment_sources,cap_review_id,date_matches_entry_confirmed'];
            $rules['accounting_expense_details.'.$field.'.document_type'] = ['nullable', Rule::in(array_keys(FurimaDeckSaleExpenseAccountingService::DOCUMENT_TYPE_LABELS))];
            $rules['accounting_expense_details.'.$field.'.tax_category'] = ['nullable', Rule::in(array_keys(FurimaDeckSaleExpenseAccountingService::TAX_CATEGORY_LABELS))];
            $rules['accounting_expense_details.'.$field.'.invoice_issuer'] = ['nullable', Rule::in(array_keys(FurimaDeckSaleExpenseAccountingService::INVOICE_ISSUER_LABELS))];
            $rules['accounting_expense_details.'.$field.'.date_matches_entry_confirmed'] = ['sometimes', 'accepted'];
            $rules['accounting_expense_details.'.$field.'.cap_review_id'] = [
                'nullable',
                'integer',
                Rule::exists('accounting_purchase_cap_reviews', 'id')->where('user_id', $request->user()->id),
            ];
            $rules['accounting_expense_details.'.$field.'.payment_sources'] = ['nullable', 'array:freee,money_forward'];
            foreach (['freee', 'money_forward'] as $provider) {
                $rules['accounting_expense_details.'.$field.'.payment_sources.'.$provider] = ['nullable', 'string', 'max:255'];
            }
        }

        $validated = $request->validate($rules);
        $before = (array) ($sale->accounting_expense_details ?? []);
        try {
            $updated = $expenses->updateExpenseDetails(
                $request->user(),
                $sale,
                (array) ($validated['accounting_expense_details'] ?? []),
            );
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['accounting_expense_details' => $exception->getMessage()]);
        }
        $after = (array) ($updated->accounting_expense_details ?? []);
        if ($before !== $after) {
            $auditLogger->log($request->user(), Sale::class, $sale->id, 'sale.accounting_expense_details_updated', $before, $after, $request->ip());
        }

        return to_route('furimadeck-accounting.sales.expenses.edit', $updated)
            ->with('success', '販売費用の会計情報を保存しました。会計データ一覧で内容を再確認してください。');
    }

    public function updateAmounts(
        Request $request,
        Sale $sale,
        SaleLifecycleService $lifecycle,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $this->ensureOwner($request, $sale);
        abort_unless(in_array($sale->status, Sale::VALID_SOLD_STATUSES, true), 404);

        $rules = ['record_version' => ['required', 'string']];
        foreach (self::SALE_AMOUNT_FIELDS as $field => $_label) {
            $rules[$field] = ['required', 'integer', 'min:0', 'max:'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT];
        }
        $validated = $request->validate($rules);
        $before = $sale->getAttributes();

        try {
            $updated = $lifecycle->correctAmounts($request->user(), $sale, [
                ...$validated,
                'sold_at' => $sale->sold_at->format('Y-m-d\\TH:i'),
                'sold_price' => (int) $sale->sold_price,
                'sales_fee' => (int) $sale->sales_fee,
                'shipping_fee' => (int) $sale->shipping_fee,
            ]);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['accounting_amounts' => $exception->getMessage()]);
        }

        if ($before !== $updated->getAttributes()) {
            $auditLogger->log($request->user(), Sale::class, $sale->id, 'sale.accounting_amounts_updated', $before, $updated->getAttributes(), $request->ip());
        }

        return to_route('furimadeck-accounting.sales.expenses.edit', $updated)
            ->with('success', '会計金額を保存しました。変更した費用の証憑を確認してください。');
    }

    public function updateProviderSources(
        Request $request,
        Sale $sale,
        string $provider,
        FurimaDeckSaleExpenseAccountingService $expenses,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $this->ensureOwner($request, $sale);
        abort_unless(in_array($sale->status, Sale::VALID_SOLD_STATUSES, true), 404);

        $rules = ['payment_sources' => ['required', 'array:'.implode(',', array_keys(FurimaDeckSaleExpenseAccountingService::EXPENSES))]];
        foreach (FurimaDeckSaleExpenseAccountingService::EXPENSES as $field => $_label) {
            $rules['payment_sources.'.$field] = ['nullable', 'string', 'max:255'];
        }
        $validated = $request->validate($rules);
        $before = (array) ($sale->accounting_expense_details ?? []);
        try {
            $updated = $expenses->updateProviderSources($request->user(), $sale, $provider, $validated['payment_sources']);
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['payment_sources' => $exception->getMessage()]);
        }
        $after = (array) ($updated->accounting_expense_details ?? []);
        if ($before !== $after) {
            $auditLogger->log($request->user(), Sale::class, $sale->id, 'sale.accounting_expense_sources_updated', $before, $after, $request->ip());
        }

        return to_route('furimadeck-accounting.sales.expenses.edit', $updated)
            ->with('success', '未送信サービスの費目別支払元を保存しました。送信済みサービスの内容は変更していません。');
    }

    private function ensureOwner(Request $request, Sale $sale): void
    {
        abort_unless((int) $sale->user_id === (int) $request->user()->id, 404);
    }
}
