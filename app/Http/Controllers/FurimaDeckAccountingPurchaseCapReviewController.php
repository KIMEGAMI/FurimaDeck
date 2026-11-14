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
        if (! $caps->isCompleteAnnualPeriod($validated['period_from'], $validated['period_to'])) {
            throw ValidationException::withMessages(['period_from' => '仕入先別上限確認は、開始日から1年後の前日までの年または事業年度全体で登録してください。']);
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

    public function update(Request $request, AccountingPurchaseCapReview $review, FurimaDeckInvoiceTransitionCapService $caps, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless((int) $review->user_id === (int) $request->user()->id, 404);
        $validated = $request->validate([
            'period_from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'period_to' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'external_qualifying_purchase_amount' => ['required', 'integer', 'min:0', 'max:'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT],
            'reviewed_through' => ['required', 'date_format:Y-m-d'],
            'furimadeck_history_complete' => ['sometimes', 'boolean'],
            'external_history_complete' => ['sometimes', 'boolean'],
        ]);
        $periodFrom = $validated['period_from'] ?? $review->period_from->toDateString();
        $periodTo = $validated['period_to'] ?? $review->period_to->toDateString();
        if (! $caps->isCompleteAnnualPeriod($periodFrom, $periodTo)) {
            throw ValidationException::withMessages(['period_from' => '仕入先別上限確認は、開始日から1年後の前日までの年または事業年度全体で登録してください。']);
        }
        if ($validated['reviewed_through'] < $periodFrom || $validated['reviewed_through'] > $periodTo) {
            throw ValidationException::withMessages(['reviewed_through' => '累計確認日は、登録する年度の開始日から終了日の範囲で指定してください。']);
        }
        $validated['period_from'] = $periodFrom;
        $validated['period_to'] = $periodTo;
        $validated['furimadeck_history_complete'] = $request->boolean('furimadeck_history_complete');
        $validated['external_history_complete'] = $request->boolean('external_history_complete');
        $before = [
            'period_from' => $review->period_from->toDateString(),
            'period_to' => $review->period_to->toDateString(),
            'external_qualifying_purchase_amount' => (int) $review->external_qualifying_purchase_amount,
            'furimadeck_history_complete' => (bool) $review->furimadeck_history_complete,
            'external_history_complete' => (bool) $review->external_history_complete,
        ];
        $updatedReview = DB::connection('furimadeck')->transaction(function () use ($request, $review, $validated): AccountingPurchaseCapReview {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $overlapping = $user->accountingPurchaseCapReviews()
                ->where('counterparty_fingerprint', $review->counterparty_fingerprint)
                ->where('id', '<>', $review->id)
                ->whereDate('period_from', '<=', $validated['period_to'])
                ->whereDate('period_to', '>=', $validated['period_from'])
                ->exists();
            if ($overlapping) {
                throw ValidationException::withMessages(['period_from' => '同じ相手先で重なる年度の確認記録がすでにあります。']);
            }

            $review->update([...$validated, 'reviewed_at' => now()]);

            return $review->refresh();
        });
        $auditLogger->log($request->user(), 'accounting_purchase_cap_review', (int) $updatedReview->id, 'updated', $before, [
            'period_from' => $updatedReview->period_from->toDateString(),
            'period_to' => $updatedReview->period_to->toDateString(),
            'external_qualifying_purchase_amount' => (int) $updatedReview->external_qualifying_purchase_amount,
            'furimadeck_history_complete' => (bool) $updatedReview->furimadeck_history_complete,
            'external_history_complete' => (bool) $updatedReview->external_history_complete,
        ]);

        return to_route('furimadeck-accounting.purchase-caps.index')->with('success', '年度・累計金額・確認日を更新しました。');
    }
}
