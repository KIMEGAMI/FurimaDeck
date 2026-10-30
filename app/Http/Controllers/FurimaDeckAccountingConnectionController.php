<?php

namespace App\Http\Controllers;

use App\Services\FurimaDeckAccountingOAuthService;
use App\Services\FurimaDeckFreeeAccountingSetupService;
use App\Services\FurimaDeckMoneyForwardAccountingSetupService;
use App\Services\FurimaDeckMoneyForwardBusinessPeriodService;
use App\Services\FurimaDeckMoneyForwardInvoiceKindService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            return to_route('furimadeck-accounting.index', ['section' => 'connections'])->withErrors(['accounting' => '会計サービスの連携がキャンセルされました。']);
        }

        try {
            $oauth->exchange($request->user(), $provider, (string) $request->query('code'));
        } catch (RuntimeException|ConnectionException $exception) {
            Log::warning('Accounting OAuth token exchange failed.', ['provider' => $provider, 'error_class' => $exception::class]);

            return to_route('furimadeck-accounting.index', ['section' => 'connections'])->withErrors(['accounting' => '会計サービスとの連携に失敗しました。']);
        }

        return to_route('furimadeck-accounting.index', ['section' => 'connections'])->with('success', '会計サービスを連携しました。');
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
            return to_route('furimadeck-accounting.index', ['section' => 'connections'])->withErrors(['accounting' => $exception->getMessage()]);
        }
    }

    public function saveFreeeSetup(Request $request, FurimaDeckFreeeAccountingSetupService $setup): RedirectResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'min:1'],
            'account_item_ids' => ['required', 'array'],
            'account_item_ids.settlement' => ['required', 'integer', 'min:1'],
            'account_item_ids.sales' => ['required', 'integer', 'min:1'],
            'account_item_ids.fees' => ['required', 'integer', 'min:1'],
            'account_item_ids.cost' => ['required', 'integer', 'min:1'],
            'account_item_ids.inventory' => ['required', 'integer', 'min:1'],
            'account_item_ids.shipping' => ['nullable', 'integer', 'min:1'],
            'account_item_ids.other' => ['nullable', 'integer', 'min:1'],
            'purchase_payment_account_ids_present' => ['sometimes', 'accepted'],
            'purchase_payment_account_ids' => ['nullable', 'array'],
            'purchase_payment_account_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $setup->save($request->user(), $validated);
        } catch (RuntimeException|ConnectionException $exception) {
            return back()->withErrors(['accounting' => $exception->getMessage()])->withInput();
        }

        return to_route('furimadeck-accounting.index', ['section' => 'connections'])->with('success', 'freeeの仕訳設定を保存しました。');
    }

    public function moneyForwardSetup(Request $request, FurimaDeckMoneyForwardAccountingSetupService $setup, FurimaDeckMoneyForwardBusinessPeriodService $businessPeriods): View|RedirectResponse
    {
        try {
            $connection = $setup->connection($request->user());
            $options = $setup->options($request->user());

            $settings = (array) $connection->settings;
            $oldPeriods = $request->session()->getOldInput('business_tax_periods');
            $storedPeriods = $settings['business_tax_periods'] ?? [];
            $settings['business_tax_periods'] = $businessPeriods->normalizeForDisplay(
                is_array($oldPeriods) ? $oldPeriods : (is_array($storedPeriods) ? $storedPeriods : []),
            );

            return view('furimadeck_accounting.money-forward-setup', [
                'options' => $options,
                'settings' => $settings,
                'eligibleAccountIds' => $setup->eligibleAccountIds($options['accounts']['accounts'] ?? []),
            ]);
        } catch (RuntimeException|ConnectionException $exception) {
            return to_route('furimadeck-accounting.index', ['section' => 'connections'])->withErrors(['accounting' => $exception->getMessage()]);
        }
    }

    public function saveMoneyForwardSetup(Request $request, FurimaDeckMoneyForwardAccountingSetupService $setup): RedirectResponse
    {
        $requiresInvoiceKinds = collect($request->input('business_tax_periods', []))
            ->contains(static fn (mixed $period): bool => is_array($period)
                && ($period['invoice_kind'] ?? null) === FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_TAXABLE);
        $invoiceKindRule = [$requiresInvoiceKinds ? 'required' : 'nullable', Rule::in(FurimaDeckMoneyForwardInvoiceKindService::CSV_SUPPORTED_KINDS)];
        $optionalInvoiceKindRule = ['nullable', Rule::in(FurimaDeckMoneyForwardInvoiceKindService::CSV_SUPPORTED_KINDS)];
        $validated = $request->validate([
            'account_ids' => ['required', 'array'],
            'account_ids.settlement' => ['required', 'string', 'max:255'],
            'account_ids.sales' => ['required', 'string', 'max:255'],
            'account_ids.fees' => ['required', 'string', 'max:255'],
            'account_ids.cost' => ['required', 'string', 'max:255'],
            'account_ids.inventory' => ['required', 'string', 'max:255'],
            'account_ids.shipping' => ['nullable', 'string', 'max:255'],
            'account_ids.other' => ['nullable', 'string', 'max:255'],
            'business_tax_periods' => ['required', 'array', 'min:1'],
            'business_tax_periods.*' => ['required', 'array:from,to,invoice_kind'],
            'business_tax_periods.*.from' => ['required', 'date_format:Y-m-d'],
            'business_tax_periods.*.to' => ['required', 'date_format:Y-m-d'],
            'business_tax_periods.*.invoice_kind' => ['required', Rule::in([
                ...FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES,
                ...FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_LEGACY_VALUES,
            ])],
            'invoice_kinds' => ['sometimes', 'array'],
            'invoice_kinds.settlement' => $invoiceKindRule,
            'invoice_kinds.sales' => $invoiceKindRule,
            'invoice_kinds.fees' => $invoiceKindRule,
            'invoice_kinds.cost' => $invoiceKindRule,
            'invoice_kinds.inventory' => $invoiceKindRule,
            'invoice_kinds.shipping' => $optionalInvoiceKindRule,
            'invoice_kinds.other' => $optionalInvoiceKindRule,
            'purchase_payment_account_ids_present' => ['sometimes', 'accepted'],
            'purchase_payment_account_ids' => ['nullable', 'array'],
            'purchase_payment_account_ids.*' => ['required', 'string', 'max:255'],
            'purchase_tax_ids' => ['nullable', 'array:standard_10,reduced_8,exempt,non_taxable,out_of_scope'],
            'purchase_tax_ids.*' => ['nullable', 'string', 'max:255'],
        ]);
        try {
            $setup->save($request->user(), $validated);
        } catch (RuntimeException|ConnectionException $exception) {
            return back()->withErrors(['accounting' => $exception->getMessage()])->withInput();
        }

        return to_route('furimadeck-accounting.index', ['section' => 'connections'])->with('success', 'Money Forwardの仕訳設定を保存しました。');
    }

    public function disconnect(Request $request, string $provider, FurimaDeckAccountingOAuthService $oauth): RedirectResponse
    {
        $oauth->disconnect($request->user(), $provider);

        return back()->with('success', '会計サービスの連携を解除しました。');
    }
}
