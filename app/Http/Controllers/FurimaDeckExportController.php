<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FurimaDeckExportController extends Controller
{
    private const CSV_FORMULA_PREFIX_PATTERN = '/^(?:[\t\r\n\x00]|[\s\x00]*[=+\-@＝＋－＠])/u';

    public function products(Request $request): StreamedResponse
    {
        return $this->stream('products.csv', ['sku', 'product_name', 'condition', 'quantity_available', 'purchase_unit_cost', 'inventory_status'], $request->user()->products()->cursor(), fn ($product) => [$product->internal_sku, $product->product_name, $product->condition, $product->quantity_available, $product->purchase_unit_cost, $product->inventory_status]);
    }

    public function listings(Request $request): StreamedResponse
    {
        return $this->stream('listings.csv', ['product_name', 'marketplace', 'title', 'price', 'status'], $request->user()->listings()->with(['product', 'marketplace'])->cursor(), fn ($listing) => [$listing->product->product_name, $listing->marketplace->name, $listing->listing_title, $listing->listing_price, $listing->status]);
    }

    public function sales(Request $request): StreamedResponse
    {
        return $this->stream('sales.csv', ['sku', 'product_name', 'sold_price', 'net_profit', 'status', 'sold_at'], $request->user()->sales()->cursor(), fn ($sale) => [$sale->internal_sku_snapshot, $sale->product_name_snapshot, $sale->sold_price, $sale->net_profit, $sale->status, $sale->sold_at?->toDateString()]);
    }

    private function stream(string $filename, array $headers, iterable $rows, callable $map): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $map): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers);
            foreach ($rows as $row) {
                fputcsv($output, $this->sanitizeRow($map($row)));
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<int, string>
     */
    private function sanitizeRow(array $row): array
    {
        return array_map(function (mixed $value): string {
            $value = (string) $value;

            return preg_match(self::CSV_FORMULA_PREFIX_PATTERN, $value) === 1
                ? "'".$value
                : $value;
        }, $row);
    }
}
