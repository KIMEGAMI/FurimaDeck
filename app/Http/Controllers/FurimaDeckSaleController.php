<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Marketplace;
use App\Models\Sale;
use App\Services\AuditLogger;
use App\Services\MarketplaceFeeService;
use App\Services\SaleLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class FurimaDeckSaleController extends Controller
{
    public function index(Request $request): View
    {
        return view('furimadeck_sales.index', ['sales' => $request->user()->sales()->with(['product', 'marketplace'])->latest('sold_at')->paginate(50)]);
    }

    public function create(Request $request, MarketplaceFeeService $feeService): View
    {
        $marketplaces = Marketplace::query()->where('is_active', true)->orderBy('name')->get();

        return view('furimadeck_sales.create', [
            'products' => $request->user()->products()->where('quantity_available', '>', 0)->orderBy('product_name')->get(),
            'listings' => $request->user()->listings()->whereIn('status', ['ready', 'active'])->with(['product', 'marketplace'])->get(),
            'marketplaces' => $marketplaces,
            'marketplaceFeeRates' => $feeService->rates($marketplaces),
            'selectedProductId' => $request->integer('product_id') ?: null,
        ]);
    }

    public function store(Request $request, SaleLifecycleService $lifecycle, AuditLogger $auditLogger, MarketplaceFeeService $feeService): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('user_id', $request->user()->id)],
            'listing_id' => ['required', Rule::exists('listings', 'id')->where('user_id', $request->user()->id)],
            'quantity' => ['required', 'integer', 'min:1'],
            'sold_price' => ['required', 'integer', 'min:0'],
            'sold_at' => ['required', 'date'],
            'cost_basis' => ['required', 'integer', 'min:0'],
            'sales_fee' => ['nullable', 'integer', 'min:0'],
            'shipping_fee' => ['nullable', 'integer', 'min:0'],
            'purchase_shipping_cost' => ['nullable', 'integer', 'min:0'],
            'packing_cost' => ['nullable', 'integer', 'min:0'],
            'repair_cost' => ['nullable', 'integer', 'min:0'],
            'cleaning_cost' => ['nullable', 'integer', 'min:0'],
            'other_expense' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $marketplaceId = (int) $request->user()->listings()->whereKey($validated['listing_id'])->value('marketplace_id');
            $marketplace = Marketplace::query()->whereKey($marketplaceId)->where('is_active', true)->firstOrFail();
            $validated['marketplace_id'] = $marketplace->id;
            $validated['sales_fee'] = $feeService->amount((int) $validated['sold_price'], $marketplace);
            $sale = $lifecycle->record($request->user(), $validated);
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['quantity' => $exception->getMessage()]);
        }
        $auditLogger->log($request->user(), Sale::class, $sale->id, 'sale.recorded', null, $sale->getAttributes(), $request->ip());

        $otherActiveListings = Listing::query()->where('product_id', $sale->product_id)->whereIn('status', ['ready', 'active'])->count();

        return redirect()->route('furimadeck-sales.index')->with('success', $otherActiveListings > 0
            ? '販売を記録しました。他の販売先にも出品準備・出品中のデータが残っています。確認してください。'
            : '販売を記録しました。');
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
        $validated = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'refund_amount' => ['required', 'integer', 'min:0'], 'return_shipping_fee' => ['nullable', 'integer', 'min:0'], 'restock' => ['nullable', 'boolean']]);
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
