<?php

namespace App\Http\Controllers;

use App\Models\AccountingEntry;
use App\Services\FurimaDeckAccountingJournalVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class FurimaDeckAccountingJournalVerificationController extends Controller
{
    public function verify(Request $request, AccountingEntry $accountingEntry, string $provider, FurimaDeckAccountingJournalVerificationService $service): RedirectResponse
    {
        abort_unless($accountingEntry->user_id === $request->user()->id, 404);
        $validated = $request->validate([
            'bind_legacy_scope' => ['sometimes', 'in:1'],
            'confirm_current_account' => ['exclude_unless:bind_legacy_scope,1', 'accepted'],
            'confirm_external_transaction_id' => ['exclude_unless:bind_legacy_scope,1', 'accepted'],
            'confirm_read_only' => ['exclude_unless:bind_legacy_scope,1', 'accepted'],
        ], [
            'confirm_current_account.accepted' => '現在の会計事業者が送信先と同じことを確認してください。',
            'confirm_external_transaction_id.accepted' => '外部仕訳IDが一致することを確認してください。',
            'confirm_read_only.accepted' => '読取専用で再送しない操作であることを確認してください。',
        ]);
        try {
            $matches = isset($validated['bind_legacy_scope'])
                ? $service->verifyLegacyUnscoped(
                    $request->user(),
                    $accountingEntry,
                    $provider,
                    true,
                    true,
                    true,
                )
                : $service->verify($request->user(), $accountingEntry, $provider);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with($matches ? 'success' : 'error', $matches
            ? (isset($validated['bind_legacy_scope'])
                ? '仕訳内容が一致しました。現在の会計事業者との紐付けを記録しました。'
                : '会計ソフトから取得した仕訳内容と送信時の内容が一致しました。')
            : '会計ソフトの仕訳内容が送信時の内容と一致しません。外部側を確認してください。');
    }
}
