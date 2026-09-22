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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'keyword' => ['nullable', 'string', 'max:255'],
            'inventory_status' => ['nullable', Rule::in(Product::INVENTORY_STATUSES)],
            'category_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'stale_days' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('furimadeck.inventory.max_filter_days')],
        ]);

        $products = $request->user()
            ->products()
            ->with(['category', 'supplier', 'images', 'listings.marketplace', 'validSales.marketplace'])
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
            ->when($filters['supplier_id'] ?? null, fn ($query, int $supplierId) => $query->where('supplier_id', $supplierId))
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
            'suppliers' => $request->user()->suppliers()->orderBy('name')->get(),
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

    public function showImage(Request $request, Product $product, ProductImage $image, string $variant): BinaryFileResponse
    {
        $this->ensureOwner($request, $product);
        abort_unless($image->product_id === $product->id, 404);

        $path = $variant === 'thumbnail' && $image->derived_path !== null
            ? $image->derived_path
            : $image->original_path;
        abort_unless($this->isProductImagePath($path, (int) $product->user_id), 404);

        $disk = $image->storageDisk();
        abort_unless(Storage::disk($disk)->exists($path), 404);

        $response = response()->file(Storage::disk($disk)->path($path), [
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
        $paths = array_filter([$image->original_path, $image->derived_path]);
        $image->delete();
        Storage::disk($image->storageDisk())->delete($paths);

        return back()->with('success', '商品画像を削除しました。');
    }

    public function store(Request $request, AuditLogger $auditLogger, ProductImageProcessor $imageProcessor, FurimaDeckEntitlements $entitlements): RedirectResponse
    {
        $validated = $this->validatedProduct($request);

        $product = DB::transaction(function () use ($request, $validated, $imageProcessor, $entitlements): Product {
            $user = $request->user()->newQuery()->lockForUpdate()->findOrFail($request->user()->id);
            if (! $entitlements->canCreateProduct($user, $user->products()->count())) {
                throw ValidationException::withMessages([
                    'product_limit' => 'Freeプランの商品登録上限に達しています。Premiumにすると登録数の制限がなくなります。',
                ]);
            }
            $product = $request->user()->products()->create($this->productValues($validated));
            $this->storeAttributeValues($product, $validated['attributes'] ?? []);
            $this->storeImages($product, $request, $imageProcessor);

            return $product;
        });
        $auditLogger->log($request->user(), Product::class, $product->id, 'product.created', null, $product->getAttributes(), $request->ip());

        return redirect()->route('products.index')->with('success', '商品を登録しました。');
    }

    public function edit(Request $request, Product $product): View
    {
        $this->ensureOwner($request, $product);

        return view('products.edit', array_merge($this->formData($request, $product), [
            'product' => $product->load(['images', 'attributeValues.categoryAttribute', 'validSales.marketplace']),
        ]));
    }

    public function update(Request $request, Product $product, AuditLogger $auditLogger, ProductImageProcessor $imageProcessor): RedirectResponse
    {
        $this->ensureOwner($request, $product);
        $validated = $this->validatedProduct($request, $product);

        $before = $product->getAttributes();
        DB::transaction(function () use ($request, $product, $validated, $imageProcessor): void {
            $product->update($this->productValues($validated));
            $this->syncSaleFromProduct($product, $validated['sale'] ?? []);
            $this->storeAttributeValues($product, $validated['attributes'] ?? []);
            $this->storeImages($product, $request, $imageProcessor);
        });
        $auditLogger->log($request->user(), Product::class, $product->id, 'product.updated', $before, $product->fresh()->getAttributes(), $request->ip());

        return redirect()->route('products.index')->with('success', '商品を更新しました。');
    }

    public function destroy(Request $request, Product $product, AuditLogger $auditLogger): RedirectResponse
    {
        $this->ensureOwner($request, $product);
        $request->validate(['confirm_deletion' => ['accepted']]);
        if ($product->listings()->exists() || $product->sales()->exists()) {
            return back()->with('error', '出品または販売履歴がある商品は削除できません。履歴を保護するため、在庫状態を変更してください。');
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

        return [
            'categories' => $this->activeCategories(),
            'suppliers' => $request->user()->suppliers()->orderBy('name')->get(),
            'marketplaces' => Marketplace::query()->where('is_active', true)->orderBy('name')->get(),
            'marketplaceFeeRates' => app(MarketplaceFeeService::class)->rates(Marketplace::query()->where('is_active', true)->orderBy('name')->get()),
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
            'purchase_date' => ['nullable', 'date'],
            'purchase_unit_cost' => ['required', 'integer', 'min:0'],
            'purchase_shipping_cost' => ['nullable', 'integer', 'min:0'],
            'other_purchase_expense' => ['nullable', 'integer', 'min:0'],
            'supplier_id' => [
                'nullable',
                Rule::exists('suppliers', 'id')->where('user_id', $request->user()->id),
            ],
            'jan_ean' => ['nullable', 'string', 'max:64'],
            'isbn' => ['nullable', 'string', 'max:64'],
            'manufacturer_model_number' => ['nullable', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'storage_location' => ['nullable', 'string', 'max:255'],
            'memo' => ['nullable', 'string', 'max:10000'],
            'sale' => ['nullable', 'array'],
            'sale.sold_price' => ['nullable', 'integer', 'min:0'],
            'sale.marketplace_id' => ['nullable', 'integer', Rule::exists('marketplaces', 'id')->where('is_active', true), 'required_with:sale.sold_price'],
            'sale.sales_fee_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'required_with:sale.sold_price'],
            'sale.shipping_fee' => ['nullable', 'integer', 'min:0'],
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
    private function productValues(array $validated): array
    {
        return collect($validated)
            ->except(['attributes', 'images', 'inventory_status', 'sale'])
            ->map(fn (mixed $value, string $key): mixed => in_array($key, ['purchase_shipping_cost', 'other_purchase_expense'], true) && $value === null ? 0 : $value)
            ->put('inventory_status', Product::inventoryStatusForQuantity((int) $validated['quantity_available']))
            ->all();
    }

    /** @param array<string, mixed> $values */
    private function syncSaleFromProduct(Product $product, array $values): void
    {
        if (! array_key_exists('sold_price', $values) || $values['sold_price'] === null || $values['sold_price'] === '') {
            return;
        }

        $marketplace = Marketplace::query()->whereKey($values['marketplace_id'])->where('is_active', true)->firstOrFail();

        $soldPrice = (int) $values['sold_price'];
        $feeRate = (float) $values['sales_fee_rate'];
        $shippingFee = (int) ($values['shipping_fee'] ?? 0);
        $salesFee = (int) floor($soldPrice * $feeRate / 100);
        $costBasis = (int) $product->purchase_unit_cost;
        $profit = $soldPrice - $costBasis - $salesFee - $shippingFee;
        $saleValues = [
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_price' => $soldPrice,
            'sold_at' => now(),
            'status' => Sale::STATUSES[3],
            'cost_basis' => $costBasis,
            'sales_fee' => $salesFee,
            'sales_fee_rate' => $feeRate,
            'shipping_fee' => $shippingFee,
            'net_profit' => $profit,
        ];
        $sale = $product->validSales()->first();
        if ($sale === null) {
            $product->sales()->create(['user_id' => $product->user_id, ...$saleValues]);
        } else {
            $sale->update($saleValues);
        }

        $product->update(['quantity_available' => 0]);
        $product->listings()->whereIn('status', ['ready', 'active'])->update(['status' => 'sold', 'ended_at' => now()]);
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

    private function storeImages(Product $product, Request $request, ProductImageProcessor $imageProcessor): void
    {
        $files = $request->file('images', []);
        $position = (int) $product->images()->max('position') + 1;
        $createdPaths = [];

        try {
            foreach ($files as $file) {
                $image = $imageProcessor->process($file, (int) $product->user_id);
                $createdPaths[] = $image['original_path'];
                $createdPaths[] = $image['derived_path'];

                $product->images()->create([
                    ...$image,
                    'storage_disk' => ProductImage::PRIVATE_STORAGE_DISK,
                    'position' => $position++,
                ]);
            }
        } catch (Throwable $exception) {
            Storage::disk(ProductImage::PRIVATE_STORAGE_DISK)->delete(array_filter($createdPaths));

            throw $exception;
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
                Storage::disk($disk)->delete($images->flatMap(fn (ProductImage $image) => [$image->original_path, $image->derived_path])->filter()->all());
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
