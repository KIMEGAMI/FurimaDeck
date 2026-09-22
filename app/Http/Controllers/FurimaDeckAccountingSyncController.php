<?php

namespace App\Http\Controllers;

use App\Models\AccountingEntry;
use App\Services\FurimaDeckFreeeAccountingSyncService;
use App\Services\FurimaDeckMoneyForwardAccountingSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class FurimaDeckAccountingSyncController extends Controller
{
    public function freee(Request $request, AccountingEntry $accountingEntry, FurimaDeckFreeeAccountingSyncService $sync): RedirectResponse
    {
        abort_unless($accountingEntry->user_id === $request->user()->id, 404);
        try {
            $sync->send($request->user(), $accountingEntry);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'freeeへ会計データを送信しました。');
    }

    public function moneyForward(Request $request, AccountingEntry $accountingEntry, FurimaDeckMoneyForwardAccountingSyncService $sync): RedirectResponse
    {
        abort_unless($accountingEntry->user_id === $request->user()->id, 404);
        try {
            $sync->send($request->user(), $accountingEntry);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Money Forwardへ会計データを送信しました。');
    }
}
