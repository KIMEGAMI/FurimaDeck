<?php

namespace App\Http\Controllers;

use App\Models\AccountingPurchaseCapReview;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FurimaDeckInvoiceTransitionCapService;
use App\Services\SaleProfitCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FurimaDeckAccountingPurchaseCapReviewController extends Controller
{
    public function index(Request $request): View
    {
        return view('furimadeck_accounting.purchase-cap-reviews', [
            'reviews' => $request->user()->accountingPurchaseCapReviews()->orderByDesc('period_from')->orderBy('counterparty_fingerprint')->paginate(50),
        ]);
    }

    public function store(Request $request, FurimaDeckInvoiceTransitionCapService $caps, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'counterparty_name' => ['required', 'string', 'max:255'],
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'external_qualifying_purchase_amount' => ['required', 'integer', 'min:0', 'max:'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT],
            'reviewed_through' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from', 'before_or_equal:period_to'],
            'furimadeck_history_complete' => ['required', 'accepted'],
            'external_history_complete' => ['required', 'accepted'],
        ]);
        $validated['counterparty_name'] = trim($validated['counterparty_name']);
        if ($validated['counterparty_name'] === '') {
            throw ValidationException::withMessages(['counterparty_name' => '証憑に記載された相手先名を入力してください。']);
        }

        $fingerprint = $caps->counterpartyFingerprint($validated['counterparty_name']);
        $review = DB::connection('furimadeck')->transaction(function () use ($request, $validated, $fingerprint): AccountingPurchaseCapReview {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $overlapping = $user->accountingPurchaseCapReviews()
                ->where('counterparty_fingerprint', $fingerprint)
                ->whereDate('period_from', '<=', $validated['period_to'])
                ->whereDate('period_to', '>=', $validated['period_from'])
                ->exists();
            if ($overlapping) {
                throw ValidationException::withMessages(['counterparty_name' => '同じ相手先で重なる課税期間の確認記録がすでにあります。既存記録を更新してください。']);
            }

            return $user->accountingPurchaseCapReviews()->create([
                ...$validated,
                'counterparty_fingerprint' => $fingerprint,
                'reviewed_at' => now(),
            ]);
        });
        $auditLogger->log($request->user(), 'accounting_purchase_cap_review', (int) $review->id, 'created', null, [
            'external_qualifying_purchase_amount' => (int) $review->external_qualifying_purchase_amount,
            'furimadeck_history_complete' => (bool) $review->furimadeck_history_complete,
            'external_history_complete' => (bool) $review->external_history_complete,
        ]);

        return to_route('furimadeck-accounting.purchase-caps.index')->with('success', '仕入先別の累計確認を登録しました。商品・販売費用の証憑にも同じ確認記録を指定してください。');
    }

    public function update(Request $request, AccountingPurchaseCapReview $review, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless((int) $review->user_id === (int) $request->user()->id, 404);
        $validated = $request->validate([
            'external_qualifying_purchase_amount' => ['required', 'integer', 'min:0', 'max:'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT],
            'reviewed_through' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$review->period_from->toDateString(), 'before_or_equal:'.$review->period_to->toDateString()],
            'furimadeck_history_complete' => ['sometimes', 'boolean'],
            'external_history_complete' => ['sometimes', 'boolean'],
        ]);
        $validated['furimadeck_history_complete'] = $request->boolean('furimadeck_history_complete');
        $validated['external_history_complete'] = $request->boolean('external_history_complete');
        $before = [
            'external_qualifying_purchase_amount' => (int) $review->external_qualifying_purchase_amount,
            'furimadeck_history_complete' => (bool) $review->furimadeck_history_complete,
            'external_history_complete' => (bool) $review->external_history_complete,
        ];
        $review->update([...$validated, 'reviewed_at' => now()]);
        $auditLogger->log($request->user(), 'accounting_purchase_cap_review', (int) $review->id, 'updated', $before, [
            'external_qualifying_purchase_amount' => (int) $review->external_qualifying_purchase_amount,
            'furimadeck_history_complete' => (bool) $review->furimadeck_history_complete,
            'external_history_complete' => (bool) $review->external_history_complete,
        ]);

        return to_route('furimadeck-accounting.purchase-caps.index')->with('success', '累計金額と確認日を更新しました。');
    }
}
