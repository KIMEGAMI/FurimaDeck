<?php

namespace App\Http\Controllers;

use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Models\Sale;
use App\Services\FurimaDeckAccountingAdjustmentService;
use App\Services\FurimaDeckAccountingEntryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class FurimaDeckAccountingExportController extends Controller
{
    public function sync(Request $request, FurimaDeckAccountingEntryService $entries): RedirectResponse
    {
        $validated = $this->validatedPeriod($request);
        [$from, $toExclusive] = $this->period($validated);
        try {
            $entries->syncPeriod($request->user(), $from, $toExclusive);
        } catch (RuntimeException $exception) {
            return to_route('furimadeck-accounting.index', $this->periodQuery($validated) + ['section' => 'csv'])->with('error', $exception->getMessage());
        }

        return to_route('furimadeck-accounting.index', $this->periodQuery($validated) + ['section' => 'csv'])->with('success', '共通会計データを更新しました。内容を確認してからCSVをダウンロードしてください。');
    }

    public function csv(Request $request, FurimaDeckAccountingEntryService $accountingEntries): StreamedResponse|RedirectResponse
    {
        $validated = $this->validatedPeriod($request);
        [$from, $toExclusive] = $this->period($validated);
        $entriesQuery = fn () => $request->user()->accountingEntries()->eligibleForAccounting()
            ->where('transaction_date', '>=', $from->toDateString())
            ->where('transaction_date', '<', $toExclusive->toDateString())
            ->with('product')
            ->orderBy('transaction_date')
            ->orderBy('id');
        $headers = ['種別', '取引日', '管理ID', '商品名', '販売先', '売上', '原価', '販売手数料', '送料', 'その他費用', 'FurimaDeck実利益', '状態'];
        $filename = 'furimadeck-accounting-'.(isset($validated['from'], $validated['to']) ? $from->toDateString().'_'.$toExclusive->subDay()->toDateString() : (string) $from->year).'.csv';
        $temporaryPath = tempnam(sys_get_temp_dir(), 'furimadeck-accounting-');
        if (! is_string($temporaryPath)) {
            return to_route('furimadeck-accounting.index', $this->periodQuery($validated) + ['section' => 'csv'])
                ->with('error', '会計CSVを作成できませんでした。時間をおいて再度お試しください。');
        }

        try {
            $output = fopen($temporaryPath, 'wb');
            if ($output === false) {
                throw new RuntimeException('会計CSVを作成できませんでした。時間をおいて再度お試しください。');
            }
            try {
                if (fwrite($output, "\xEF\xBB\xBF") === false || fputcsv($output, $headers) === false) {
                    throw new RuntimeException('会計CSVを作成できませんでした。時間をおいて再度お試しください。');
                }
                foreach ($entriesQuery()->lazy((int) config('furimadeck.accounting.query_chunk_size')) as $entry) {
                    $accountingEntries->assertCurrent($entry);
                    if (fputcsv($output, $this->sanitize([$entry->entry_type === AccountingEntry::TYPE_PURCHASE ? '仕入' : '売上', $entry->transaction_date?->format('Y/m/d'), $entry->management_id, $entry->title, $entry->marketplace, $entry->sale_amount, $entry->purchase_cost, $entry->selling_fee, $entry->shipping_cost, $entry->other_cost, $entry->furimadeck_actual_profit, $entry->sync_status])) === false) {
                        throw new RuntimeException('会計CSVを作成できませんでした。時間をおいて再度お試しください。');
                    }
                }
            } finally {
                fclose($output);
            }
        } catch (QueryException $exception) {
            $this->cleanupTemporaryCsv($temporaryPath);
            throw $exception;
        } catch (RuntimeException $exception) {
            $this->cleanupTemporaryCsv($temporaryPath);

            return to_route('furimadeck-accounting.index', $this->periodQuery($validated) + ['section' => 'csv'])->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            $this->cleanupTemporaryCsv($temporaryPath);
            throw $exception;
        }

        return response()->streamDownload(function () use ($temporaryPath): void {
            $input = fopen($temporaryPath, 'rb');
            if ($input === false) {
                $this->cleanupTemporaryCsv($temporaryPath);

                return;
            }
            try {
                fpassthru($input);
            } finally {
                fclose($input);
                $this->cleanupTemporaryCsv($temporaryPath);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function adjustmentsCsv(Request $request, FurimaDeckAccountingAdjustmentService $adjustments): StreamedResponse
    {
        $validated = $this->validatedPeriod($request);
        [$from, $toExclusive] = $this->period($validated);
        $sales = $adjustments->lazyForPeriod($request->user(), $from, $toExclusive);
        $headers = ['取引ID', '取消・返品日', '状態', '元の販売日', '管理ID', '商品名', '販売先', '元の売価', '原価', '販売手数料', '販売送料', '仕入送料', '梱包費', '修理費', '清掃費', 'その他費用', '返金額', '返送送料', '再入庫数', 'freee状態', 'freee仕訳ID', 'Money Forward状態', 'Money Forward仕訳ID', '注意'];

        return response()->streamDownload(function () use ($sales, $headers): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);
            foreach ($sales as $sale) {
                $eventAt = $sale->status === 'cancelled' ? $sale->cancelled_at : $sale->returned_at;
                fputcsv($output, $this->sanitize([
                    $sale->id,
                    $eventAt?->format('Y/m/d'),
                    Sale::statusLabel($sale->status),
                    $sale->sold_at?->format('Y/m/d'),
                    $sale->internal_sku_snapshot,
                    $sale->product_name_snapshot,
                    $sale->marketplace?->name,
                    $sale->sold_price,
                    $sale->cost_basis,
                    $sale->sales_fee,
                    $sale->shipping_fee,
                    $sale->purchase_shipping_cost,
                    $sale->packing_cost,
                    $sale->repair_cost,
                    $sale->cleaning_cost,
                    $sale->other_expense,
                    $sale->refund_amount,
                    $sale->return_shipping_fee,
                    $sale->restocked_quantity,
                    $this->providerStatusLabel($sale->accountingEntry?->providerSync('freee')),
                    $sale->accountingEntry?->providerSync('freee')?->external_transaction_id,
                    $this->providerStatusLabel($sale->accountingEntry?->providerSync('money_forward')),
                    $sale->accountingEntry?->providerSync('money_forward')?->external_transaction_id,
                    '会計ソフトに自動送信しません。元の販売と返金・費用を照合してください。',
                ]));
            }
            fclose($output);
        }, 'furimadeck-accounting-adjustments-'.$from->toDateString().'_'.$toExclusive->subDay()->toDateString().'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedPeriod(Request $request): array
    {
        return $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
    }

    /** @param array<string, mixed> $validated @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(array $validated): array
    {
        if (isset($validated['from']) xor isset($validated['to'])) {
            abort(422, '開始日と終了日は両方指定してください。');
        }
        if (isset($validated['from'], $validated['to'])) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'], config('app.timezone'));
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'], config('app.timezone'));
            if ($from === false || $to === false || $from->greaterThan($to)) {
                abort(422, '期間の指定が正しくありません。');
            }

            return [$from->startOfDay(), $to->addDay()->startOfDay()];
        }
        $year = (int) ($validated['year'] ?? now()->year);
        $from = CarbonImmutable::create($year, 1, 1, 0, 0, 0, config('app.timezone'));

        return [$from, $from->addYear()];
    }

    /** @param array<string, mixed> $validated @return array<string, string|int> */
    private function periodQuery(array $validated): array
    {
        return isset($validated['from'], $validated['to'])
            ? ['from' => $validated['from'], 'to' => $validated['to']]
            : ['year' => (int) ($validated['year'] ?? now()->year)];
    }

    /** @param array<int, mixed> $row @return array<int, string|int> */
    private function sanitize(array $row): array
    {
        return array_map(static function (mixed $value): string|int {
            if (is_int($value)) {
                return $value;
            }

            $value = (string) ($value ?? '');

            return preg_match('/^(?:[\t\r\n\x00]|[\s\x00]*[=+\-@＝＋－＠])/u', $value) === 1 ? "'".$value : $value;
        }, $row);
    }

    private function providerStatusLabel(?AccountingEntrySync $sync): string
    {
        if ($sync === null) {
            return '会計明細なし';
        }

        return match ($sync->status) {
            'synced' => $sync->verified_at === null ? 'API登録済み・内容未照合' : 'API登録・内容照合済み',
            AccountingEntrySync::STATUS_CSV_IMPORTED => 'CSV取込確認済み',
            AccountingEntrySync::STATUS_MANUALLY_RECONCILED => '利用者による手動照合済み・API自動照合ではない',
            'csv_pending' => 'CSV取込確認待ち',
            'failed' => '送信エラー・外部確認要',
            'sync_unknown', 'legacy_unknown', 'sending', 'queued' => '外部状態要照合',
            'not_synced' => $sync->last_error === AccountingEntrySync::VERIFIED_ABSENT_INACTIVE_SALE
                ? '外部未登録確認済み・再送なし'
                : '未送信',
            default => '状態不明',
        };
    }

    private function cleanupTemporaryCsv(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
