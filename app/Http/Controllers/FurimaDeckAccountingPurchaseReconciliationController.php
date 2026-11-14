<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\FurimaDeckAccountingPurchaseReconciliationService;
use App\Services\SaleProfitCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class FurimaDeckAccountingPurchaseReconciliationController extends Controller
{
    public function store(
        Request $request,
        Product $product,
        string $provider,
        FurimaDeckAccountingPurchaseReconciliationService $reconciliations,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        if ((int) $product->user_id !== (int) $request->user()->id) {
            abort(404);
        }
        if (! in_array($provider, FurimaDeckAccountingPurchaseReconciliationService::PROVIDERS, true)) {
            abort(404);
        }
        if (! $request->user()->isPremium()) {
            abort(403);
        }

        try {
            $amounts = $reconciliations->expectedAmounts($product);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['purchase_reconciliation' => $exception->getMessage()]);
        }

        $rules = [
            'external_transaction_id' => ['required', 'string', 'max:255', 'not_regex:/[\x00-\x1F\x7F]/'],
            'transaction_date' => ['required', 'date_format:Y-m-d'],
            'debit_total' => ['required', 'integer', 'min:0', 'max:'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT],
            'credit_total' => ['required', 'integer', 'min:0', 'max:'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT],
            'debit_accounts' => ['required', 'string', 'max:2000'],
            'credit_accounts' => ['required', 'string', 'max:2000'],
            'expense_evidence' => ['required', 'array:'.implode(',', array_keys(FurimaDeckAccountingPurchaseReconciliationService::EXPENSES))],
            'confirmations' => ['required', 'array:service,same_purchase,date,amount,accounts_tax,no_resend'],
            'confirmations.service' => ['required', 'accepted'],
            'confirmations.same_purchase' => ['required', 'accepted'],
            'confirmations.date' => ['required', 'accepted'],
            'confirmations.amount' => ['required', 'accepted'],
            'confirmations.accounts_tax' => ['required', 'accepted'],
            'confirmations.no_resend' => ['required', 'accepted'],
        ];

        foreach ($amounts as $field => $amount) {
            $required = $amount > 0;
            $path = 'expense_evidence.'.$field;
            if (! $required) {
                $rules[$path] = ['prohibited'];

                continue;
            }
            $rules[$path] = ['required', 'array:document_type,tax_category,invoice_issuer,accounting_treatment,external_tax_classification,cap_review_id,date_matches_entry_confirmed'];
            $rules[$path.'.document_type'] = ['required', Rule::in(Product::PURCHASE_DOCUMENT_TYPES)];
            $rules[$path.'.tax_category'] = ['required', Rule::in(array_diff(Product::PURCHASE_TAX_CATEGORIES, ['unknown']))];
            $rules[$path.'.invoice_issuer'] = ['required', Rule::in(array_diff(Product::PURCHASE_INVOICE_ISSUERS, ['unknown']))];
            $rules[$path.'.accounting_treatment'] = ['required', Rule::in(array_keys(FurimaDeckAccountingPurchaseReconciliationService::ACCOUNTING_TREATMENTS))];
            $rules[$path.'.external_tax_classification'] = ['required', 'string', 'min:1', 'max:255', 'not_regex:/^\s*$/', 'not_regex:/[\x00-\x1F\x7F]/'];
            $rules[$path.'.cap_review_id'] = [
                'nullable',
                'integer',
                Rule::exists('accounting_purchase_cap_reviews', 'id')->where('user_id', $request->user()->id),
            ];
            $rules[$path.'.date_matches_entry_confirmed'] = ['required', 'accepted'];
        }

        $validated = $request->validate($rules);
        foreach ($amounts as $field => $amount) {
            if ($amount > 0) {
                $validated['expense_evidence'][$field]['date_matches_entry_confirmed'] = true;
            }
        }

        try {
            $reconciliation = $reconciliations->record($request->user(), $product, $provider, $validated);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['purchase_reconciliation' => $exception->getMessage()])->withInput();
        }

        $auditLogger->log($request->user(), Product::class, $product->id, 'accounting.purchase.manual_reconciled', null, [
            'provider' => $provider,
            'reconciliation_id' => $reconciliation->id,
            'reviewed_by_user_id' => $request->user()->id,
        ]);

        $allocation = $reconciliations->allocationStatus($reconciliation, $product, $provider);
        $success = $allocation['is_complete']
            ? '手動照合の証跡を保存しました。'.$allocation['product_count'].'商品への配分合計と仕訳全体の金額が一致しました。会計サービスへの仕訳作成・送信は行っていません。'
            : '手動照合の証跡を保存しました。現在の配分は'.number_format($allocation['allocated_total']).'円 / 仕訳全体'.number_format($allocation['transaction_total']).'円です。残額の配分が完了するまで、この仕訳に関係する商品の売上は送信・CSV出力できません。';

        return redirect()->route('furimadeck-accounting.products.purchase.edit', $product)->with('success', $success);
    }
}
