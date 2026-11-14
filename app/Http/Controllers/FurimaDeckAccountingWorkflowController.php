<?php

namespace App\Http\Controllers;

use App\Models\AccountingEntry;
use App\Models\AccountingEntrySync;
use App\Services\FurimaDeckAccountingEntryService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FurimaDeckAccountingWorkflowController extends Controller
{
    public function preparePurchases(Request $request): RedirectResponse
    {
        $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:100'],
            'product_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return back()->with('error', '既存商品の仕入情報だけでは、期首在庫や会計ソフト側の仕訳との重複を判定できません。過去分から仕入会計データは作成しません。');
    }

    public function review(Request $request): RedirectResponse
    {
        $entries = $this->entriesForPeriod($request);
        $entries->where('sync_status', 'not_synced')
            ->where(fn (Builder $query): Builder => $query->where('source_status', 'ready')
                ->orWhere(fn (Builder $legacy): Builder => $legacy->where('source_status', 'confirmed')->whereNull('confirmed_at')))
            ->update(['source_status' => 'review', 'confirmed_at' => null]);

        return back()->with('success', '会計データをReview状態にしました。内容確認後にConfirmしてください。');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'accounting_entry_id' => ['required', 'integer'],
            'reviewed_source' => ['accepted'],
        ]);
        $entries = $this->entriesForPeriod($request);
        try {
            DB::connection('furimadeck')->transaction(function () use ($entries, $validated): void {
                $entry = $entries->whereKey($validated['accounting_entry_id'])->lockForUpdate()->firstOrFail();
                if ($entry->source_status !== 'review' || $entry->sync_status !== 'not_synced'
                    || $entry->providerSyncs()->whereIn('status', ['queued', 'sending', 'synced', 'sync_unknown', 'legacy_unknown', AccountingEntrySync::STATUS_MANUALLY_RECONCILED])->exists()) {
                    throw new RuntimeException('この会計データは確認対象ではありません。送信状態を確認してください。');
                }
                app(FurimaDeckAccountingEntryService::class)->assertCurrent($entry);
                $entry->update(['source_status' => 'confirmed', 'confirmed_at' => now()]);
            });
        } catch (ModelNotFoundException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', '明細を確認済みにしました。外部送信前に会計ソフトの設定も確認してください。');
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
