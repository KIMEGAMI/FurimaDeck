<?php

namespace App\Http\Controllers;

use App\Services\FurimaDeckFreeeAccountingSyncService;
use App\Services\FurimaDeckTaxReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FurimaDeckAccountingController extends Controller
{
    public function index(Request $request, FurimaDeckTaxReadinessService $readiness, FurimaDeckFreeeAccountingSyncService $freeeSync): View
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        [$from, $toExclusive] = $this->period($validated);
        $label = isset($validated['from'], $validated['to']) ? $validated['from'].' ～ '.$validated['to'] : $from->year.'年分';
        $accountingEntries = $request->user()->accountingEntries()->eligibleForAccounting()
            ->where('transaction_date', '>=', $from->toDateString())
            ->where('transaction_date', '<', $toExclusive->toDateString())
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $freeeConnection = $request->user()->accountingConnections()->where('provider', 'freee')->first();

        return view('furimadeck_accounting.index', [
            'year' => $from->year,
            'fromInput' => $from->toDateString(),
            'toInput' => $toExclusive->subDay()->toDateString(),
            'report' => $readiness->reportPeriod($request->user(), $from, $toExclusive, $label),
            'accountingEntries' => $accountingEntries,
            'accountingConnections' => $request->user()->accountingConnections()->get()->keyBy('provider'),
            'freeeSyncMissingConfiguration' => $freeeSync->missingConfiguration($freeeConnection),
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
}
