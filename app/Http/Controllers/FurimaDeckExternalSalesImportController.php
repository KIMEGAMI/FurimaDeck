<?php

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Services\AuditLogger;
use App\Services\FurimaDeckExternalSalesImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class FurimaDeckExternalSalesImportController extends Controller
{
    public function yahooAuctions(Request $request, FurimaDeckExternalSalesImportService $service): RedirectResponse
    {
        return $this->import($request, $service, 'yahoo_auctions', 'yahoo_csv_file', 'ヤフオク');
    }

    public function mercariShops(Request $request, FurimaDeckExternalSalesImportService $service): RedirectResponse
    {
        return $this->import($request, $service, 'mercari_shops', 'mercari_shops_csv_file', 'メルカリShops');
    }

    public function previewYahoo(Request $request, FurimaDeckExternalSalesImportService $service, AuditLogger $auditLogger): RedirectResponse
    {
        return $this->preview($request, $service, $auditLogger, 'yahoo_auctions', 'yahoo_csv_file', 'ヤフオク');
    }

    public function previewMercari(Request $request, FurimaDeckExternalSalesImportService $service, AuditLogger $auditLogger): RedirectResponse
    {
        return $this->preview($request, $service, $auditLogger, 'mercari_shops', 'mercari_shops_csv_file', 'メルカリShops');
    }

    private function preview(Request $request, FurimaDeckExternalSalesImportService $service, AuditLogger $auditLogger, string $format, string $field, string $label): RedirectResponse
    {
        $validated = $request->validate([$field => ['required', 'file', 'mimes:csv,txt', 'max:'.(int) config('furimadeck.csv_import.max_size_kilobytes')]]);
        try {
            $preview = $service->preview($request->user(), $validated[$field], $format);
        } catch (RuntimeException $exception) {
            return back()->withInput()->withErrors([$field => $exception->getMessage()]);
        }
        $validRows = collect($preview['rows'])->where('errors', [])->count();
        $invalidRows = count($preview['rows']) - $validRows;
        $batch = DB::transaction(function () use ($request, $validated, $preview, $validRows, $invalidRows, $format): ImportBatch {
            $batch = new ImportBatch([
                'batch_uuid' => (string) Str::uuid(),
                'filename' => basename(str_replace('\\', '/', $validated[array_key_first($validated)]->getClientOriginalName())),
                'original_filename' => $validated[array_key_first($validated)]->getClientOriginalName(),
                'format' => 'furimadeck_external_'.$format,
                'total_rows' => count($preview['rows']) + $preview['skipped'],
                'success_rows' => $validRows,
                'skipped_rows' => $preview['skipped'],
                'failed_rows' => $invalidRows,
                'status' => 'preview',
                'started_at' => now(),
            ]);
            $batch->user_id = $request->user()->id;
            $batch->save();
            foreach ($preview['rows'] as $row) {
                $batch->rowResults()->create(['row_number' => $row['row_number'], 'row_json' => $row['values'], 'errors_json' => $row['errors'] === [] ? null : $row['errors'], 'is_valid' => $row['errors'] === []]);
            }

            return $batch;
        });
        $auditLogger->log($request->user(), ImportBatch::class, $batch->id, 'external_sales.previewed', null, ['format' => $format, 'total_rows' => $batch->total_rows, 'skipped_rows' => $batch->skipped_rows, 'invalid_rows' => $batch->failed_rows], $request->ip());

        return redirect()->route('products.imports.show', $batch)->with('csv_import_label', $label);
    }

    private function import(Request $request, FurimaDeckExternalSalesImportService $service, string $format, string $field, string $label): RedirectResponse
    {
        $validated = $request->validate([$field => ['required', 'file', 'mimes:csv,txt', 'max:'.(int) config('furimadeck.csv_import.max_size_kilobytes')]]);
        try {
            $result = $service->import($request->user(), $validated[$field], $format);
        } catch (RuntimeException $exception) {
            return back()->withErrors([$field => $exception->getMessage()]);
        }
        $message = $label.'売上CSVを取り込みました。登録 '.$result['imported'].'件 / スキップ '.$result['skipped'].'件';
        if ($result['errors'] !== []) {
            $message .= ' / エラー '.count($result['errors']).'件';
        }

        return back()->with('success', $message)->with('csv_import_errors', $result['errors']);
    }
}
