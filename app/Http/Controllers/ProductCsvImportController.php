<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\Product;
use App\Services\AuditLogger;
use App\Services\ProductCsvPreviewService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

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
        $preview = $previewService->preview($validated['csv_file']);
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
        $existingSkus = Product::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('internal_sku', $rows->map(fn ($row) => $row->row_json['internal_sku'])->all())
            ->pluck('internal_sku');
        if ($existingSkus->isNotEmpty()) {
            return back()->withErrors(['csv_file' => '登録済みのSKUが含まれています: '.$existingSkus->implode(', ')]);
        }

        try {
            DB::transaction(function () use ($request, $importBatch, $rows): void {
                $batch = ImportBatch::query()->whereKey($importBatch->id)->lockForUpdate()->firstOrFail();
                abort_unless($batch->user_id === $request->user()->id && $batch->status === 'preview' && $batch->failed_rows === 0, 422);

                foreach ($rows as $row) {
                    $request->user()->products()->create($this->productValues($row->row_json));
                }

                $batch->update(['status' => 'completed']);
            });
        } catch (QueryException) {
            return back()->withErrors(['csv_file' => 'CSVの登録に失敗しました。SKUの重複がないか確認して、もう一度お試しください。']);
        }

        $auditLogger->log($request->user(), ImportBatch::class, $importBatch->id, 'product_csv.completed', null, [
            'created_products' => $rows->count(),
        ], $request->ip());

        return redirect()->route('products.index')->with('success', $rows->count().'件の商品を登録しました。');
    }

    /** @param array<string, string> $values @return array<string, mixed> */
    private function productValues(array $values): array
    {
        $nullableValues = collect(['description_base', 'purchase_date', 'jan_ean', 'isbn', 'manufacturer_model_number', 'serial_number', 'storage_location', 'memo'])
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
            'inventory_status' => $values['inventory_status'],
            ...$nullableValues->all(),
        ];
    }
}
