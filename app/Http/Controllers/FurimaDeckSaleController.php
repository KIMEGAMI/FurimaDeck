<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Marketplace;
use App\Models\Sale;
use App\Services\AuditLogger;
use App\Services\FurimaDeckAccountingEntryService;
use App\Services\MarketplaceFeeService;
use App\Services\SaleLifecycleService;
use App\Services\SaleProfitCalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

class FurimaDeckSaleController extends Controller
{
    public function index(Request $request): View
    {
        return view('furimadeck_sales.index', ['sales' => $request->user()->sales()->with(['product', 'marketplace'])->latest('sold_at')->paginate(50)]);
    }

    public function create(Request $request, MarketplaceFeeService $feeService): View|RedirectResponse
    {
        $marketplaces = Marketplace::query()->where('is_active', true)->orderBy('name')->get();
        $selectedProductId = $request->integer('product_id') ?: null;
        $selectedProduct = $request->user()->products()
            ->where('quantity_available', '>', 0)
            ->with(['listings' => fn ($query) => $query->whereIn('status', ['draft', 'ready', 'active'])->with('marketplace')->orderByDesc('updated_at')->orderByDesc('id')])
            ->whereKey($selectedProductId)
            ->first();

        if ($selectedProduct === null) {
            return to_route('products.index')->with('error', '販売を記録する商品を商品一覧から選んでください。');
        }

        try {
            app(FurimaDeckAccountingEntryService::class)
                ->costBasisForNewSale($request->user(), $selectedProduct);
        } catch (RuntimeException $exception) {
            return to_route('products.index')->with('error', $exception->getMessage());
        }

        $listing = $selectedProduct->listings->first();
        $selectedSaleDefaults = [
            'marketplaceId' => $listing?->marketplace_id,
            'marketplaceName' => $listing?->marketplace?->name,
            'listingTitle' => $listing?->listing_title,
            'feeRate' => $listing === null || $listing->expected_fee_rate === null || (float) $listing->expected_fee_rate <= 0
                ? ($listing?->marketplace ? $feeService->rate($listing->marketplace) : null)
                : (float) $listing->expected_fee_rate,
            'shippingFee' => (int) ($listing?->shipping_fee ?? 0),
        ];

        return view('furimadeck_sales.create', [
            'selectedProduct' => $selectedProduct,
            'marketplaces' => $marketplaces,
            'marketplaceFeeRates' => $feeService->rates($marketplaces),
            'selectedSaleDefaults' => $selectedSaleDefaults,
            'selectedMarketplaceId' => $request->integer('marketplace_id') ?: $listing?->marketplace_id,
        ]);
    }

