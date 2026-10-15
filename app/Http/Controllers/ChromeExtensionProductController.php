<?php

namespace App\Http\Controllers;

use App\Models\Listing;
use App\Models\Marketplace;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\ListingLifecycleService;
use App\Services\ListingProfitEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChromeExtensionProductController extends Controller
{
    private const PLATFORMS = [
        'mercari' => 'メルカリ',
        'rakuma' => 'ラクマ',
        'yahoo_flea_market' => 'Yahoo!フリマ',
        'yahoo_auctions' => 'Yahoo!オークション',
        'mercari_shops' => 'メルカリShops',
    ];

    private const PLATFORM_HOSTS = [
        'mercari' => ['jp.mercari.com'],
        'rakuma' => ['fril.jp'],
        'yahoo_flea_market' => ['paypayfleamarket.yahoo.co.jp'],
        'yahoo_auctions' => ['auctions.yahoo.co.jp'],
        'mercari_shops' => ['mercari-shops.com'],
    ];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $user = $request->attributes->get('chrome_extension_user');
        $products = $user->products()
            ->with('category')
            ->when($validated['query'] ?? null, function ($query, string $search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('internal_sku', 'like', '%'.$search.'%')
                        ->orWhere('product_name', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('product_name')
            ->paginate((int) config('chrome_extension.product_page_size'), ['id', 'internal_sku', 'product_name', 'category_id', 'condition', 'quantity_available'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json([
            'data' => $products->getCollection()->map(fn (Product $product): array => [
                'id' => $product->id,
                'internal_sku' => $product->internal_sku,
                'product_name' => $product->product_name,
                'category' => $product->category?->name,
                'condition' => $product->condition,
                'quantity_available' => $product->quantity_available,
            ]),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function draft(Request $request, int $productId): JsonResponse
    {
        $validated = $request->validate(['platform' => ['required', 'string', 'in:'.implode(',', array_keys(self::PLATFORMS))]]);
        abort_unless(config('chrome_extension.platforms.'.$validated['platform'].'.enabled', true), 503, 'この出品先の拡張機能操作は現在停止中です。');
        $user = $request->attributes->get('chrome_extension_user');
        $product = $user->products()->with(['category', 'images', 'attributeValues.categoryAttribute'])->findOrFail($productId);
        $marketplace = Marketplace::query()->where('code', $validated['platform'])->where('is_active', true)->first();
        $listing = $marketplace === null
            ? null
            : $user->listings()
                ->where('product_id', $product->id)
                ->where('marketplace_id', $marketplace->id)
                ->whereIn('status', ['draft', 'ready', 'active'])
                ->latest('updated_at')
                ->first();

        $reviewFields = [];
        $price = $listing?->listing_price;
        $category = $listing?->marketplace_category;
        if ($price === null) {
            $reviewFields[] = 'price';
        }
        if (blank($category)) {
            $reviewFields[] = 'marketplace_category';
        }
        if ($listing === null || blank($listing->shipping_method)) {
            $reviewFields[] = 'shipping_method';
        }
        if ($listing === null || blank($listing->marketplace_condition)) {
            $reviewFields[] = 'condition';
        }
        if (blank($listing?->listing_description ?: $product->description_base)) {
            $reviewFields[] = 'description';
        }

        return response()->json([
            'product' => [
                'id' => $product->id,
                'internal_sku' => $product->internal_sku,
                'product_name' => $product->product_name,
                'title' => $listing?->listing_title ?: $product->product_name,
                'description' => $listing?->listing_description ?: $product->description_base,
                'price' => $price,
                'condition' => $listing?->marketplace_condition ?: Product::conditionLabel($product->condition),
                'category' => $category ?: $product->category?->name,
                'jan_ean' => $product->jan_ean,
                'isbn' => $product->isbn,
                'manufacturer_model_number' => $product->manufacturer_model_number,
                'serial_number' => $product->serial_number,
                'shipping_method' => $listing?->shipping_method,
                'shipping_fee' => $listing?->shipping_fee,
                'shipping_payer' => $listing?->shipping_payer,
                'sender_region' => $listing?->sender_region,
                'dispatch_days' => $listing?->dispatch_days,
                'shipping_size' => $listing?->shipping_size,
                'shipping_weight_grams' => $listing?->shipping_weight_grams,
                'sale_format' => $listing?->sale_format,
                'listing_period_days' => $listing?->listing_period_days,
                'return_policy' => $listing?->return_policy,
                'purchase_application' => $listing?->purchase_application,
                'sales_fee_rate' => $listing?->expected_fee_rate ?? $marketplace?->default_fee_rate,
                'listing_quantity' => $listing?->listing_quantity,
                'quantity_available' => $product->quantity_available,
                'attributes' => $product->attributeValues
                    ->filter(fn ($value): bool => filled($value->categoryAttribute?->label) && filled($value->value_text))
                    ->map(fn ($value): array => [
                        'label' => $value->categoryAttribute->label,
                        'value' => $value->value_text,
                    ])
                    ->values(),
                'images' => $product->images->map(fn ($image): array => [
                    'id' => $image->id,
                    'position' => $image->position,
                    'path' => '/api/extension/v1/products/'.$product->id.'/images/'.$image->id,
                ])->values(),
            ],
            'platform' => ['id' => $validated['platform'], 'name' => self::PLATFORMS[$validated['platform']], 'operation_level' => 'manual_assist'],
            'review_fields' => $reviewFields,
            'authorized_publish' => false,
            'listing_history' => $user->listings()
                ->with('marketplace')
                ->where('product_id', $product->id)
                ->whereHas('marketplace', fn ($query) => $query->where('code', $validated['platform']))
                ->latest('listed_at')
                ->limit(20)
                ->get()
                ->map(fn ($item): array => [
                    'id' => $item->id,
                    'url' => $item->external_listing_url,
                    'price' => $item->listing_price,
                    'status' => $item->status,
                    'listed_at' => $item->listed_at?->toIso8601String(),
                    'record_method' => $item->record_method,
                ])->values(),
        ]);
    }

    public function recordListing(
        Request $request,
        int $productId,
        ListingLifecycleService $lifecycle,
        ListingProfitEstimator $profitEstimator,
        AuditLogger $auditLogger,
    ): JsonResponse {
        $validated = $request->validate([
            'platform' => ['required', 'string', 'in:'.implode(',', array_keys(self::PLATFORMS))],
            'external_listing_url' => ['required', 'url:https', 'max:2048'],
            'listing_price' => ['required', 'integer', 'min:0', 'max:100000000'],
            'confirmed_listed' => ['required', 'accepted'],
        ]);
        abort_unless(config('chrome_extension.platforms.'.$validated['platform'].'.enabled', true), 503, 'この出品先の拡張機能操作は現在停止中です。');

        $url = parse_url($validated['external_listing_url']);
        $host = is_array($url) ? strtolower((string) ($url['host'] ?? '')) : '';
        $allowedHosts = self::PLATFORM_HOSTS[$validated['platform']];
        $hostIsAllowed = collect($allowedHosts)->contains(fn (string $allowed): bool => $host === $allowed || str_ends_with($host, '.'.$allowed));
        if (! $hostIsAllowed || isset($url['user']) || isset($url['pass'])) {
            throw ValidationException::withMessages([
                'external_listing_url' => '選択した出品先の公式URLを入力してください。',
            ]);
        }

        $user = $request->attributes->get('chrome_extension_user');
        $product = $user->products()->findOrFail($productId);
        $marketplace = Marketplace::query()
            ->where('code', $validated['platform'])
            ->where('is_active', true)
            ->firstOrFail();

        $record = DB::transaction(function () use ($user, $product, $marketplace, $validated, $lifecycle, $profitEstimator): array {
            $existing = $user->listings()
                ->where('external_listing_url', $validated['external_listing_url'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return [$existing, false];
            }

            $source = $user->listings()
                ->where('product_id', $product->id)
                ->where('marketplace_id', $marketplace->id)
                ->latest('updated_at')
                ->first();
            $price = (int) $validated['listing_price'];
            $feeRate = (string) ($source?->expected_fee_rate ?? $marketplace->default_fee_rate);
            $shippingFee = (int) ($source?->shipping_fee ?? 0);
            $estimate = $profitEstimator->estimate($product, 1, $price, $feeRate, $shippingFee);

            $listing = $lifecycle->create($user, [
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'listing_title' => $source?->listing_title ?: $product->product_name,
                'listing_description' => $source?->listing_description ?: $product->description_base,
                'listing_price' => $price,
                'listing_quantity' => 1,
                'expected_fee_rate' => $feeRate,
                'expected_profit' => $estimate['expected_profit'],
                'expected_margin' => $estimate['expected_margin'],
                'marketplace_category' => $source?->marketplace_category,
                'marketplace_condition' => $source?->marketplace_condition,
                'shipping_method' => $source?->shipping_method,
                'shipping_fee' => $shippingFee,
                'external_listing_url' => $validated['external_listing_url'],
                'record_method' => 'manual_confirmed',
                'status' => 'active',
            ]);

            return [$listing, true];
        });

        [$listing, $created] = $record;
        if ($created) {
            $auditLogger->log($user, Listing::class, $listing->id, 'chrome_extension.listing_recorded', null, [
                'marketplace_id' => $marketplace->id,
                'status' => 'active',
                'record_method' => 'manual_confirmed',
            ]);
        }

        return response()->json([
            'created' => $created,
            'listing' => [
                'id' => $listing->id,
                'url' => $listing->external_listing_url,
                'price' => $listing->listing_price,
                'status' => $listing->status,
                'listed_at' => $listing->listed_at?->toIso8601String(),
                'record_method' => $listing->record_method,
            ],
        ], $created ? 201 : 200);
    }
}
