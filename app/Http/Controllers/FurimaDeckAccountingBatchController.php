<?php

namespace App\Http\Controllers;

use App\Services\FurimaDeckAccountingBatchService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class FurimaDeckAccountingBatchController extends Controller
{
    public function sendFreee(Request $request, FurimaDeckAccountingBatchService $batches): RedirectResponse
    {
        return $this->send($request, $batches, 'freee');
    }

    public function sendMoneyForward(Request $request, FurimaDeckAccountingBatchService $batches): RedirectResponse
    {
        return $this->send($request, $batches, 'money_forward');
    }

    private function send(Request $request, FurimaDeckAccountingBatchService $batches, string $provider): RedirectResponse
    {
        [$from, $toExclusive, $periodQuery] = $this->period($request);
        $providerName = $provider === 'freee' ? 'freee' : 'Money Forward';
        try {
            $batch = $provider === 'freee'
                ? $batches->startFreee($request->user(), $from, $toExclusive)
                : $batches->startMoneyForward($request->user(), $from, $toExclusive);
        } catch (RuntimeException $exception) {
            return to_route('furimadeck-accounting.index', $periodQuery + ['section' => 'send'])->with('error', $exception->getMessage());
        }

        return to_route('furimadeck-accounting.index', $periodQuery + ['section' => 'send'])
            ->with('success', $batch->total_count.'件を'.$providerName.'への一括送信キューに登録しました。処理状況をこの画面で確認できます。');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: array<string, string|int>} */
    private function period(Request $request): array
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

            return [$from, $to->addDay(), ['from' => $validated['from'], 'to' => $validated['to']]];
        }

        $from = CarbonImmutable::create((int) ($validated['year'] ?? now()->year), 1, 1, 0, 0, 0, config('app.timezone'));

        return [$from, $from->addYear(), ['year' => $from->year]];
    }
}
