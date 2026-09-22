<?php

namespace App\Http\Controllers;

use App\Services\FurimaDeckAccountingOAuthService;
use App\Services\FurimaDeckFreeeAccountingSetupService;
use App\Services\FurimaDeckMoneyForwardAccountingSetupService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class FurimaDeckAccountingConnectionController extends Controller
{
    public function connect(Request $request, string $provider, FurimaDeckAccountingOAuthService $oauth): RedirectResponse
    {
        try {
            $state = Str::random(64);
            $request->session()->put('furimadeck.accounting.oauth_state.'.$provider, $state);

            return redirect()->away($oauth->authorizationUrl($request->user(), $provider, $state));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['accounting' => $exception->getMessage()]);
        }
    }

    public function callback(Request $request, string $provider, FurimaDeckAccountingOAuthService $oauth): RedirectResponse
    {
        $expectedState = (string) $request->session()->pull('furimadeck.accounting.oauth_state.'.$provider);
        $state = (string) $request->query('state');
        if ($expectedState === '' || $state === '' || ! hash_equals($expectedState, $state)) {
            abort(419, '会計サービスのOAuth stateが一致しません。');
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return to_route('furimadeck-accounting.index')->withErrors(['accounting' => '会計サービスの連携がキャンセルされました。']);
        }

        try {
            $oauth->exchange($request->user(), $provider, (string) $request->query('code'));
        } catch (RuntimeException $exception) {
            Log::warning('Accounting OAuth token exchange failed.', ['provider' => $provider, 'error_class' => $exception::class]);

            return to_route('furimadeck-accounting.index')->withErrors(['accounting' => '会計サービスとの連携に失敗しました。']);
        }

        return to_route('furimadeck-accounting.index')->with('success', '会計サービスを連携しました。');
    }

    public function freeeSetup(Request $request, FurimaDeckFreeeAccountingSetupService $setup): View|RedirectResponse
    {
        try {
            if ($request->filled('company_id')) {
                $companyId = $request->integer('company_id');
                abort_unless($companyId > 0, 422);

                return view('furimadeck_accounting.freee-setup', [
                    'companies' => $setup->companies($request->user())['companies'],
                    'options' => $setup->options($request->user(), $companyId),
                ]);
            }

            return view('furimadeck_accounting.freee-setup', [
                'companies' => $setup->companies($request->user())['companies'],
                'options' => null,
            ]);
        } catch (RuntimeException|ConnectionException $exception) {
            return to_route('furimadeck-accounting.index')->withErrors(['accounting' => $exception->getMessage()]);
        }
    }

    public function saveFreeeSetup(Request $request, FurimaDeckFreeeAccountingSetupService $setup): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'min:1'],
            'tax_code' => ['required', 'string', 'max:32'],
            'account_item_ids' => ['required', 'array'],
            'account_item_ids.settlement' => ['required', 'integer', 'min:1'],
            'account_item_ids.sales' => ['required', 'integer', 'min:1'],
            'account_item_ids.fees' => ['required', 'integer', 'min:1'],
            'account_item_ids.cost' => ['required', 'integer', 'min:1'],
            'account_item_ids.inventory' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $setup->save($request->user(), $validated);
        } catch (RuntimeException|ConnectionException $exception) {
            return back()->withErrors(['accounting' => $exception->getMessage()])->withInput();
        }

        return to_route('furimadeck-accounting.index')->with('success', 'freeeの仕訳設定を保存しました。');
    }

    public function moneyForwardSetup(Request $request, FurimaDeckMoneyForwardAccountingSetupService $setup): View|RedirectResponse
    {
        try {
            $connection = $setup->connection($request->user());
            $options = $setup->options($request->user());

            return view('furimadeck_accounting.money-forward-setup', [
                'options' => $options,
                'settings' => (array) $connection->settings,
            ]);
        } catch (RuntimeException|ConnectionException $exception) {
            return to_route('furimadeck-accounting.index')->withErrors(['accounting' => $exception->getMessage()]);
        }
    }

    public function saveMoneyForwardSetup(Request $request, FurimaDeckMoneyForwardAccountingSetupService $setup): RedirectResponse
    {
        $validated = $request->validate([
            'tax_id' => ['required', 'string', 'max:255'],
            'account_ids' => ['required', 'array'],
            'account_ids.settlement' => ['required', 'string', 'max:255'],
            'account_ids.sales' => ['required', 'string', 'max:255'],
            'account_ids.fees' => ['required', 'string', 'max:255'],
            'account_ids.cost' => ['required', 'string', 'max:255'],
            'account_ids.inventory' => ['required', 'string', 'max:255'],
            'invoice_kind' => ['required', 'in:INVOICE_KIND_NOT_TARGET,INVOICE_KIND_QUALIFIED,INVOICE_KIND_NON_QUALIFIED'],
        ]);
        try {
            $setup->save($request->user(), $validated);
        } catch (RuntimeException|ConnectionException $exception) {
            return back()->withErrors(['accounting' => $exception->getMessage()])->withInput();
        }

        return to_route('furimadeck-accounting.index')->with('success', 'Money Forwardの仕訳設定を保存しました。');
    }

    public function disconnect(Request $request, string $provider, FurimaDeckAccountingOAuthService $oauth): RedirectResponse
    {
        $oauth->disconnect($request->user(), $provider);

        return back()->with('success', '会計サービスの連携を解除しました。');
    }
}
