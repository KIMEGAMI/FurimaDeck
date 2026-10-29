<?php

namespace App\Services;

use App\Models\AccountingEntry;
use App\Models\AccountingPurchaseCapReview;
use App\Models\ManualProductPurchaseReconciliation;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

class FurimaDeckInvoiceTransitionCapService
{
    /** @var array<string, array<string, int>> */
    private array $dailyCumulativeByReview = [];

    public function counterpartyFingerprint(string $counterpartyName): string
    {
        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($counterpartyName)) ?? trim($counterpartyName));
        $key = app('encrypter')->getKey();

        return hash_hmac('sha256', $normalized, $key);
    }

    /** @return Collection<int, AccountingPurchaseCapReview> */
    public function reviewsForUser(User $user): Collection
    {
        return $user->accountingPurchaseCapReviews()
            ->orderBy('period_from')
            ->orderBy('counterparty_fingerprint')
            ->get();
    }

    public function assertEntryWithinCap(AccountingEntry $entry): void
    {
        if ($entry->entry_type === AccountingEntry::TYPE_PURCHASE) {
            $product = $entry->loadMissing('product')->product;
            if ($product === null || (int) $product->user_id !== (int) $entry->user_id) {
                throw new RuntimeException('仕入先別の年間累計を確認できません。会計データは保留しました。');
            }

            $evidence = (array) ($product->purchase_evidence ?? []);
            $amounts = [
                'purchase_unit_cost' => (int) $product->purchase_unit_cost * (int) $product->purchase_quantity,
                'purchase_shipping_cost' => (int) $product->purchase_shipping_cost,
                'other_purchase_expense' => (int) $product->other_purchase_expense,
            ];
            $specialCase = (array) ($product->purchase_special_case_data ?? []);
            $isAntiqueException = ($specialCase['scheme'] ?? null) === 'antique_dealer';
            if ($isAntiqueException) {
                app(FurimaDeckPurchaseSpecialCaseService::class)->assertConfirmations(
                    $specialCase,
                    $product->purchase_date?->toDateString(),
                );
            }

            foreach ($amounts as $field => $amount) {
                if ($amount <= 0) {
                    continue;
                }
                $lineEvidence = (array) ($evidence[$field] ?? []);
                $date = $product->purchase_date?->toDateString() ?? '';
                $this->assertLine(
                    (int) $entry->user_id,
                    $date,
                    $lineEvidence,
                    $isAntiqueException && $field === 'purchase_unit_cost',
                    '仕入'.match ($field) {
                        'purchase_unit_cost' => '商品代',
                        'purchase_shipping_cost' => '送料',
                        default => 'その他費用',
                    },
                );
            }

            return;
        }

        if ($entry->entry_type !== AccountingEntry::TYPE_SALE) {
            return;
        }

        $sale = $entry->loadMissing('sale')->sale;
        if ($sale === null || (int) $sale->user_id !== (int) $entry->user_id) {
            throw new RuntimeException('仕入先別の年間累計を確認できません。会計データは保留しました。');
        }

        $details = (array) ($sale->accounting_expense_details ?? []);
        foreach (FurimaDeckSaleExpenseAccountingService::EXPENSES as $field => $label) {
            $amount = (int) ($sale->{$field} ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $lineEvidence = (array) ($details[$field] ?? []);
            $date = $sale->sold_at?->toDateString() ?? '';
            $this->assertLine(
                (int) $entry->user_id,
                $date,
                $lineEvidence,
                false,
                $label,
            );
        }
    }

    /** @param array<string, mixed> $expenseEvidence */
    public function assertManualPurchaseEvidenceWithinCap(int $userId, Product $product, string $date, array $expenseEvidence): void
    {
        $amounts = [
            'purchase_unit_cost' => (int) $product->purchase_unit_cost * (int) $product->purchase_quantity,
            'purchase_shipping_cost' => (int) $product->purchase_shipping_cost,
            'other_purchase_expense' => (int) $product->other_purchase_expense,
        ];
        $specialCase = (array) ($product->purchase_special_case_data ?? []);
        $isAntiqueException = ($specialCase['scheme'] ?? null) === 'antique_dealer';
        if ($isAntiqueException) {
            app(FurimaDeckPurchaseSpecialCaseService::class)->assertConfirmations($specialCase, $product->purchase_date?->toDateString());
            foreach (['license_number', 'counterparty_name', 'counterparty_address'] as $field) {
                if (trim((string) ($specialCase[$field] ?? '')) === '') {
                    throw new RuntimeException('古物商等特例の許可番号・相手先情報が不足しているため、手動照合を保留しました。');
                }
            }
            if (trim((string) ($specialCase['confirmed_at'] ?? '')) === '') {
                throw new RuntimeException('古物商等特例の確認日時が不足しているため、手動照合を保留しました。');
            }
        }

        $additionalAmountsByReview = [];
        foreach ($amounts as $field => $amount) {
            if ($amount <= 0) {
                continue;
            }
            $lineEvidence = (array) ($expenseEvidence[$field] ?? []);
            $lineEvidence['date_matches_entry_confirmed'] = in_array($lineEvidence['date_matches_entry_confirmed'] ?? false, [true, 1, '1', 'on'], true);
            $lineEvidence['reviewed_at'] = is_string($lineEvidence['reviewed_at'] ?? null) && trim($lineEvidence['reviewed_at']) !== ''
                ? $lineEvidence['reviewed_at']
                : now()->toIso8601String();

            $additionalAmount = $amount;
            $this->assertLine(
                $userId,
                $date,
                $lineEvidence,
                $isAntiqueException && $field === 'purchase_unit_cost',
                '手動照合の'.match ($field) {
                    'purchase_unit_cost' => '商品代',
                    'purchase_shipping_cost' => '仕入送料',
                    default => 'その他仕入費',
                },
                $additionalAmount,
                (int) $product->id,
            );

            if ($additionalAmount === 0 || $isAntiqueException
                || ($lineEvidence['invoice_issuer'] ?? null) !== 'unqualified'
                || ! in_array($lineEvidence['tax_category'] ?? null, ['standard_10', 'reduced_8'], true)
                || $date < (string) config('furimadeck.accounting.invoice_transition.invoice_system_start', '')) {
                continue;
            }

            $review = $this->reviewForEvidence($userId, $date, $lineEvidence, '手動照合の証憑');
            if ($this->capForPeriod($review->period_from->toDateString()) === null) {
                continue;
            }

            $previousAmount = (int) ($additionalAmountsByReview[$review->id] ?? 0);
            if ($additionalAmount > SaleProfitCalculator::MAX_UNSIGNED_AMOUNT - $previousAmount) {
                throw new RuntimeException('同じ仕入先の複数費目合計を安全に計算できません。会計データは保留しました。');
            }
            $additionalAmountsByReview[$review->id] = $previousAmount + $additionalAmount;

            $this->assertLine(
                $userId,
                $date,
                $lineEvidence,
                false,
                '手動照合の複数費目合計',
                $additionalAmountsByReview[$review->id],
                (int) $product->id,
            );
        }
    }

    /**
     * Produces the supplier and transition-relief notation that must remain visible in the journal.
     *
     * @param  array<string, mixed>  $evidence
     */
    public function accountingMemoForEvidence(int $userId, string $date, array $evidence, bool $antiqueDealerException = false): ?string
    {
        if (! $antiqueDealerException) {
            $this->assertIssuerClassification($evidence, '証憑');
        }

        if ($antiqueDealerException
            || ($evidence['invoice_issuer'] ?? null) !== 'unqualified'
            || ! in_array($evidence['tax_category'] ?? null, ['standard_10', 'reduced_8'], true)
            || $date < (string) config('furimadeck.accounting.invoice_transition.invoice_system_start', '')) {
            return null;
        }

        $this->assertLine($userId, $date, $evidence, false, '証憑');
        $review = $this->reviewForEvidence($userId, $date, $evidence);
        $notation = $this->transitionNotation($evidence, $date);

        return '仕入先:'.$review->counterparty_name.($notation === null ? '' : ' / '.$notation);
    }

    public function appendAccountingMemo(string $description, ?string $memo): string
    {
        $combined = $memo === null ? $description : $description.' / '.$memo;
        if (mb_strlen($combined, 'UTF-8') > 200) {
            throw new RuntimeException('仕入先名・控除区分を含む仕訳摘要が200文字を超えます。摘要を確認するまで送信・CSV出力を止めました。');
        }

        return $combined;
    }

    private function assertLine(
        int $userId,
        string $date,
        array $evidence,
        bool $antiqueException,
        string $label,
        int $additionalAmount = 0,
        ?int $excludeProductId = null,
    ): void {
        if (! $antiqueException) {
            $this->assertIssuerClassification($evidence, $label);
        }

        if ($antiqueException
            || ($evidence['invoice_issuer'] ?? null) !== 'unqualified'
            || ! in_array($evidence['tax_category'] ?? null, ['standard_10', 'reduced_8'], true)) {
            return;
        }

        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            throw new RuntimeException($label.'の証憑日が確認できません。相手先・日付を確認するまで会計データを保留しました。');
        }
        if ($date < (string) config('furimadeck.accounting.invoice_transition.invoice_system_start', '')) {
            return;
        }
        if (($evidence['date_matches_entry_confirmed'] ?? false) !== true) {
            throw new RuntimeException($label.'の証憑日と仕訳日が一致するか未確認です。日付を確認するまで会計データを保留しました。');
        }

        $review = $this->reviewForEvidence($userId, $date, $evidence, $label);
        $cap = $this->capForPeriod($review->period_from->toDateString());
        if ($cap === null) {
            return;
        }

        $capturedAmount = $this->capturedAmount($review, $date, $cap, $excludeProductId);
        $outsideAmount = (int) $review->external_qualifying_purchase_amount;
        if ($outsideAmount > $cap || $capturedAmount > $cap - $outsideAmount
            || $additionalAmount > $cap - $outsideAmount - $capturedAmount) {
            throw new RuntimeException($label.'を含む「'.$review->counterparty_name.'」の課税期間内累計が上限を超えるか確認できません。超過部分の自動分割はせず、この仕訳の送信・CSV出力を止めました。税区分を確認してください。');
        }
    }

    private function reviewForEvidence(int $userId, string $date, array $evidence, string $label = '証憑'): AccountingPurchaseCapReview
    {
        $reviewId = filter_var($evidence['cap_review_id'] ?? null, FILTER_VALIDATE_INT);
        $review = $reviewId === false || $reviewId === null
            ? null
            : AccountingPurchaseCapReview::query()->whereKey($reviewId)->where('user_id', $userId)->first();
        if ($review === null
            || $review->period_from === null
            || $review->period_to === null
            || $date < $review->period_from->toDateString()
            || $date > $review->period_to->toDateString()
            || $review->reviewed_through === null
            || $review->reviewed_through->toDateString() < $date
            || ! $review->furimadeck_history_complete
            || ! $review->external_history_complete
            || trim((string) $review->counterparty_name) === '') {
            throw new RuntimeException($label.'の相手先・課税期間・年度累計が未確認です。会計データの送信・CSV出力を止めました。「会計データ > 仕入先別上限確認」で対象先と累計を確認してください。');
        }

        return $review;
    }

    private function capForPeriod(string $periodFrom): ?int
    {
        $rules = (array) config('furimadeck.accounting.invoice_transition', []);

        return match (true) {
            $periodFrom >= (string) ($rules['reduced_counterparty_cap_from'] ?? '') => (int) ($rules['counterparty_annual_cap_jpy'] ?? 0),
            $periodFrom >= (string) ($rules['cap_review_required_from'] ?? '') => (int) ($rules['earlier_counterparty_annual_cap_jpy'] ?? 0),
            default => null,
        };
    }

    private function transitionNotation(array $evidence, string $date): ?string
    {
        $kind = app(FurimaDeckMoneyForwardInvoiceKindService::class)->forPurchaseEvidence(
            (string) ($evidence['tax_category'] ?? ''),
            (string) ($evidence['invoice_issuer'] ?? ''),
            $date,
        );

        return match ($kind) {
            FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_80 => '80%控除対象',
            FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_70 => '70%控除対象',
            FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_50 => '50%控除対象',
            FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED_30 => '30%控除対象',
            FurimaDeckMoneyForwardInvoiceKindService::UNQUALIFIED => '控除なし',
            default => null,
        };
    }

    private function capturedAmount(AccountingPurchaseCapReview $review, string $throughDate, int $cap, ?int $excludeProductId = null): int
    {
        $cacheKey = $review->id.':'.($excludeProductId === null ? 'all' : (string) $excludeProductId);
        $cumulative = $this->dailyCumulativeByReview[$cacheKey] ?? null;
        if ($cumulative === null) {
            $dailyAmounts = [];
            $recordAmount = function (string $date, int $amount) use (&$dailyAmounts, $cap): void {
                $current = (int) ($dailyAmounts[$date] ?? 0);
                $dailyAmounts[$date] = min($cap + 1, $current + $amount);
            };

            $products = Product::query()
                ->where('user_id', $review->user_id)
                ->when($excludeProductId !== null, fn ($query) => $query->where('id', '<>', $excludeProductId))
                ->whereDate('purchase_date', '>=', $review->period_from->toDateString())
                ->whereDate('purchase_date', '<=', $review->period_to->toDateString())
                ->get(['id', 'purchase_date', 'purchase_unit_cost', 'purchase_quantity', 'purchase_shipping_cost', 'other_purchase_expense', 'purchase_evidence', 'purchase_special_case_data']);

            foreach ($products as $product) {
                $evidence = (array) ($product->purchase_evidence ?? []);
                $date = $product->purchase_date?->toDateString();
                if ($date === null) {
                    continue;
                }
                $amounts = [
                    'purchase_unit_cost' => (int) $product->purchase_unit_cost * (int) $product->purchase_quantity,
                    'purchase_shipping_cost' => (int) $product->purchase_shipping_cost,
                    'other_purchase_expense' => (int) $product->other_purchase_expense,
                ];
                $specialCase = (array) ($product->purchase_special_case_data ?? []);
                foreach ($amounts as $field => $amount) {
                    if ($field === 'purchase_unit_cost' && ($specialCase['scheme'] ?? null) === 'antique_dealer') {
                        continue;
                    }

                    $lineEvidence = (array) ($evidence[$field] ?? []);
                    if ($amount > 0 && $this->belongsToReview($lineEvidence, $review)) {
                        if (($lineEvidence['date_matches_entry_confirmed'] ?? false) !== true) {
                            throw new RuntimeException('FurimaDeck内の同一仕入先取引に仕訳日との一致が未確認の証憑があります。累計を確認するまで送信を止めました。');
                        }
                        $this->assertDateWithinPeriod($date, $review);
                        $recordAmount($date, $amount);
                    }
                }
            }

            $manualEvidenceByProductField = [];
            $manualReconciliations = ManualProductPurchaseReconciliation::query()
                ->where('user_id', $review->user_id)
                ->with('product')
                ->get();

            foreach ($manualReconciliations as $reconciliation) {
                $productId = (int) $reconciliation->product_id;

                if ($productId === $excludeProductId) {
                    continue;
                }

                $manualEvidence = $reconciliation->latestEvidence();
                $expenseEvidence = (array) ($manualEvidence['expense_evidence'] ?? []);
                $matchingFields = [];

                foreach (FurimaDeckAccountingPurchaseReconciliationService::EXPENSES as $field => $_label) {
                    $lineEvidence = (array) ($expenseEvidence[$field] ?? []);

                    if ($this->belongsToReview($lineEvidence, $review)) {
                        $matchingFields[$field] = $lineEvidence;
                    }
                }

                if ($matchingFields === []) {
                    continue;
                }

                $product = $reconciliation->product;
                $storedSnapshot = (string) ($manualEvidence['product_snapshot_hash'] ?? '');
                $currentSnapshot = $product === null
                    ? ''
                    : app(FurimaDeckAccountingPurchaseReconciliationService::class)->snapshotHash($product);

                if ($product === null
                    || (int) $product->user_id !== (int) $review->user_id
                    || $product->purchase_date === null
                    || ! hash_equals((string) $reconciliation->product_snapshot_hash, $currentSnapshot)
                    || ! hash_equals($storedSnapshot, $currentSnapshot)) {
                    throw new RuntimeException('仕入先別累計に含まれる手動照合の商品情報が変更されています。元の仕入額を確認するまで送信を止めました。');
                }

                $date = (string) ($manualEvidence['transaction_date'] ?? '');

                if ($date !== $product->purchase_date->toDateString()
                    || (int) ($manualEvidence['purchase_amount'] ?? -1) !== (int) $product->purchase_unit_cost * (int) $product->purchase_quantity
                        + (int) $product->purchase_shipping_cost
                        + (int) $product->other_purchase_expense) {
                    throw new RuntimeException('手動照合の仕入額・取引日が現在の商品記録と一致しません。累計を確認するまで送信を止めました。');
                }
                $this->assertDateWithinPeriod($date, $review);

                $amounts = [
                    'purchase_unit_cost' => (int) $product->purchase_unit_cost * (int) $product->purchase_quantity,
                    'purchase_shipping_cost' => (int) $product->purchase_shipping_cost,
                    'other_purchase_expense' => (int) $product->other_purchase_expense,
                ];
                $specialCase = (array) ($product->purchase_special_case_data ?? []);
                $productEvidence = (array) ($product->purchase_evidence ?? []);

                foreach ($matchingFields as $field => $lineEvidence) {
                    if ($field === 'purchase_unit_cost' && ($specialCase['scheme'] ?? null) === 'antique_dealer') {
                        continue;
                    }

                    $amount = (int) ($amounts[$field] ?? 0);

                    if ($amount <= 0) {
                        throw new RuntimeException('手動照合の仕入証跡に対応する金額がありません。累計を確認するまで送信を止めました。');
                    }

                    $productLineEvidence = (array) ($productEvidence[$field] ?? []);
                    $productTaxCategory = $productLineEvidence['tax_category'] ?? null;
                    $productIssuer = $productLineEvidence['invoice_issuer'] ?? null;

                    if (($productTaxCategory !== null && $productTaxCategory !== $lineEvidence['tax_category'])
                        || ($productIssuer !== null && $productIssuer !== $lineEvidence['invoice_issuer'])
                        || (isset($productLineEvidence['cap_review_id'])
                            && (string) $productLineEvidence['cap_review_id'] !== (string) $review->id)) {
                        throw new RuntimeException('商品情報と手動照合の仕入証跡で仕入先・税区分が一致しません。累計を確認するまで送信を止めました。');
                    }

                    if ($this->belongsToReview($productLineEvidence, $review)) {
                        continue;
                    }

                    $key = $productId.':'.$field;
                    $classification = implode(':', [
                        $date,
                        (string) ($lineEvidence['tax_category'] ?? ''),
                        (string) ($lineEvidence['invoice_issuer'] ?? ''),
                        (string) ($lineEvidence['cap_review_id'] ?? ''),
                    ]);

                    if (isset($manualEvidenceByProductField[$key])) {
                        if ($manualEvidenceByProductField[$key] !== $classification) {
                            throw new RuntimeException('同一商品の複数会計サービス間で仕入証跡が一致しません。累計を確認するまで送信を止めました。');
                        }

                        continue;
                    }

                    $manualEvidenceByProductField[$key] = $classification;
                    $recordAmount($date, $amount);
                }
            }

            $sales = Sale::query()
                ->where('user_id', $review->user_id)
                ->whereDate('sold_at', '>=', $review->period_from->toDateString())
                ->whereDate('sold_at', '<=', $review->period_to->toDateString())
                ->get(['sold_at', 'packing_cost', 'repair_cost', 'cleaning_cost', 'other_expense', 'accounting_expense_details']);

            foreach ($sales as $sale) {
                $details = (array) ($sale->accounting_expense_details ?? []);
                $date = $sale->sold_at?->toDateString();
                if ($date === null) {
                    continue;
                }
                foreach (FurimaDeckSaleExpenseAccountingService::EXPENSES as $field => $_label) {
                    $amount = (int) ($sale->{$field} ?? 0);
                    $lineEvidence = (array) ($details[$field] ?? []);
                    if ($amount > 0 && $this->belongsToReview($lineEvidence, $review)) {
                        if (($lineEvidence['date_matches_entry_confirmed'] ?? false) !== true) {
                            throw new RuntimeException('FurimaDeck内の同一仕入先費用に仕訳日との一致が未確認の証憑があります。累計を確認するまで送信を止めました。');
                        }
                        $this->assertDateWithinPeriod($date, $review);
                        $recordAmount($date, $amount);
                    }
                }
            }

            ksort($dailyAmounts);
            $running = 0;
            foreach ($dailyAmounts as $date => $amount) {
                $running = min($cap + 1, $running + $amount);
                $dailyAmounts[$date] = $running;
            }
            $this->dailyCumulativeByReview[$cacheKey] = $dailyAmounts;
            $cumulative = $dailyAmounts;
        }

        $amount = 0;
        foreach ($cumulative as $date => $dailyTotal) {
            if ($date > $throughDate) {
                break;
            }
            $amount = $dailyTotal;
        }

        return $amount;
    }

    private function assertDateWithinPeriod(string $date, AccountingPurchaseCapReview $review): void
    {
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date
            || $date < $review->period_from->toDateString()
            || $date > $review->period_to->toDateString()) {
            throw new RuntimeException('仕入先別累計に含める費用の日付が課税期間と一致しません。会計データは保留しました。');
        }
    }

    /** @param array<string, mixed> $evidence */
    private function assertIssuerClassification(array $evidence, string $label): void
    {
        if (in_array($evidence['tax_category'] ?? null, ['standard_10', 'reduced_8'], true)
            && ! in_array($evidence['invoice_issuer'] ?? null, ['qualified', 'unqualified'], true)) {
            throw new RuntimeException($label.'は課税仕入れですが、発行者区分が「対象外」または未設定です。証憑を確認するまで会計データの送信・CSV出力を止めました。');
        }
    }

    private function belongsToReview(array $evidence, AccountingPurchaseCapReview $review): bool
    {
        return (string) ($evidence['cap_review_id'] ?? '') === (string) $review->id
            && ($evidence['invoice_issuer'] ?? null) === 'unqualified'
            && in_array($evidence['tax_category'] ?? null, ['standard_10', 'reduced_8'], true);
    }
}
