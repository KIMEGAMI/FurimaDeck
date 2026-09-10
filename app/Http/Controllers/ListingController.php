<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Marketplace;
use App\Services\AuditLogger;
use App\Services\ListingLifecycleService;
use App\Services\ListingProfitEstimator;
use App\Services\MarketplaceListingReadinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ListingController extends Controller
{
    public function index(Request $request): View
    {
        return view('listings.index', [
            'listings' => $request->user()->listings()->with(['product', 'marketplace'])->latest()->paginate(50),
        ]);
    }

    public function create(Request $request): View
    {
        return view('listings.create', $this->formData($request));
    }

    public function store(Request $request, ListingLifecycleService $lifecycle, ListingProfitEstimator $profitEstimator, AuditLogger $auditLogger): RedirectResponse
    {
        try {
            $listing = $lifecycle->create($request->user(), $this->listingValuesWithEstimate($request, $profitEstimator));
        } catch (\RuntimeException $exception) {
            return back()->withInput()->withErrors(['status' => $exception->getMessage()]);
        }
        $auditLogger->log($request->user(), Listing::class, $listing->id, 'listing.created', null, $listing->getAttributes(), $request->ip());

        return redirect()->route('listings.edit', $listing)->with('success', '出品準備データを作成しました。');
    }

    public function edit(Request $request, Listing $listing): View
    {
        $this->ensureOwner($request, $listing);

        return view('listings.edit', $this->formData($request, $listing) + ['listing' => $listing]);
    }

    public function update(Request $request, Listing $listing, ListingLifecycleService $lifecycle, ListingProfitEstimator $profitEstimator, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureOwner($request, $listing);
        $before = $listing->getAttributes();
        try {
            $listing = $lifecycle->update($request->user(), $listing, $this->listingValuesWithEstimate($request, $profitEstimator));
        } catch (\RuntimeException $exception) {
            return back()->withInput()->withErrors(['status' => $exception->getMessage()]);
        }
        $auditLogger->log($request->user(), Listing::class, $listing->id, 'listing.updated', $before, $listing->fresh()->getAttributes(), $request->ip());

        return back()->with('success', '出品準備データを更新しました。');
    }

    public function destroy(Request $request, Listing $listing, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureOwner($request, $listing);
        $request->validate(['confirm_deletion' => ['accepted']]);
        if ($listing->status !== 'draft' || $listing->sales()->exists()) {
            return back()->with('error', '下書き以外、または販売履歴のある出品は削除できません。');
        }

        $before = $listing->getAttributes();
        $listing->delete();
        $auditLogger->log($request->user(), Listing::class, $listing->id, 'listing.deleted', $before, null, $request->ip());

        return redirect()->route('listings.index')->with('success', '出品下書きを削除しました。');
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, ?Listing $listing = null): array
    {
        $marketplaces = Marketplace::query()->where('is_active', true)->orderBy('name')->get();

        return [
            'products' => $request->user()->products()->orderBy('product_name')->get(),
            'marketplaces' => $marketplaces,
            'marketplaceFeeRates' => $marketplaces->mapWithKeys(fn (Marketplace $marketplace) => [$marketplace->id => $marketplace->default_fee_rate]),
            'marketplaceRequirements' => app(MarketplaceListingReadinessService::class)->requirementsForMarketplaces($marketplaces),
            'statuses' => Listing::USER_SETTABLE_STATUSES,
        ];
    }

    /** @return array<string, mixed> */
    private function validatedListing(Request $request): array
    {
        return $request->validate([
            'product_id' => ['required', Rule::exists('products', 'id')->where('user_id', $request->user()->id)],
            'marketplace_id' => ['required', Rule::exists('marketplaces', 'id')->where('is_active', true)],
            'marketplace_other_name' => [
                'nullable',
                'string',
                'max:255',
            ],
            'listing_title' => ['required', 'string', 'max:255'],
            'listing_description' => ['nullable', 'string', 'max:10000'],
            'listing_price' => ['nullable', 'integer', 'min:0'],
            'listing_quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'expected_fee_rate' => ['required', 'decimal:0,2', 'min:0', 'max:100'],
            'marketplace_category' => ['nullable', 'string', 'max:255'],
            'marketplace_condition' => ['nullable', 'string', 'max:255'],
            'shipping_method' => ['nullable', 'string', 'max:255'],
            'shipping_payer' => ['nullable', Rule::in(Listing::SHIPPING_PAYERS)],
            'sender_region' => ['nullable', 'string', 'max:255'],
            'dispatch_days' => ['nullable', 'integer', 'min:0', 'max:30'],
            'shipping_size' => ['nullable', 'string', 'max:255'],
            'shipping_weight_grams' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'sale_format' => ['nullable', Rule::in(Listing::SALE_FORMATS)],
            'listing_period_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'return_policy' => ['nullable', 'string', 'max:1000'],
            'purchase_application' => ['nullable', Rule::in(Listing::PURCHASE_APPLICATIONS)],
            'shipping_fee' => ['nullable', 'integer', 'min:0'],
            'external_listing_url' => ['nullable', 'url:https', 'max:2048'],
            'external_listing_id' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in(Listing::USER_SETTABLE_STATUSES)],
        ]);
    }

    /** @return array<string, mixed> */
    private function listingValuesWithEstimate(Request $request, ListingProfitEstimator $profitEstimator): array
    {
        $values = $this->validatedListing($request);
        $marketplaceCode = Marketplace::query()->whereKey($values['marketplace_id'])->value('code');
        if ($marketplaceCode === 'other' && blank($values['marketplace_other_name'] ?? null)) {
            throw ValidationException::withMessages([
                'marketplace_other_name' => 'その他の販売先名を入力してください。',
            ]);
        }

        if (($values['listing_price'] ?? null) === null) {
            $values['expected_profit'] = null;
            $values['expected_margin'] = null;

            return $values;
        }

        $product = $request->user()->products()->findOrFail($values['product_id']);
        $estimate = $profitEstimator->estimate(
            $product,
            $values['listing_quantity'],
            $values['listing_price'],
            (string) $values['expected_fee_rate'],
            $values['shipping_fee'] ?? 0,
        );

        return [...$values, ...$estimate];
    }

    private function ensureOwner(Request $request, Listing $listing): void
    {
        abort_unless($listing->user_id === $request->user()->id, 404);
    }
}
