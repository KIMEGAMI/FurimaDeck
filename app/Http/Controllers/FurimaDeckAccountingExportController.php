<?php

namespace App\Http\Controllers;

use App\Services\FurimaDeckAccountingEntryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FurimaDeckAccountingExportController extends Controller
{
    public function sync(Request $request, FurimaDeckAccountingEntryService $entries): RedirectResponse
    {
        $validated = $this->validatedPeriod($request);
        [$from, $toExclusive] = $this->period($validated);
        $entries->syncPeriod($request->user(), $from, $toExclusive);

        return to_route('furimadeck-accounting.index', $this->periodQuery($validated))->with('success', '共通会計データを更新しました。内容を確認してからCSVをダウンロードしてください。');
    }

    public function csv(Request $request): StreamedResponse
    {
        $validated = $this->validatedPeriod($request);
        [$from, $toExclusive] = $this->period($validated);
        $entries = $request->user()->accountingEntries()->eligibleForAccounting()
            ->where('transaction_date', '>=', $from->toDateString())
            ->where('transaction_date', '<', $toExclusive->toDateString())
            ->orderBy('transaction_date')->cursor();
        $headers = ['取引日', '管理ID', '商品名', '販売先', '売上', '原価', '販売手数料', '送料', 'その他費用', 'FurimaDeck実利益', '状態'];

        return response()->streamDownload(function () use ($entries, $headers): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);
            foreach ($entries as $entry) {
                fputcsv($output, $this->sanitize([$entry->transaction_date?->format('Y/m/d'), $entry->management_id, $entry->title, $entry->marketplace, $entry->sale_amount, $entry->purchase_cost, $entry->selling_fee, $entry->shipping_cost, $entry->other_cost, $entry->furimadeck_actual_profit, $entry->sync_status]));
            }
            fclose($output);
        }, 'furimadeck-accounting-'.(isset($validated['from'], $validated['to']) ? $from->toDateString().'_'.$toExclusive->subDay()->toDateString() : (string) $from->year).'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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

    /** @param array<int, mixed> $row @return array<int, string> */
    private function sanitize(array $row): array
    {
        return array_map(static function (mixed $value): string {
            $value = (string) $value;

            return preg_match('/^(?:[\t\r\n\x00]|[\s\x00]*[=+\-@＝＋－＠])/u', $value) === 1 ? "'".$value : $value;
        }, $row);
    }
}
