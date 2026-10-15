<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChromeExtensionExportController extends Controller
{
    private const FORMULA_PREFIX_PATTERN = '/^(?:[\t\r\n\x00]|[\s\x00]*[=+\-@＝＋－＠])/u';

    public function products(Request $request): StreamedResponse
    {
        $user = $request->attributes->get('chrome_extension_user');
        $rows = $user->products()->with('category.parent')->lazyById(250);

        return $this->stream('furimadeck-products.csv', [
            'internal_sku', 'product_name', 'condition', 'purchase_unit_cost', 'purchase_quantity',
            'quantity_available', 'inventory_status', 'description_base', 'purchase_date',
            'purchase_shipping_cost', 'other_purchase_expense', 'jan_ean', 'isbn',
            'manufacturer_model_number', 'serial_number', 'storage_location', 'memo',
            'parent_category', 'category',
        ], $rows, fn ($product): array => [
            $product->internal_sku,
            $product->product_name,
            $product->condition,
            $product->purchase_unit_cost,
            $product->purchase_quantity,
            $product->quantity_available,
            $product->inventory_status,
            $product->description_base,
            $product->purchase_date?->toDateString(),
            $product->purchase_shipping_cost,
            $product->other_purchase_expense,
            $product->jan_ean,
            $product->isbn,
            $product->manufacturer_model_number,
            $product->serial_number,
            $product->storage_location,
            $product->memo,
            $product->category?->parent?->name,
            $product->category?->name,
        ]);
    }

    public function listings(Request $request): StreamedResponse
    {
        $user = $request->attributes->get('chrome_extension_user');
        $rows = $user->listings()->with(['product', 'marketplace'])->lazyById(250);

        return $this->stream('furimadeck-listings.csv', [
            'internal_sku', 'product_name', 'platform', 'title', 'price', 'status',
            'external_listing_url', 'listed_at', 'record_method',
        ], $rows, fn ($listing): array => [
            $listing->product?->internal_sku,
            $listing->product?->product_name,
            $listing->marketplace?->name,
            $listing->listing_title,
            $listing->listing_price,
            $listing->status,
            $listing->external_listing_url,
            $listing->listed_at?->toIso8601String(),
            $listing->record_method,
        ]);
    }

    public function sales(Request $request): StreamedResponse
    {
        $user = $request->attributes->get('chrome_extension_user');
        $rows = $user->sales()->with('marketplace')->lazyById(250);

        return $this->stream('furimadeck-sales.csv', [
            'internal_sku', 'product_name', 'platform', 'sold_price', 'sales_fee',
            'shipping_fee', 'net_profit', 'status', 'sold_at',
        ], $rows, fn ($sale): array => [
            $sale->internal_sku_snapshot,
            $sale->product_name_snapshot,
            $sale->marketplace?->name,
            $sale->sold_price,
            $sale->sales_fee,
            $sale->shipping_fee,
            $sale->net_profit,
            $sale->status,
            $sale->sold_at?->toIso8601String(),
        ]);
    }

    /** @param array<int, string> $headers @param iterable<mixed> $rows @param callable(mixed): array<int, mixed> $map */
    private function stream(string $filename, array $headers, iterable $rows, callable $map): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows, $map): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                return;
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $headers, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($output, $this->sanitizeRow($map($row)), ',', '"', '');
            }
            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param array<int, mixed> $row @return array<int, string> */
    private function sanitizeRow(array $row): array
    {
        return array_map(function (mixed $value): string {
            $value = (string) $value;

            return preg_match(self::FORMULA_PREFIX_PATTERN, $value) === 1 ? "'".$value : $value;
        }, $row);
    }
}
