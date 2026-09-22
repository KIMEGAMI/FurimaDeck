<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FurimaDeckExportController extends Controller
{
    private const CSV_FORMULA_PREFIX_PATTERN = '/^(?:[\t\r\n\x00]|[\s\x00]*[=+\-@＝＋－＠])/u';

    public function products(Request $request): StreamedResponse
    {
        return $this->stream('products.csv', ['product_id', 'product_name', 'condition', 'quantity_available', 'purchase_unit_cost', 'inventory_status'], $request->user()->products()->cursor(), fn ($product) => [$product->internal_sku, $product->product_name, $product->condition, $product->quantity_available, $product->purchase_unit_cost, $product->inventory_status]);
    }

    public function productBackup(Request $request): StreamedResponse
    {
        $headers = [
            'internal_sku', 'product_name', 'category_id', 'condition', 'description_base',
            'purchase_date', 'purchase_unit_cost', 'purchase_quantity', 'quantity_available',
            'purchase_shipping_cost', 'other_purchase_expense', 'supplier_id', 'jan_ean', 'isbn',
            'manufacturer_model_number', 'serial_number', 'storage_location', 'inventory_status', 'memo',
        ];

        return $this->stream(
            'furimadeck-products-backup.csv',
            $headers,
            $request->user()->products()->cursor(),
            fn ($product) => array_map(
                fn (string $field): mixed => $field === 'purchase_date'
                    ? $product->purchase_date?->format('Y-m-d')
                    : $product->{$field},
                $headers,
            ),
        );
    }

    public function listings(Request $request): StreamedResponse
    {
        return $this->stream('listings.csv', ['product_name', 'marketplace', 'title', 'price', 'status'], $request->user()->listings()->with(['product', 'marketplace'])->cursor(), fn ($listing) => [$listing->product->product_name, $listing->marketplace->name, $listing->listing_title, $listing->listing_price, $listing->status]);
    }

    public function sales(Request $request): StreamedResponse
    {
        return $this->stream('sales.csv', ['product_id', 'product_name', 'sold_price', 'net_profit', 'status', 'sold_at'], $request->user()->sales()->cursor(), fn ($sale) => [$sale->internal_sku_snapshot, $sale->product_name_snapshot, $sale->sold_price, $sale->net_profit, $sale->status, $sale->sold_at?->toDateString()]);
    }

    public function salesSpec(Request $request): StreamedResponse
    {
        $headers = ['管理ID', 'タイトル', '大ジャンル', '小ジャンル', '出品先', '仕入れ値', '売値', '販売手数料率', '販売手数料', '送料', '実利益', 'ステータス', 'SOLD日'];
        $rows = $request->user()->sales()
            ->with(['product.category.parent', 'listing.marketplace', 'marketplace'])
            ->where('status', 'completed')
            ->whereNotNull('sold_at')
            ->orderByDesc('sold_at')
            ->cursor();

        return $this->stream('furimadeck-sales.csv', $headers, $rows, function ($sale): array {
            $listing = $sale->listing;
            $marketplace = $listing?->marketplace ?? $sale->marketplace;
            $rate = $listing?->expected_fee_rate ?? $marketplace?->default_fee_rate ?? 0;

            return [
                $sale->internal_sku_snapshot,
                $sale->product_name_snapshot,
                $sale->product?->category?->parent?->name ?? '',
                $sale->product?->category?->name ?? '',
                $this->marketplaceLabel($marketplace?->name),
                (int) $sale->cost_basis,
                (int) $sale->sold_price,
                $this->formatRate($rate),
                (int) $sale->sales_fee,
                (int) $sale->shipping_fee,
                $this->calculatedProfit($sale),
                'SOLD',
                $sale->sold_at?->format('Y-m-d'),
            ];
        });
    }

    public function backupSpec(Request $request): StreamedResponse
    {
        $headers = ['管理ID', '商品タイトル', '大ジャンル', '小ジャンル', '出品先', 'ステータス', '仕入れ値', '販売価格', '販売手数料率', '販売手数料', '送料', '実利益', 'SOLD日', '商品画像URL', 'SOLD画像URL', 'コメント', '作成日', '更新日'];
        $rows = $request->user()->products()
            ->with(['category.parent', 'images', 'listings.marketplace', 'sales.marketplace'])
            ->orderByDesc('updated_at')
            ->cursor();

        return $this->stream('furimadeck-backup.csv', $headers, $rows, function ($product): array {
            $listing = $product->listings->sortByDesc('updated_at')->first();
            $sale = $product->sales->sortByDesc('sold_at')->first(fn ($sale) => $sale->status === 'completed');
            $marketplace = $listing?->marketplace ?? $sale?->marketplace;
            $image = $product->images->first();
            $soldImage = null;

            return [
                $product->internal_sku,
                $product->product_name,
                $product->category?->parent?->name ?? '',
                $product->category?->name ?? '',
                $this->marketplaceLabel($marketplace?->name),
                $sale !== null ? 'SOLD' : ($listing?->status ?? $product->inventory_status),
                (int) $product->purchase_unit_cost,
                (int) ($sale?->sold_price ?? $listing?->listing_price ?? 0),
                $this->formatRate($listing?->expected_fee_rate ?? $marketplace?->default_fee_rate ?? 0),
                (int) ($sale?->sales_fee ?? 0),
                (int) ($sale?->shipping_fee ?? $listing?->shipping_fee ?? 0),
                $sale !== null ? $this->calculatedProfit($sale) : '',
                $sale?->sold_at?->format('Y-m-d') ?? '',
                $image === null ? '' : route('products.images.show', [$product, $image, 'original']),
                $soldImage ?? '',
                $product->memo ?? $product->description_base ?? '',
                $product->created_at?->format('Y-m-d H:i:s'),
                $product->updated_at?->format('Y-m-d H:i:s'),
            ];
        });
    }

    public function restoreSpec(Request $request): StreamedResponse
    {
        $headers = ['management_id', 'title', 'comment', 'platform', 'parent_category', 'category', 'purchase_price', 'sold_price', 'sales_fee_rate', 'shipping_fee', 'status', 'sold_at'];
        $rows = $request->user()->products()
            ->with(['category.parent', 'listings.marketplace', 'sales.marketplace'])
            ->orderBy('id')
            ->cursor();

        return $this->stream('furimadeck-restore.csv', $headers, $rows, function ($product): array {
            $sale = $product->sales->sortByDesc('sold_at')->first(fn ($sale) => $sale->status === 'completed');
            $listing = $product->listings->sortByDesc('updated_at')->first();
            $marketplace = $sale?->marketplace ?? $listing?->marketplace;
            $rate = $sale?->listing?->expected_fee_rate ?? $listing?->expected_fee_rate ?? $marketplace?->default_fee_rate ?? 0;

            return [
                $product->internal_sku,
                $product->product_name,
                $product->memo ?? $product->description_base ?? '',
                $this->marketplaceLabel($marketplace?->name),
                $product->category?->parent?->name ?? '',
                $product->category?->name ?? '',
                (int) $product->purchase_unit_cost,
                (int) ($sale?->sold_price ?? $listing?->listing_price ?? 0),
                $this->formatRate($rate),
                (int) ($sale?->shipping_fee ?? $listing?->shipping_fee ?? 0),
                $sale !== null ? 'sold' : 'selling',
                $sale?->sold_at?->format('Y-m-d') ?? '',
            ];
        });
    }

    public function activeListingsSpec(Request $request): StreamedResponse
    {
        $headers = ['management_id', 'title', 'comment', 'platform', 'parent_category', 'category', 'purchase_price', 'sold_price', 'sales_fee_rate', 'shipping_fee', 'status', 'sold_at'];
        $rows = $request->user()->listings()
            ->with(['product.category.parent', 'marketplace'])
            ->where('status', 'active')
            ->orderBy('id')
            ->cursor();

        return $this->stream('furimadeck-active-listings.csv', $headers, $rows, function ($listing): array {
            return [
                $listing->product?->internal_sku,
                $listing->listing_title ?: $listing->product?->product_name,
                $listing->listing_description ?: $listing->product?->memo,
                $this->marketplaceLabel($listing->marketplace?->name),
                $listing->product?->category?->parent?->name ?? '',
                $listing->product?->category?->name ?? '',
                (int) ($listing->product?->purchase_unit_cost ?? 0),
                (int) $listing->listing_price,
                $this->formatRate($listing->expected_fee_rate),
                (int) $listing->shipping_fee,
                'selling',
                '',
            ];
        });
    }

    private function calculatedProfit($sale): int
    {
        return (int) $sale->sold_price
            - (int) $sale->cost_basis
            - (int) $sale->sales_fee
            - (int) $sale->shipping_fee
            - (int) $sale->purchase_shipping_cost
            - (int) $sale->packing_cost
            - (int) $sale->repair_cost
            - (int) $sale->cleaning_cost
            - (int) $sale->other_expense;
    }

    private function formatRate(mixed $rate): string
    {
        return rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');
    }

    private function marketplaceLabel(?string $name): string
    {
        return match ($name) {
            'PayPayフリマ' => 'Yahoo!フリマ',
            'Yahoo!オークション' => 'ヤフオク',
            default => $name ?? '',
        };
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
