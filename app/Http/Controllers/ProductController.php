<?php

namespace App\Http\Controllers;

use App\Models\CategoryAttribute;
use App\Models\Listing;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use App\Models\Sale;
use App\Services\AuditLogger;
use App\Services\FurimaDeckEntitlements;
use App\Services\FurimaDeckPurchaseInfoLock;
use App\Services\ListingProfitEstimator;
use App\Services\MarketplaceFeeService;
use App\Services\ProductAcquisitionInfoUpdateService;
use App\Services\ProductImageDeletionOutbox;
use App\Services\ProductImageProcessor;
use App\Services\ProductInventoryEventService;
use App\Services\SaleProfitCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'inventory_status' => ['nullable', Rule::in(Product::INVENTORY_STATUSES)],
            'category_id' => ['nullable', 'integer'],
            'stale_days' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('furimadeck.inventory.max_filter_days')],
        ]);

        $products = $request->user()
            ->products()
            ->with(['category', 'images', 'listings.marketplace', 'sales.marketplace', 'validSales.marketplace'])
            ->withValidSaleFlag()
            ->when($filters['keyword'] ?? null, function ($query, string $keyword): void {
                $query->where(function ($nestedQuery) use ($keyword): void {
                    $nestedQuery
                        ->where('internal_sku', 'like', '%'.$keyword.'%')
                        ->orWhere('product_name', 'like', '%'.$keyword.'%')
                        ->orWhere('manufacturer_model_number', 'like', '%'.$keyword.'%');
                });
            })
            ->when($filters['inventory_status'] ?? null, fn ($query, string $status) => $query->where('inventory_status', $status))
            ->when($filters['category_id'] ?? null, fn ($query, int $categoryId) => $query->whereIn('category_id', $this->categoryIdsForFilter($categoryId)))
            ->when($filters['stale_days'] ?? null, function ($query, int $days): void {
                $threshold = now()->subDays($days)->toDateString();
                $query
                    ->where('quantity_available', '>', 0)
                    ->whereDoesntHave('sales', fn ($sales): object => $sales->whereIn('status', Sale::VALID_SOLD_STATUSES))
                    ->whereHas('listings', fn ($listing): object => $listing
                        ->whereIn('status', Listing::STALE_INVENTORY_STATUSES)
                        ->whereNotNull('listed_at')
                        ->whereDate('listed_at', '<=', $threshold));
            })
            ->latest()
            ->paginate((int) config('furimadeck.inventory.product_list_per_page'))
            ->withQueryString();

        return view('products.index-furupro-style', [
            'products' => $products,
            'filters' => $filters,
            'categories' => $this->activeCategories(),
            'marketplaces' => Marketplace::query()->where('is_active', true)->orderBy('name')->get(),
            'marketplaceFeeRates' => app(MarketplaceFeeService::class)->rates(Marketplace::query()->where('is_active', true)->orderBy('name')->get()),
            'longTermInventoryDays' => (int) config('furimadeck.inventory.long_term_days'),
        ]);
    }

    public function create(Request $request): View
    {
        return view('products.create', $this->formData($request));
    }

    public function categoryAttributes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => ['required', Rule::exists('product_categories', 'id')->where('is_active', true)],
        ]);

        return response()->json([
            'attributes' => CategoryAttribute::query()
                ->where('category_id', $validated['category_id'])
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'key', 'label', 'input_type', 'is_required', 'options_json']),
        ]);
    }

    public function showImage(Request $request, Product $product, ProductImage $image, string $variant): StreamedResponse
    {
        $this->ensureOwner($request, $product);
        abort_unless($image->product_id === $product->id, 404);

        $path = $variant === 'thumbnail' && $image->derived_path !== null
            ? $image->derived_path
            : $image->original_path;
        abort_unless($this->isProductImagePath($path, (int) $product->user_id), 404);

        $disk = $image->storageDisk();
        abort_unless(Storage::disk($disk)->exists($path), 404);

        $response = Storage::disk($disk)->response($path, basename($path), [
            'Content-Type' => $image->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store', true);

        return $response;
    }

    public function reorderImages(Request $request, Product $product): JsonResponse
    {
        $this->ensureOwner($request, $product);
        $validated = $request->validate(['image_ids' => ['required', 'array'], 'image_ids.*' => ['integer']]);
        $imageIds = array_map('intval', $validated['image_ids']);
        $ownedIds = $product->images()->pluck('id')->map(fn ($id) => (int) $id)->all();
        abort_unless(count($imageIds) === count($ownedIds) && array_diff($imageIds, $ownedIds) === [] && count(array_unique($imageIds)) === count($imageIds), 422);
        foreach ($imageIds as $position => $imageId) {
            ProductImage::query()->whereKey($imageId)->update(['position' => $position]);
        }

        return response()->json(['ok' => true]);
    }

    public function destroyImage(Request $request, Product $product, ProductImage $image, ProductImageDeletionOutbox $deletions): RedirectResponse
    {
        $this->ensureOwner($request, $product);
        abort_unless($image->product_id === $product->id, 404);
        $outboxIds = DB::transaction(function () use ($product, $image, $deletions): array {
            $lockedImage = ProductImage::query()
                ->whereKey($image->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->firstOrFail();
            $outboxIds = $deletions->enqueueImages([$lockedImage], (int) $product->user_id);
            $lockedImage->delete();

            return $outboxIds;
        });

        $cleanup = $deletions->process($outboxIds);
        if ($cleanup['pending'] > 0) {
            return back()->with('warning', '画像の削除要求を保存しました。保存先の一時エラーのため、削除処理を自動で再試行します。');
        }

        return back()->with('success', '商品画像を削除しました。');
    }

    public function store(Request $request, AuditLogger $auditLogger, ProductImageProcessor $imageProcessor, FurimaDeckEntitlements $entitlements, ProductImageDeletionOutbox $deletions): RedirectResponse
    {
        $validated = $this->validatedProduct($request);
        $uploadedImagePathsByDisk = [];

        try {
            $product = DB::connection('furimadeck')->transaction(function () use ($request, $validated, $imageProcessor, $entitlements, &$uploadedImagePathsByDisk): Product {
                $user = $request->user()->newQuery()->lockForUpdate()->findOrFail($request->user()->id);
                if (! $entitlements->canCreateProduct($user, $user->products()->count())) {
                    throw ValidationException::withMessages([
                        'product_limit' => 'Freeプランの商品登録上限に達しています。Premiumにすると登録数の制限がなくなります。',
                    ]);
                }
                $product = $request->user()->products()->create($this->productValues($validated));
                $this->storeAttributeValues($product, $validated['attributes'] ?? []);
                $this->storeImages($product, $request, $imageProcessor, $uploadedImagePathsByDisk);
                $this->storeInitialListing($request, $product, $validated);
                app(ProductInventoryEventService::class)->recordOpening(
                    $product,
                    $request->user(),
                    'product',
                    (int) $product->id,
                    now(),
                    '商品登録による初期在庫登録',
                );

                return $product;
            });
        } catch (Throwable $exception) {
            $this->deleteUploadedImageFiles($uploadedImagePathsByDisk, (int) $request->user()->id, $deletions);

            throw $exception;
        }
        $auditLogger->log($request->user(), Product::class, $product->id, 'product.created', null, $product->getAttributes(), $request->ip());

        return redirect()->route('products.index')->with('success', '商品を登録しました。出品情報は商品編集から確認・登録できます。');
    }

    public function edit(Request $request, Product $product): View
    {
        $this->ensureOwner($request, $product);

        return $this->productDetailView($request, $product);
    }

    public function show(Request $request, Product $product): View
    {
        $this->ensureOwner($request, $product);

        return $this->productDetailView($request, $product);
    }

    public function update(Request $request, Product $product, AuditLogger $auditLogger, ProductImageProcessor $imageProcessor, ProductImageDeletionOutbox $deletions, ListingProfitEstimator $profitEstimator, ProductAcquisitionInfoUpdateService $purchaseInfoUpdater): RedirectResponse
    {
        $this->ensureOwner($request, $product);
        $validated = $this->validatedProduct($request, $product);

        $before = $product->getAttributes();
        $uploadedImagePathsByDisk = [];
        try {
            DB::connection('furimadeck')->transaction(function () use ($request, $product, $validated, $imageProcessor, $profitEstimator, $purchaseInfoUpdater, $auditLogger, &$before, &$uploadedImagePathsByDisk): void {
                $lockedProduct = Product::query()
                    ->whereKey($product->id)
                    ->where('user_id', $request->user()->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $before = $lockedProduct->getAttributes();
                $beforePurchaseInfo = $lockedProduct->only([
                    'purchase_date', 'purchase_unit_cost', 'purchase_quantity', 'quantity_available',
                    'purchase_shipping_cost', 'other_purchase_expense', 'supplier_id', 'purchase_info_confirmed',
                ]);
                $beforePurchaseInfo['supplier_name'] = $lockedProduct->supplier?->name;

                $lockedProduct->update($this->productValues($validated, $lockedProduct));
                $purchaseInfoUpdater->afterProductUpdate(
                    $lockedProduct,
                    $request->user(),
                    $beforePurchaseInfo,
                    $request,
                    $profitEstimator,
                    $auditLogger,
                );
                $this->storeAttributeValues($lockedProduct, $validated['attributes'] ?? []);
                $this->storeImages($lockedProduct, $request, $imageProcessor, $uploadedImagePathsByDisk);
            });
        } catch (Throwable $exception) {
            $this->deleteUploadedImageFiles($uploadedImagePathsByDisk, (int) $product->user_id, $deletions);

            throw $exception;
        }
        $auditLogger->log($request->user(), Product::class, $product->id, 'product.updated', $before, $product->fresh()->getAttributes(), $request->ip());

        return redirect()->route('products.index')->with('success', '商品を更新しました。');
    }

    public function adjustInventory(Request $request, Product $product, ProductInventoryEventService $inventoryEvents, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureOwner($request, $product);
        $validated = $request->validate([
            'target_quantity' => ['required', 'integer', Rule::in([0, Product::UNITS_PER_PRODUCT_ID])],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'adjustment_token' => ['required', 'uuid'],
        ]);

        $event = DB::connection('furimadeck')->transaction(function () use ($request, $product, $validated, $inventoryEvents) {
            $lockedProduct = Product::query()
                ->whereKey($product->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedProduct->sales()->exists()) {
                throw ValidationException::withMessages([
                    'target_quantity' => '販売履歴がある商品の在庫は直接調整できません。販売取消または返品の操作を使用してください。',
                ]);
            }

            return $inventoryEvents->adjustToQuantity(
                $lockedProduct,
                (int) $validated['target_quantity'],
                (string) $validated['adjustment_token'],
                $request->user(),
                now(),
                trim($validated['reason']),
            );
        });

        if ($event === null) {
            return back()->with('success', '在庫状態に変更はありません。');
        }

        if ($event->wasRecentlyCreated) {
            $auditLogger->log(
                $request->user(),
                Product::class,
                $product->id,
                'product.inventory_adjusted',
                $event->before_state,
                $event->after_state,
                $request->ip(),
            );
        }

        return back()->with('success', '在庫状態を更新し、入出庫履歴に記録しました。');
    }

    public function destroy(Request $request, Product $product, AuditLogger $auditLogger, ProductImageDeletionOutbox $deletions): RedirectResponse
    {
        $this->ensureOwner($request, $product);
        $request->validate(['confirm_deletion' => ['accepted']]);
        if ($product->sales()->exists() || $product->accountingEntries()->exists()
            || $product->manualPurchaseReconciliations()->exists()) {
            return back()->with('error', '販売・会計履歴がある商品は、履歴を保護するため削除できません。CSV登録とは関係ありません。商品編集画面から在庫数を直接変更する機能もありません。');
        }

        $before = $product->getAttributes();
        $outboxIds = DB::transaction(function () use ($request, $product, $deletions): array {
            $lockedProduct = Product::query()
                ->whereKey($product->id)
                ->where('user_id', $request->user()->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedProduct->sales()->exists() || $lockedProduct->accountingEntries()->exists()
                || $lockedProduct->manualPurchaseReconciliations()->exists()) {
                throw ValidationException::withMessages([
                    'confirm_deletion' => '販売・会計履歴がある商品は削除できません。',
                ]);
            }

            $images = $lockedProduct->images()->get();
            $outboxIds = $deletions->enqueueImages($images, (int) $lockedProduct->user_id);
            $lockedProduct->delete();

            return $outboxIds;
        });
        $cleanup = $deletions->process($outboxIds);
        $auditLogger->log($request->user(), Product::class, $product->id, 'product.deleted', $before, null, $request->ip());

        return redirect()->route('products.index')->with(
            $cleanup['pending'] > 0 ? 'warning' : 'success',
            $cleanup['pending'] > 0
                ? '商品情報を削除しました。画像の削除処理は保存先の一時エラーにより自動再試行中です。'
                : '商品を削除しました。',
        );
    }

    private function productDetailView(Request $request, Product $product): View
    {
        $product->load(['images', 'attributeValues.categoryAttribute', 'listings.marketplace', 'supplier']);

        return view('products.edit', array_merge($this->formData($request, $product), [
            'product' => $product,
            'inventoryEvents' => $product->inventoryEvents()->orderByDesc('occurred_at')->limit(50)->get(),
            'canEditPurchaseInfo' => ! app(FurimaDeckPurchaseInfoLock::class)->isLocked($product),
            'canDelete' => ! $product->sales()->exists()
                && ! $product->accountingEntries()->exists()
                && ! $product->manualPurchaseReconciliations()->exists(),
        ]));
    }

    /** @return array<string, mixed> */
    private function formData(Request $request, ?Product $product = null): array
    {
        $categoryId = old('category_id', $product?->category_id);
        $marketplaces = Marketplace::query()->where('is_active', true)->orderBy('name')->get();

        return [
            'categories' => $this->activeCategories(),
            'marketplaces' => $marketplaces,
            'marketplaceFeeRates' => app(MarketplaceFeeService::class)->rates($marketplaces),
            'conditions' => Product::CONDITIONS,
            'inventoryStatuses' => Product::INVENTORY_STATUSES,
            'suppliers' => $request->user()->suppliers()->orderBy('name')->get(),
            'categoryAttributes' => $categoryId === null
                ? collect()
                : CategoryAttribute::query()
                    ->where('category_id', $categoryId)
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(),
        ];
    }

    /** @return array<string, mixed> */
    private function validatedProduct(Request $request, ?Product $product = null): array
    {
        $maxImages = (int) config('furimadeck.product_images.max_count');
        $maxImageSize = (int) config('furimadeck.product_images.max_size_kilobytes');
        $remainingImageSlots = max(0, $maxImages - ($product?->images()->count() ?? 0));
        $validated = $request->validate([
            'internal_sku' => [
                'required', 'string', 'max:80',
                Rule::unique(Product::class, 'internal_sku')->where('user_id', $request->user()->id)->ignore($product),
            ],
            'product_name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', Rule::exists('product_categories', 'id')->where('is_active', true)],
            'condition' => ['required', Rule::in(Product::CONDITIONS)],
            'description_base' => ['nullable', 'string', 'max:10000'],
            'initial_marketplace_id' => [
                'nullable',
                'integer',
                Rule::exists('marketplaces', 'id')->where('is_active', true),
                'required_with:initial_listing_price',
            ],
            'initial_listing_price' => ['nullable', 'integer', 'min:0', 'max:'.SaleProfitCalculator::MAX_UNSIGNED_AMOUNT, 'required_with:initial_marketplace_id'],
            'jan_ean' => ['nullable', 'string', 'max:64'],
            'isbn' => ['nullable', 'string', 'max:64'],
            'manufacturer_model_number' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'storage_location' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:10000'],
            'attributes' => ['nullable', 'array'],
            'attributes.*.id' => ['required_with:attributes', 'integer'],
            'attributes.*.value_text' => ['nullable', 'string', 'max:1000'],
            'images' => ['nullable', 'array', 'max:'.$remainingImageSlots],
            'images.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxImageSize],
        ]);

        $maximumAmount = SaleProfitCalculator::MAX_UNSIGNED_AMOUNT;
        $purchaseValues = $request->validate([
            'purchase_date' => ['nullable', 'date'],
            'purchase_unit_cost' => ['nullable', 'integer', 'min:0', 'max:'.$maximumAmount],
            'purchase_shipping_cost' => ['nullable', 'integer', 'min:0', 'max:'.$maximumAmount],
            'other_purchase_expense' => ['nullable', 'integer', 'min:0', 'max:'.$maximumAmount],
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('user_id', $request->user()->id)],
        ]);
        $validated = array_merge($validated, $purchaseValues);

        if ($product === null) {
            $validated['purchase_info_confirmed'] = array_key_exists('purchase_unit_cost', $purchaseValues)
                && $purchaseValues['purchase_unit_cost'] !== null;
            $validated['purchase_date'] = $purchaseValues['purchase_date'] ?? null;
            $validated['purchase_unit_cost'] = (int) ($purchaseValues['purchase_unit_cost'] ?? 0);
            $validated['purchase_shipping_cost'] = (int) ($purchaseValues['purchase_shipping_cost'] ?? 0);
            $validated['other_purchase_expense'] = (int) ($purchaseValues['other_purchase_expense'] ?? 0);
            $validated['supplier_id'] = $purchaseValues['supplier_id'] ?? null;
        } elseif (array_key_exists('purchase_unit_cost', $purchaseValues)) {
            $validated['purchase_info_confirmed'] = $purchaseValues['purchase_unit_cost'] !== null;
            $validated['purchase_date'] = $purchaseValues['purchase_date'] ?? null;
            $validated['purchase_unit_cost'] = (int) ($purchaseValues['purchase_unit_cost'] ?? 0);
            $validated['purchase_shipping_cost'] = (int) ($purchaseValues['purchase_shipping_cost'] ?? 0);
            $validated['other_purchase_expense'] = (int) ($purchaseValues['other_purchase_expense'] ?? 0);
            $validated['supplier_id'] = $purchaseValues['supplier_id'] ?? null;
        }

        $this->validateAttributeOwnership($validated);

        $validated['purchase_quantity'] = $product?->purchase_quantity ?? Product::UNITS_PER_PRODUCT_ID;
        $validated['quantity_available'] = $product?->quantity_available ?? Product::UNITS_PER_PRODUCT_ID;

        return $validated;
    }

    /** @param array<string, mixed> $validated */
    private function validateAttributeOwnership(array $validated): void
    {
        $attributes = $validated['attributes'] ?? [];

        if ($attributes === []) {
            return;
        }

        $attributeIds = collect($attributes)->pluck('id')->map(fn ($id) => (int) $id)->unique();
        $matchingAttributes = CategoryAttribute::query()
            ->where('category_id', $validated['category_id'] ?? null)
            ->where('is_active', true)
            ->whereIn('id', $attributeIds)
            ->count();

        abort_unless($matchingAttributes === $attributeIds->count(), 422, '選択したカテゴリに属さない属性が含まれています。');
    }

    /** @param array<string, mixed> $validated */
    private function productValues(array $validated, ?Product $product = null): array
    {
        $values = collect($validated)
            ->except(['attributes', 'images', 'inventory_status', 'initial_marketplace_id', 'initial_listing_price', 'purchase_document_reviewed']);

        if ($product !== null) {
            // Route binding and validation happen before the transaction. Preserve the
            // freshly locked inventory state instead of writing a stale form snapshot.
            $values
                ->put('purchase_quantity', (int) $product->purchase_quantity)
                ->put('quantity_available', (int) $product->quantity_available);
        }

        return $values
            ->put('inventory_status', Product::inventoryStatusForQuantity((int) $values['quantity_available']))
            ->put('purchase_info_confirmed', (bool) ($validated['purchase_info_confirmed'] ?? $product?->purchase_info_confirmed ?? false))
            ->all();
    }

    /** @param array<string, mixed> $validated */
    private function storeInitialListing(Request $request, Product $product, array $validated): void
    {
        if (! isset($validated['initial_marketplace_id'], $validated['initial_listing_price'])) {
            return;
        }

        $marketplace = Marketplace::query()
            ->whereKey($validated['initial_marketplace_id'])
            ->where('is_active', true)
            ->firstOrFail();
        $feeRate = app(MarketplaceFeeService::class)->rate($marketplace);
        $request->user()->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => $product->product_name,
            'listing_description' => $product->description_base,
            'listing_price' => (int) $validated['initial_listing_price'],
            'listing_quantity' => Product::UNITS_PER_PRODUCT_ID,
            'expected_fee_rate' => $feeRate,
            'expected_profit' => null,
            'expected_margin' => null,
            'status' => 'draft',
        ]);
    }

    /** @param array<int, array{id: int, value_text?: string|null}> $attributes */
    private function storeAttributeValues(Product $product, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            $product->attributeValues()->updateOrCreate(
                ['category_attribute_id' => $attribute['id']],
                ['value_text' => $attribute['value_text'] ?? null],
            );
        }
    }

    /** @param array<string, array<int, string>> $uploadedPathsByDisk */
    private function storeImages(Product $product, Request $request, ProductImageProcessor $imageProcessor, array &$uploadedPathsByDisk): void
    {
        $files = $request->file('images', []);
        $position = (int) $product->images()->max('position') + 1;
        $storageDisk = ProductImage::privateStorageDisk();

        foreach ($files as $file) {
            $image = $imageProcessor->process($file, (int) $product->user_id);
            $uploadedPathsByDisk[$storageDisk][] = $image['original_path'];
            $uploadedPathsByDisk[$storageDisk][] = $image['derived_path'];

            $product->images()->create([
                ...$image,
                'storage_disk' => $storageDisk,
                'position' => $position++,
            ]);
        }
    }

    /** @param array<string, array<int, string>> $pathsByDisk */
    private function deleteUploadedImageFiles(array $pathsByDisk, int $userId, ProductImageDeletionOutbox $deletions): void
    {
        try {
            $ids = $deletions->enqueuePathsByDisk($pathsByDisk, $userId);
            $deletions->process($ids);
        } catch (Throwable) {
            Log::warning('Product image cleanup could not be queued after a database rollback.', [
                'disk_count' => count($pathsByDisk),
            ]);
        }
    }

    private function isProductImagePath(string $path, int $userId): bool
    {
        return str_starts_with($path, 'products/'.$userId.'/') && ! str_contains($path, '..');
    }

    private function ensureOwner(Request $request, Product $product): void
    {
        abort_unless($product->user_id === $request->user()->id, 404);
    }

    private function activeCategories()
    {
        return ProductCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /** @return array<int, int> */
    private function categoryIdsForFilter(int $categoryId): array
    {
        ProductCategory::query()->findOrFail($categoryId);

        $categoryIds = [(int) $categoryId];
        $parentIds = $categoryIds;

        while ($parentIds !== []) {
            $childIds = ProductCategory::query()
                ->where('is_active', true)
                ->whereIn('parent_id', $parentIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $childIds = array_values(array_diff($childIds, $categoryIds));

            if ($childIds === []) {
                break;
            }

            $categoryIds = [...$categoryIds, ...$childIds];
            $parentIds = $childIds;
        }

        return $categoryIds;
    }
}
