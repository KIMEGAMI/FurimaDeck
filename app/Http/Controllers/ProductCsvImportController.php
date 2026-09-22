<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\Listing;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FurimaDeckExternalSalesImportService;
use App\Services\FurimaDeckRestoreCsvService;
use App\Services\MarketplaceFeeService;
use App\Services\ProductCsvPreviewService;
use App\Services\SaleProfitCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

class ProductCsvImportController extends Controller
{
    public function create(): View
    {
        return view('product_imports.create');
    }

    public function preview(Request $request, ProductCsvPreviewService $previewService, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:'.(int) config('furimadeck.csv_import.max_size_kilobytes')],
        ]);
        try {
            $preview = $previewService->preview($validated['csv_file']);
        } catch (RuntimeException $exception) {
            return back()
                ->withInput()
                ->withErrors(['csv_file' => $exception->getMessage()]);
        }
        $validRows = collect($preview['rows'])->where('errors', [])->count();
        $invalidRows = count($preview['rows']) - $validRows;

        $batch = DB::transaction(function () use ($request, $validated, $preview, $validRows, $invalidRows): ImportBatch {
            $batch = new ImportBatch([
                'batch_uuid' => (string) Str::uuid(),
                'filename' => basename(str_replace('\\', '/', $validated['csv_file']->getClientOriginalName())),
                'format' => 'furimadeck_product_csv',
                'total_rows' => count($preview['rows']),
                'success_rows' => $validRows,
                'failed_rows' => $invalidRows,
                'status' => 'preview',
                'started_at' => now(),
            ]);
            $batch->user_id = $request->user()->id;
            $batch->save();

            foreach ($preview['rows'] as $row) {
                $batch->rowResults()->create([
                    'row_number' => $row['row_number'],
                    'row_json' => $row['values'],
                    'errors_json' => $row['errors'] === [] ? null : $row['errors'],
                    'is_valid' => $row['errors'] === [],
                ]);
            }

            return $batch;
        });
        $auditLogger->log($request->user(), ImportBatch::class, $batch->id, 'product_csv.previewed', null, [
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->success_rows,
            'invalid_rows' => $batch->failed_rows,
        ], $request->ip());

        return redirect()->route('products.imports.show', $batch);
    }

    public function restorePreview(Request $request, FurimaDeckRestoreCsvService $restoreService, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'csv_file' => ['required', 'file', 'mimes:csv,txt', 'max:'.(int) config('furimadeck.csv_import.max_size_kilobytes')],
        ]);
        try {
            $preview = $restoreService->preview($request->user(), $validated['csv_file']);
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors(['csv_file' => $exception->getMessage()]);
        }
        $validRows = collect($preview['rows'])->where('errors', [])->count();
        $invalidRows = count($preview['rows']) - $validRows;
        $batch = DB::transaction(function () use ($request, $validated, $preview, $validRows, $invalidRows): ImportBatch {
            $batch = new ImportBatch([
                'batch_uuid' => (string) Str::uuid(),
                'filename' => basename(str_replace('\\', '/', $validated['csv_file']->getClientOriginalName())),
                'original_filename' => $validated['csv_file']->getClientOriginalName(),
                'format' => 'furimadeck_restore_csv',
                'total_rows' => count($preview['rows']),
                'success_rows' => $validRows,
                'skipped_rows' => 0,
                'failed_rows' => $invalidRows,
                'status' => 'preview',
                'started_at' => now(),
            ]);
            $batch->user_id = $request->user()->id;
            $batch->save();
            foreach ($preview['rows'] as $row) {
                $batch->rowResults()->create([
                    'row_number' => $row['row_number'],
                    'row_json' => $row['values'],
                    'errors_json' => $row['errors'] === [] ? null : $row['errors'],
                    'is_valid' => $row['errors'] === [],
                ]);
            }

            return $batch;
        });
        $auditLogger->log($request->user(), ImportBatch::class, $batch->id, 'restore_csv.previewed', null, ['total_rows' => $batch->total_rows, 'invalid_rows' => $batch->failed_rows], $request->ip());

        return redirect()->route('products.imports.show', $batch);
    }

    public function show(Request $request, ImportBatch $importBatch): View
    {
        abort_unless($importBatch->user_id === $request->user()->id, 404);

        return view('product_imports.show', [
            'batch' => $importBatch,
            'rows' => $importBatch->rowResults()->orderBy('row_number')->paginate((int) config('furimadeck.csv_import.preview_rows_per_page')),
        ]);
    }

    public function commit(Request $request, ImportBatch $importBatch, AuditLogger $auditLogger): RedirectResponse
    {
        abort_unless($importBatch->user_id === $request->user()->id, 404);
        abort_unless($importBatch->status === 'preview' && $importBatch->failed_rows === 0, 422);

        $rows = $importBatch->rowResults()->where('is_valid', true)->orderBy('row_number')->get();
        if (str_starts_with($importBatch->format, 'furimadeck_external_')) {
            $format = str_replace('furimadeck_external_', '', $importBatch->format);
            try {
                $result = DB::transaction(function () use ($request, $importBatch, $rows, $format): array {
                    $batch = ImportBatch::query()->whereKey($importBatch->id)->lockForUpdate()->firstOrFail();
                    abort_unless($batch->user_id === $request->user()->id && $batch->status === 'preview' && $batch->failed_rows === 0, 422);
                    $service = app(FurimaDeckExternalSalesImportService::class);
                    $imported = 0;
                    $provisional = 0;
                    $skipped = (int) $batch->skipped_rows;
                    foreach ($rows as $row) {
                        $rowResult = $service->commitParsed($request->user(), $row->row_json, $format);
                        if ($rowResult === 'skipped') {
                            $skipped++;
                        } elseif ($rowResult === 'provisional') {
                            $provisional++;
                        } else {
                            $imported++;
                        }
                    }
                    $batch->update(['success_rows' => $imported, 'skipped_rows' => $skipped, 'status' => 'completed', 'completed_at' => now()]);

                    return ['imported' => $imported, 'provisional' => $provisional, 'skipped' => $skipped];
                });
            } catch (RuntimeException $exception) {
                return back()->withErrors(['csv_file' => $exception->getMessage()]);
            }
            $auditLogger->log($request->user(), ImportBatch::class, $importBatch->id, 'external_sales.completed', null, $result, $request->ip());

            return redirect()->route('furimadeck-sales.index')->with('success', '外部売上CSVを確定しました。登録 '.$result['imported'].'件 / 要確認 '.$result['provisional'].'件 / スキップ '.$result['skipped'].'件');
        }
        if ($importBatch->format === 'furimadeck_restore_csv') {
            try {
                $result = DB::transaction(function () use ($request, $importBatch, $rows): array {
                    $batch = ImportBatch::query()->whereKey($importBatch->id)->lockForUpdate()->firstOrFail();
                    abort_unless($batch->user_id === $request->user()->id && $batch->status === 'preview' && $batch->failed_rows === 0, 422);
                    $imported = 0;
                    $skipped = 0;
                    $restoreService = app(FurimaDeckRestoreCsvService::class);
                    foreach ($rows as $row) {
                        if ($restoreService->commit($request->user(), $row->row_json) === 'skipped') {
                            $skipped++;
                        } else {
                            $imported++;
                        }
                    }
                    $batch->update(['success_rows' => $imported, 'skipped_rows' => $skipped, 'status' => 'completed', 'completed_at' => now()]);

                    return ['imported' => $imported, 'skipped' => $skipped];
                });
            } catch (RuntimeException $exception) {
                return back()->withErrors(['csv_file' => $exception->getMessage()]);
            }
            $auditLogger->log($request->user(), ImportBatch::class, $importBatch->id, 'restore_csv.completed', null, $result, $request->ip());

            return redirect()->route('products.index')->with('success', $result['imported'].'件を復元しました。重複スキップ: '.$result['skipped'].'件');
        }

        $existingSkus = Product::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('internal_sku', $rows->map(fn ($row) => $row->row_json['internal_sku'])->all())
            ->pluck('internal_sku');
        if ($existingSkus->isNotEmpty()) {
            return back()->withErrors(['csv_file' => '登録済みの商品IDが含まれています: '.$existingSkus->implode(', ')]);
        }

        $categoryIds = $rows->pluck('row_json')->pluck('category_id')->filter(fn (mixed $id): bool => (string) $id !== '')->map(fn (string $id): int => (int) $id)->unique()->values();
        if ($categoryIds->isNotEmpty() && ProductCategory::query()->whereIn('id', $categoryIds)->count() !== $categoryIds->count()) {
            return back()->withErrors(['csv_file' => '存在しないカテゴリIDが含まれています。']);
        }
        $supplierIds = $rows->pluck('row_json')->pluck('supplier_id')->filter(fn (mixed $id): bool => (string) $id !== '')->map(fn (string $id): int => (int) $id)->unique()->values();
        if ($supplierIds->isNotEmpty() && Supplier::query()->where('user_id', $request->user()->id)->whereIn('id', $supplierIds)->count() !== $supplierIds->count()) {
            return back()->withErrors(['csv_file' => '自分の仕入先ではないIDが含まれています。']);
        }

        try {
            $result = DB::transaction(function () use ($request, $importBatch, $rows): array {
                $batch = ImportBatch::query()->whereKey($importBatch->id)->lockForUpdate()->firstOrFail();
                abort_unless($batch->user_id === $request->user()->id && $batch->status === 'preview' && $batch->failed_rows === 0, 422);

                $createdListings = 0;
                $createdSales = 0;
                foreach ($rows as $row) {
                    $values = $row->row_json;
                    $values['category_id'] = $this->categoryId($values);
                    $product = $request->user()->products()->create($this->productValues($values));
                    if (($values['listed_at'] ?? '') !== '') {
                        $listing = $this->createListingFromCsv($request->user(), $product, $values);
                        $createdListings++;
                        if (($values['sale_state'] ?? 'stock') === 'sold') {
                            $this->createSaleFromCsv($request->user(), $product, $listing, $values);
                            $createdSales++;
                        }
                    }
                }

                $batch->update(['status' => 'completed', 'completed_at' => now()]);

                return [
                    'created_products' => $rows->count(),
                    'created_listings' => $createdListings,
                    'created_sales' => $createdSales,
                ];
            });
        } catch (RuntimeException $exception) {
            return back()->withErrors(['csv_file' => $exception->getMessage()]);
        } catch (QueryException) {
            return back()->withErrors(['csv_file' => 'CSVの登録に失敗しました。商品IDの重複がないか確認して、もう一度お試しください。']);
        }

        $auditLogger->log($request->user(), ImportBatch::class, $importBatch->id, 'product_csv.completed', null, [
            ...$result,
        ], $request->ip());

        return redirect()->route('products.index')->with('success', $result['created_products'].'件の商品、'.$result['created_listings'].'件の出品、'.$result['created_sales'].'件の売上を登録しました。');
    }

    /** @param array<string,mixed> $values */
    private function createListingFromCsv(User $user, Product $product, array $values): Listing
    {
        $marketplace = $this->marketplaceFromCsv((string) ($values['marketplace_code'] ?? ''));
        $listedAt = CarbonImmutable::parse((string) $values['listed_at'])->startOfDay();
        $isSold = ($values['sale_state'] ?? 'stock') === 'sold';

        return $user->listings()->create([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'listing_title' => $product->product_name,
            'listing_description' => $product->description_base,
            'listing_price' => (int) $values['listing_price'],
            'listing_quantity' => 1,
            'status' => $isSold ? 'sold' : 'active',
            'listed_at' => $listedAt,
            'ended_at' => $isSold ? CarbonImmutable::parse((string) $values['sold_at'])->startOfDay() : null,
        ]);
    }

    /** @param array<string,mixed> $values */
    private function createSaleFromCsv(User $user, Product $product, Listing $listing, array $values): void
    {
        $marketplace = $listing->marketplace;
        $quantity = (int) $values['sale_quantity'];
        $soldAt = CarbonImmutable::parse((string) $values['sold_at'])->startOfDay();
        $feeService = app(MarketplaceFeeService::class);
        $salesFee = ($values['sales_fee'] ?? '') !== ''
            ? (int) $values['sales_fee']
            : (($values['sales_fee_rate'] ?? '') !== ''
                ? (int) floor((int) ($values['sold_price'] ?? 0) * ((float) $values['sales_fee_rate'] / 100))
                : $feeService->amount((int) ($values['sold_price'] ?? 0), $marketplace));
        $amounts = [
            'sold_price' => (int) ($values['sold_price'] ?? 0),
            'cost_basis' => (int) $product->purchase_unit_cost * $quantity,
            'sales_fee' => $salesFee,
            'shipping_fee' => (int) ($values['shipping_fee'] ?? 0),
            'purchase_shipping_cost' => (int) ($values['sale_purchase_shipping_cost'] ?? 0),
            'packing_cost' => (int) ($values['packing_cost'] ?? 0),
            'repair_cost' => (int) ($values['repair_cost'] ?? 0),
            'cleaning_cost' => (int) ($values['cleaning_cost'] ?? 0),
            'other_expense' => (int) ($values['sale_other_expense'] ?? 0),
        ];
        $user->sales()->create([
            ...$amounts,
            'product_id' => $product->id,
            'listing_id' => $listing->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => $quantity,
            'sold_at' => $soldAt,
            'status' => 'completed',
            'completed_at' => $soldAt,
            'net_profit' => app(SaleProfitCalculator::class)->calculate($amounts),
        ]);
    }

    private function marketplaceFromCsv(string $marketplaceCode): Marketplace
    {
        $marketplaceCode = trim($marketplaceCode);
        $marketplace = Marketplace::query()->where('code', $marketplaceCode)->first();
        if ($marketplace !== null) {
            if (! $marketplace->is_active) {
                throw new RuntimeException('marketplace_codeに対応する出品先が無効化されています: '.$marketplaceCode);
            }

            return $marketplace;
        }

        $marketplaceDefinitions = [
            'mercari' => ['name' => 'メルカリ', 'base_url' => 'https://jp.mercari.com/'],
            'yahoo_flea_market' => ['name' => 'Yahoo!フリマ', 'base_url' => 'https://paypayfleamarket.yahoo.co.jp/'],
            'yahoo_auctions' => ['name' => 'Yahoo!オークション', 'base_url' => 'https://auctions.yahoo.co.jp/'],
            'rakuma' => ['name' => 'ラクマ', 'base_url' => 'https://fril.jp/'],
            'other' => ['name' => 'その他', 'base_url' => 'https://example.invalid/'],
        ];
        $definition = $marketplaceDefinitions[$marketplaceCode] ?? null;
        if ($definition === null) {
            throw new RuntimeException('marketplace_codeに対応する有効な出品先がありません: '.$marketplaceCode);
        }

        return Marketplace::query()->create([
            'code' => $marketplaceCode,
            ...$definition,
            'fee_type' => 'percentage',
            'default_fee_rate' => 0,
            'is_active' => true,
        ]);
    }

    /** @param array<string,mixed> $values */
    private function categoryId(array $values): ?int
    {
        $parentName = trim((string) ($values['parent_category'] ?? ''));
        $categoryName = trim((string) ($values['category'] ?? ''));
        if ($parentName === '' && $categoryName === '') {
            return ($values['category_id'] ?? '') !== '' ? (int) $values['category_id'] : null;
        }
        if ($categoryName === '') {
            throw new RuntimeException('小ジャンルが空のためカテゴリを登録できません。');
        }
        $query = ProductCategory::query()->where('name', $categoryName);
        if ($parentName === '') {
            $query->whereNull('parent_id');
        } else {
            $query->whereHas('parent', fn ($parent) => $parent->where('name', $parentName));
        }
        $category = $query->first();
        if ($category === null) {
            throw new RuntimeException('カテゴリが見つかりません: '.$parentName.($parentName !== '' ? ' / ' : '').$categoryName);
        }

        return (int) $category->id;
    }

    /** @param array<string, string> $values @return array<string, mixed> */
    private function productValues(array $values): array
    {
        $nullableValues = collect(['description_base', 'purchase_date', 'jan_ean', 'isbn', 'manufacturer_model_number', 'serial_number', 'storage_location'])
            ->mapWithKeys(fn (string $key) => [$key => ($values[$key] ?? '') !== '' ? $values[$key] : null]);

        return [
            'internal_sku' => $values['internal_sku'],
            'product_name' => $values['product_name'],
            'condition' => $values['condition'],
            'purchase_unit_cost' => (int) $values['purchase_unit_cost'],
            'purchase_quantity' => (int) $values['purchase_quantity'],
            'quantity_available' => (int) $values['quantity_available'],
            'purchase_shipping_cost' => (int) ($values['purchase_shipping_cost'] ?? 0),
            'other_purchase_expense' => (int) ($values['other_purchase_expense'] ?? 0),
            'inventory_status' => Product::inventoryStatusForQuantity((int) $values['quantity_available']),
            'memo' => ($values['memo'] ?? $values['comment'] ?? '') !== '' ? ($values['memo'] ?? $values['comment']) : null,
            'category_id' => ($values['category_id'] ?? '') !== '' ? (int) $values['category_id'] : null,
            'supplier_id' => ($values['supplier_id'] ?? '') !== '' ? (int) $values['supplier_id'] : null,
            ...$nullableValues->all(),
        ];
    }
}
