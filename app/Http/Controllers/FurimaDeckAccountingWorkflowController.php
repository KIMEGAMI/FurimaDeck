<?php

namespace App\Http\Controllers;

use App\Models\AccountingEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FurimaDeckAccountingWorkflowController extends Controller
{
    public function review(Request $request): RedirectResponse
    {
        $entries = $this->entriesForPeriod($request);
        $entries->where('sync_status', 'not_synced')->update(['source_status' => 'review']);

        return back()->with('success', '会計データをReview状態にしました。内容確認後にConfirmしてください。');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $entries = $this->entriesForPeriod($request);
        $entries->where('source_status', 'review')->where('sync_status', 'not_synced')->update(['source_status' => 'confirmed']);

        return back()->with('success', '会計データをConfirm状態にしました。外部送信前の最終確認を行ってください。');
    }

    /** @return Builder<AccountingEntry> */
    private function entriesForPeriod(Request $request)
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
            $toExclusive = $to->addDay();
        } else {
            $from = CarbonImmutable::create((int) ($validated['year'] ?? now()->year), 1, 1, 0, 0, 0, config('app.timezone'));
            $toExclusive = $from->addYear();
        }

        return $request->user()->accountingEntries()->eligibleForAccounting()
            ->where('transaction_date', '>=', $from->toDateString())
            ->where('transaction_date', '<', $toExclusive->toDateString());
    }
}
