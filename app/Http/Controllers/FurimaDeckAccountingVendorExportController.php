<?php

namespace App\Http\Controllers;

use App\Services\FurimaDeckAccountingVendorCsvService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FurimaDeckAccountingVendorExportController extends Controller
{
    public function freee(Request $request, FurimaDeckAccountingVendorCsvService $csv): StreamedResponse|RedirectResponse
    {
        return $this->download($request, $csv, 'freee');
    }

    public function moneyForward(Request $request, FurimaDeckAccountingVendorCsvService $csv): StreamedResponse|RedirectResponse
    {
        return $this->download($request, $csv, 'money-forward');
    }

    private function download(Request $request, FurimaDeckAccountingVendorCsvService $csv, string $format): StreamedResponse|RedirectResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (isset($validated['from']) xor isset($validated['to'])) {
            abort(422, '開始日と終了日は両方指定してください。');
        }

        if (isset($validated['from'], $validated['to'])) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $validated['from'], config('app.timezone'));
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $validated['to'], config('app.timezone'));
            if ($from === false || $to === false || $from->greaterThan($to)) {
                abort(422, '期間の指定が正しくありません。');
            }
            $toExclusive = $to->addDay()->startOfDay();
        } else {
            $from = CarbonImmutable::create((int) ($validated['year'] ?? now()->year), 1, 1, 0, 0, 0, config('app.timezone'));
            $toExclusive = $from->addYear();
        }

        $entries = $request->user()->accountingEntries()->eligibleForAccounting()
            ->where('transaction_date', '>=', $from->toDateString())
            ->where('transaction_date', '<', $toExclusive->toDateString())
            ->orderBy('transaction_date')
            ->get();
        try {
            $rows = $csv->journalRows($entries);
        } catch (\RuntimeException $exception) {
            return redirect()->route('furimadeck-accounting.index', $request->query())->with('error', $exception->getMessage());
        }
        $headers = $format === 'freee' ? $csv->freeeHeaders() : $csv->moneyForwardHeaders();
        $filename = 'furimadeck-'.($format === 'freee' ? 'freee' : 'money-forward').'-accounting.csv';

        return response()->streamDownload(function () use ($csv, $format, $headers, $rows): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);
            foreach ($rows as $row) {
                $values = $format === 'freee' ? $csv->freeeRow($row) : $csv->moneyForwardRow($row);
                fputcsv($output, $this->sanitize($values));
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param array<int, mixed> $row @return array<int, string> */
    private function sanitize(array $row): array
    {
        return array_map(static function (mixed $value): string {
            $value = (string) $value;

            return preg_match('/^[\t\r\n\s]*[=+\-@＝＋－＠]/u', $value) === 1 ? "'".$value : $value;
        }, $row);
    }
}
