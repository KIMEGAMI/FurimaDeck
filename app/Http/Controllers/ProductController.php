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
use App\Services\MarketplaceFeeService;
use App\Services\ProductImageProcessor;
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

    public function destroyImage(Request $request, Product $product, ProductImage $image): RedirectResponse
    {
        $this->ensureOwner($request, $product);
        abort_unless($image->product_id === $product->id, 404);
        $files = DB::transaction(function () use ($product, $image): array {
            $lockedImage = ProductImage::query()
                ->whereKey($image->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->firstOrFail();
            $disk = $lockedImage->storageDisk();
            $paths = array_values(array_filter([$lockedImage->original_path, $lockedImage->derived_path]));
            $lockedImage->delete();

            return ['disk' => $disk, 'paths' => $paths];
        });

        try {
            $deleted = Storage::disk($files['disk'])->delete($files['paths']);
        } catch (Throwable) {
            $deleted = false;
        }
        if (! $deleted) {
            Log::warning('Product image cleanup failed after database deletion.', [
                'disk' => $files['disk'],
                'file_count' => count($files['paths']),
            ]);
        }

        return back()->with('success', '商品画像を削除しました。');
    }

    public function store(Request $request, AuditLogger $auditLogger, ProductImageProcessor $imageProcessor, FurimaDeckEntitlements $entitlements): RedirectResponse
    {
        $validated = $this->validatedProduct($request);
        $uploadedImagePathsByDisk = [];

        try {
            $product = DB::transaction(function () use ($request, $validated, $imageProcessor, $entitlements, &$uploadedImagePathsByDisk): Product {
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

                return $product;
            });
        } catch (Throwable $exception) {
            $this->deleteUploadedImageFiles($uploadedImagePathsByDisk);

            throw $exception;
        }
        $auditLogger->log($request->user(), Product::class, $product->id, 'product.created', null, $product->getAttributes(), $request->ip());

        return redirect()->route('products.index')->with('success', '商品を登録しました。出品情報は商品編集から確認・登録できます。');
    }

    public function edit(Request $request, Product $product): View
    {
        $this->ensureOwner($request, $product);

        return view('products.edit', array_merge($this->formData($request, $product), [
            'product' => $product->load(['images', 'attributeValues.categoryAttribute', 'listings.marketplace']),
        ]));
    }

    public function update(Request $request, Product $product, AuditLogger $auditLogger, ProductImageProcessor $imageProcessor): RedirectResponse
    {
        $this->ensureOwner($request, $product);
        $validated = $this->validatedProduct($request, $product);

        $before = $product->getAttributes();
        $uploadedImagePathsByDisk = [];
        try {
            DB::transaction(function () use ($request, $product, $validated, $imageProcessor, &$uploadedImagePathsByDisk): void {
                $product->update($this->productValues($validated, $product));
                $this->storeAttributeValues($product, $validated['attributes'] ?? []);
                $this->storeImages($product, $request, $imageProcessor, $uploadedImagePathsByDisk);
            });
        } catch (Throwable $exception) {
            $this->deleteUploadedImageFiles($uploadedImagePathsByDisk);

            throw $exception;
        }
        $auditLogger->log($request->user(), Product::class, $product->id, 'product.updated', $before, $product->fresh()->getAttributes(), $request->ip());

        return redirect()->route('products.index')->with('success', '商品を更新しました。');
    }

    public function destroy(Request $request, Product $product, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureOwner($request, $product);
        $request->validate(['confirm_deletion' => ['accepted']]);
        if ($product->listings()->exists() || $product->sales()->exists() || $product->accountingEntries()->exists()
            || $product->manualPurchaseReconciliations()->exists()) {
            return back()->with('error', '出品・販売・会計履歴がある商品は削除できません。履歴を保護するため、在庫状態を変更してください。');
        }

        $before = $product->getAttributes();
        $images = $product->images()->get();
        DB::transaction(fn () => $product->delete());
        $this->deleteImageFiles($images);
        $auditLogger->log($request->user(), Product::class, $product->id, 'product.deleted', $before, null, $request->ip());

        return redirect()->route('products.index')->with('success', '商品を削除しました。');
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

        $this->validateAttributeOwnership($validated);

        $validated['purchase_quantity'] = $product?->purchase_quantity ?? 1;
        $validated['quantity_available'] = $product?->quantity_available ?? 1;

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
        return collect($validated)
            ->except(['attributes', 'images', 'inventory_status', 'initial_marketplace_id', 'initial_listing_price', 'purchase_document_reviewed'])
            ->put('inventory_status', Product::inventoryStatusForQuantity((int) $validated['quantity_available']))
            ->put('purchase_info_confirmed', (bool) ($product?->purchase_info_confirmed ?? false))
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
            'listing_quantity' => 1,
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
    private function deleteUploadedImageFiles(array $pathsByDisk): void
    {
        foreach ($pathsByDisk as $disk => $paths) {
            $files = array_values(array_unique(array_filter($paths)));
            $deleted = false;

            try {
                $deleted = Storage::disk($disk)->delete($files);
            } catch (Throwable) {
                // Keep the original transaction failure as the reported error.
            }

            if (! $deleted) {
                Log::warning('Product image cleanup failed after a database rollback.', [
                    'disk' => $disk,
                    'file_count' => count($files),
                ]);
            }
        }
    }

    private function isProductImagePath(string $path, int $userId): bool
    {
        return str_starts_with($path, 'products/'.$userId.'/') && ! str_contains($path, '..');
    }

    /** @param iterable<ProductImage> $images */
    private function deleteImageFiles(iterable $images): void
    {
        collect($images)
            ->groupBy(fn (ProductImage $image) => $image->storageDisk())
            ->each(function ($images, string $disk): void {
                $files = $images->flatMap(fn (ProductImage $image) => [$image->original_path, $image->derived_path])
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                $deleted = false;

                try {
                    $deleted = Storage::disk($disk)->delete($files);
                } catch (Throwable) {
                    // Product deletion has already committed; leave cleanup visible in logs.
                }

                if (! $deleted) {
                    Log::warning('Product image cleanup failed after product deletion.', [
                        'disk' => $disk,
                        'file_count' => count($files),
                    ]);
                }
            });
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