    public function store(Request $request, SaleLifecycleService $lifecycle, AuditLogger $auditLogger, MarketplaceFeeService $feeService): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('user_id', $request->user()->id)],
            'marketplace_id' => ['nullable', Rule::exists('marketplaces', 'id')->where('is_active', true)],
            'sold_price' => ['required', 'integer', 'min:0', 'max:'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT],
            'sold_at' => ['required', 'date'],
            'shipping_fee' => ['nullable', 'integer', 'min:0', 'max:'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT],
        ]);
        $validated['quantity'] = 1;

        try {
            $listing = $request->user()->listings()
                ->where('product_id', $validated['product_id'])
                ->whereIn('status', ['draft', 'ready', 'active'])
                ->with('marketplace')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();

            $marketplaceId = $validated['marketplace_id'] ?? $listing?->marketplace_id;
            if ($marketplaceId === null) {
                throw ValidationException::withMessages([
                    'marketplace_id' => '出品情報がない商品は、販売先を選択してください。',
                ]);
            }

            $marketplace = Marketplace::query()->whereKey($marketplaceId)->where('is_active', true)->firstOrFail();
            $sameMarketplaceAsListing = $listing !== null && (int) $listing->marketplace_id === (int) $marketplace->id;
            $validated['listing_id'] = $sameMarketplaceAsListing && in_array($listing->status, ['ready', 'active'], true)
                ? $listing->id
                : null;
            $defaultFeeRate = $sameMarketplaceAsListing && $listing->expected_fee_rate !== null && (float) $listing->expected_fee_rate > 0
                ? (float) $listing->expected_fee_rate
                : $feeService->rate($marketplace);

            $feeRate = $defaultFeeRate;
            $validated['shipping_fee'] = $validated['shipping_fee']
                ?? ($sameMarketplaceAsListing ? (int) ($listing->shipping_fee ?? 0) : 0);
            $validated['marketplace_id'] = $marketplace->id;
            $validated['sales_fee_rate'] = $feeRate;
            $validated['sales_fee'] = $feeService->amountAtRate((int) $validated['sold_price'], $feeRate);
            $sale = $lifecycle->record($request->user(), $validated);
        } catch (InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['sold_price' => $exception->getMessage()]);
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['quantity' => $exception->getMessage()]);
        }
        $auditLogger->log($request->user(), Sale::class, $sale->id, 'sale.recorded', null, $sale->getAttributes(), $request->ip());

        $otherActiveListings = Listing::query()->where('product_id', $sale->product_id)->whereIn('status', ['ready', 'active'])->count();

        return redirect()->route('products.index')->with('success', $otherActiveListings > 0
            ? '販売を記録しました。他の販売先にも出品準備・出品中のデータが残っています。確認してください。'
            : '販売を記録しました。');
    }

    public function edit(Request $request, Sale $sale, SaleLifecycleService $lifecycle, MarketplaceFeeService $feeService): View
    {
        $this->ensureOwner($request, $sale);
        abort_unless(in_array($sale->status, Sale::VALID_SOLD_STATUSES, true), 404);

        $sale->loadMissing('product', 'marketplace');
        $marketplaces = Marketplace::query()
            ->where('is_active', true)
            ->orWhereKey($sale->marketplace_id)
            ->orderBy('name')
            ->get();
        $marketplaceFeeRates = $feeService->rates($marketplaces);
        if ($sale->marketplace !== null && $sale->getRawOriginal('sales_fee_rate') !== null) {
            $marketplaceFeeRates[(string) $sale->marketplace_id] = (float) $sale->getRawOriginal('sales_fee_rate');
        }

        return view('furimadeck_sales.edit', [
            'sale' => $sale,
            'marketplaces' => $marketplaces,
            'marketplaceFeeRates' => $marketplaceFeeRates,
            'amountCorrectionBlockReason' => $lifecycle->amountCorrectionBlockReason($request->user(), $sale),
        ]);
    }

    public function update(Request $request, Sale $sale, SaleLifecycleService $lifecycle, AuditLogger $auditLogger, MarketplaceFeeService $feeService): RedirectResponse
    {
        $this->ensureOwner($request, $sale);
        $rules = [
            'record_version' => ['required', 'string'],
            'sold_at' => ['required', 'date_format:Y-m-d\\TH:i'],
            'marketplace_id' => ['required', 'integer', Rule::exists('marketplaces', 'id')],
            'sold_price' => ['required', 'integer', 'min:0', 'max:'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT],
            'shipping_fee' => ['required', 'integer', 'min:0', 'max:'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT],
        ];
        $validated = $request->validate($rules);
        $marketplace = Marketplace::query()->findOrFail($validated['marketplace_id']);
        if (! $marketplace->is_active && (int) $marketplace->id !== (int) $sale->marketplace_id) {
            throw ValidationException::withMessages(['marketplace_id' => '利用停止中の販売先は新しく選択できません。']);
        }
        $storedFeeRate = $sale->getRawOriginal('sales_fee_rate');
        $feeRate = (int) $sale->marketplace_id === (int) $marketplace->id && $storedFeeRate !== null
            ? (float) $storedFeeRate
            : $feeService->rate($marketplace);
        $validated['marketplace_id'] = (int) $marketplace->id;
        $validated['sales_fee_rate'] = $feeRate;
        $validated['sales_fee'] = $feeService->amountAtRate((int) $validated['sold_price'], $feeRate);
        $sale->loadMissing('listing');
        $validated['listing_id'] = $sale->listing !== null
            && (int) $sale->listing->marketplace_id === (int) $marketplace->id
            ? $sale->listing_id
            : null;
        $before = $sale->getAttributes();
        $accountingAmounts = $sale->only([
            'cost_basis', 'purchase_shipping_cost', 'packing_cost', 'repair_cost', 'cleaning_cost', 'other_expense',
        ]);
        try {
            $corrected = $lifecycle->correctAmounts($request->user(), $sale, [
                ...$validated,
                ...$accountingAmounts,
            ]);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return back()->withInput()->withErrors(['sold_price' => $exception->getMessage()]);
        }
        if ($before !== $corrected->getAttributes()) {
            $auditLogger->log($request->user(), Sale::class, $sale->id, 'sale.amounts_corrected', $before, $corrected->getAttributes());
        }

        return to_route('furimadeck-accounting.index', ['year' => $corrected->sold_at->year, 'section' => 'review'])
            ->with('success', '販売記録を修正しました。会計データを更新し、内容を再確認してください。');
    }

    public function cancel(Request $request, Sale $sale, SaleLifecycleService $lifecycle, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureOwner($request, $sale);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $before = $sale->getAttributes();
        try {
            $sale = $lifecycle->cancel($request->user(), $sale, $validated['reason']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }
        $auditLogger->log($request->user(), Sale::class, $sale->id, 'sale.cancelled', $before, $sale->getAttributes(), $request->ip());

        return back()->with('success', '取引をキャンセルし、在庫を戻しました。');
    }

    public function advanceStatus(Request $request, Sale $sale, SaleLifecycleService $lifecycle, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureOwner($request, $sale);
        $validated = $request->validate(['status' => ['required', Rule::in(['shipped', 'completed'])]]);
        $before = $sale->getAttributes();
        try {
            $sale = $lifecycle->advanceStatus($request->user(), $sale, $validated['status']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['status' => $exception->getMessage()]);
        }
        $auditLogger->log($request->user(), Sale::class, $sale->id, 'sale.status_advanced', $before, $sale->getAttributes(), $request->ip());

        return back()->with('success', $sale->status === 'shipped' ? '発送済みに更新しました。' : '取引を完了しました。');
    }

    public function returnSale(Request $request, Sale $sale, SaleLifecycleService $lifecycle, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureOwner($request, $sale);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'refund_amount' => ['required', 'integer', 'min:0', 'max:'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT], 'return_shipping_fee' => ['nullable', 'integer', 'min:0', 'max:'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT], 'restock' => ['nullable', 'boolean']]);
        $before = $sale->getAttributes();
        try {
            $sale = $lifecycle->returnSale($request->user(), $sale, $validated['reason'], $validated['refund_amount'], (int) ($validated['return_shipping_fee'] ?? 0), $request->boolean('restock'));
        } catch (RuntimeException $exception) {
            return back()->withErrors(['reason' => $exception->getMessage()]);
        }
        $auditLogger->log($request->user(), Sale::class, $sale->id, 'sale.returned', $before, $sale->getAttributes(), $request->ip());

        return back()->with('success', '返品を記録しました。');
    }

    private function ensureOwner(Request $request, Sale $sale): void
    {
        abort_unless($sale->user_id === $request->user()->id, 404);
    }
}
