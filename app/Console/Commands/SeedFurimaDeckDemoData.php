<?php

namespace App\Console\Commands;

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\SaleProfitCalculator;
use Database\Seeders\FurimaDeckMarketplaceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SeedFurimaDeckDemoData extends Command
{
    private const DEMO_CSV_PATH = 'dummy_auction_items_2026_06_2027_06.csv';

    /** @var array<string, int> */
    private const MARKETPLACE_FEE_RATES = [
        'mercari' => 10,
        'yahoo_flea_market' => 5,
        'yahoo_auctions' => 10,
        'rakuma' => 10,
        'other' => 0,
    ];

    protected $signature = 'furimadeck:seed-demo-data';

    protected $description = '設定済みデモユーザーに商品・出品・販売のデモデータを追加します。既存データは削除しません。';

    public function handle(SaleProfitCalculator $profitCalculator): int
    {
        try {
            $user = $this->demoUser();
            $records = $this->records();
            $result = $this->seed($user, $records, $profitCalculator);
        } catch (RuntimeException $exception) {
            $this->error('デモデータを準備できませんでした。既存データは変更していません。');

            return self::FAILURE;
        }

        $this->info("デモデータを追加しました: 商品{$result['products']}件、出品{$result['listings']}件、販売{$result['sales']}件。既存データは削除していません。");

        return self::SUCCESS;
    }

    private function demoUser(): User
    {
        $email = config('demo.user_email');
        if (! (bool) config('demo.user_enabled') || ! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Demo user is not configured.');
        }

        $user = User::query()->where('email', Str::lower($email))->first();
        if ($user === null) {
            throw new RuntimeException('Demo user is missing.');
        }

        return $user;
    }

    /** @return list<array<string, string>> */
    private function records(): array
    {
        $path = base_path(self::DEMO_CSV_PATH);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Demo CSV is missing.');
        }

        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                throw new RuntimeException('Demo CSV has no headers.');
            }

            $headers = array_map(fn ($header): string => trim((string) $header), $headers);
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
            $requiredHeaders = [
                'product_id', 'product_name', 'condition', 'purchase_unit_cost', 'purchase_quantity',
                'quantity_available', 'inventory_status', 'purchase_date', 'purchase_shipping_cost',
                'other_purchase_expense', 'description_base', 'manufacturer_model_number',
                'storage_location', 'memo',
            ];
            if (array_diff($requiredHeaders, $headers) !== []) {
                throw new RuntimeException('Demo CSV headers are invalid.');
            }

            $records = [];
            while (($row = fgetcsv($handle)) !== false) {
                $record = array_combine($headers, array_map(fn ($value): string => trim((string) $value), array_pad($row, count($headers), '')));
                if ($record === false || $record['product_id'] === '' || $record['product_name'] === '') {
                    throw new RuntimeException('Demo CSV row is invalid.');
                }
                $records[] = $record;
            }

            if ($records === []) {
                throw new RuntimeException('Demo CSV is empty.');
            }

            return $records;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<array<string, string>>  $records
     * @return array{products: int, listings: int, sales: int}
     */
    private function seed(User $user, array $records, SaleProfitCalculator $profitCalculator): array
    {
        $categories = ProductCategory::query()
            ->where('is_active', true)
            ->whereDoesntHave('children', fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->get();
        if ($categories->isEmpty()) {
            throw new RuntimeException('Product categories are missing.');
        }

        app(FurimaDeckMarketplaceSeeder::class)->run();
        $marketplaces = Marketplace::query()->where('is_active', true)->orderBy('id')->get()->keyBy('code');
        if ($marketplaces->isEmpty()) {
            throw new RuntimeException('Marketplaces are missing.');
        }

        return DB::transaction(function () use ($user, $records, $categories, $marketplaces, $profitCalculator): array {
            $result = ['products' => 0, 'listings' => 0, 'sales' => 0];

            foreach ($records as $index => $record) {
                $product = $user->products()->firstOrCreate([
                    'internal_sku' => $record['product_id'],
                ], [
                    'product_name' => $record['product_name'],
                    'category_id' => $categories[$index % $categories->count()]->id,
                    'condition' => $record['condition'],
                    'description_base' => $this->nullable($record['description_base']),
                    'purchase_date' => $this->nullable($record['purchase_date']),
                    'purchase_unit_cost' => $this->integer($record['purchase_unit_cost']),
                    'purchase_quantity' => $this->positiveInteger($record['purchase_quantity']),
                    'quantity_available' => $this->integer($record['quantity_available']),
                    'purchase_shipping_cost' => $this->integer($record['purchase_shipping_cost']),
                    'other_purchase_expense' => $this->integer($record['other_purchase_expense']),
                    'manufacturer_model_number' => $this->nullable($record['manufacturer_model_number']),
                    'storage_location' => $this->nullable($record['storage_location']),
                    'inventory_status' => $record['inventory_status'],
                    'memo' => $this->nullable($record['memo']),
                ]);
                $result['products'] += $product->wasRecentlyCreated ? 1 : 0;

                if ($product->category_id === null) {
                    $product->update(['category_id' => $categories[$index % $categories->count()]->id]);
                }

                $marketplace = $marketplaces->values()[$index % $marketplaces->count()];
                if ($product->inventory_status === 'out_of_stock') {
                    $result['listings'] += $this->createSoldListing($user, $product, $marketplace, $index) ? 1 : 0;
                    $result['sales'] += $this->createCompletedSale($user, $product, $marketplace, $index, $profitCalculator) ? 1 : 0;
                } elseif ($product->quantity_available > 0) {
                    $result['listings'] += $this->createActiveListing($user, $product, $marketplace, $index) ? 1 : 0;
                }
            }

            return $result;
        });
    }

    private function createSoldListing(User $user, Product $product, Marketplace $marketplace, int $index): bool
    {
        $listing = $user->listings()->firstOrCreate([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'external_listing_id' => 'demo-sold-'.$product->internal_sku,
        ], $this->listingValues($product, 'sold', $index));

        return $listing->wasRecentlyCreated;
    }

    private function createActiveListing(User $user, Product $product, Marketplace $marketplace, int $index): bool
    {
        $listing = $user->listings()->firstOrCreate([
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'external_listing_id' => 'demo-active-'.$product->internal_sku,
        ], $this->listingValues($product, 'active', $index));

        return $listing->wasRecentlyCreated;
    }

    /** @return array<string, int|string> */
    private function listingValues(Product $product, string $status, int $index): array
    {
        $price = $this->salePrice($product, $index);

        return [
            'listing_title' => $product->product_name,
            'listing_description' => $product->description_base,
            'listing_price' => $price,
            'listing_quantity' => 1,
            'expected_fee_rate' => 10,
            'expected_profit' => $price - $product->purchase_unit_cost,
            'expected_margin' => 0,
            'shipping_fee' => $this->saleShippingFee($index),
            'status' => $status,
            'listed_at' => $product->purchase_date?->startOfDay() ?? now(),
            'ended_at' => $status === 'sold' ? ($product->purchase_date?->endOfDay() ?? now()) : null,
        ];
    }

    private function createCompletedSale(User $user, Product $product, Marketplace $marketplace, int $index, SaleProfitCalculator $profitCalculator): bool
    {
        if ($product->sales()->exists()) {
            return false;
        }

        $soldPrice = $this->salePrice($product, $index);
        $salesFee = (int) round($soldPrice * ((self::MARKETPLACE_FEE_RATES[$marketplace->code] ?? 0) / 100));
        $amounts = [
            'sold_price' => $soldPrice,
            'cost_basis' => $product->purchase_unit_cost,
            'sales_fee' => $salesFee,
            'shipping_fee' => $this->saleShippingFee($index),
            'purchase_shipping_cost' => $product->purchase_shipping_cost,
            'packing_cost' => $index % 4 === 0 ? 100 : 0,
            'repair_cost' => $index % 13 === 0 ? 300 : 0,
            'cleaning_cost' => $index % 5 === 0 ? 100 : 0,
            'other_expense' => $product->other_purchase_expense,
        ];
        $soldAt = $product->purchase_date?->setTime(12, 0) ?? now();

        $user->sales()->create($amounts + [
            'product_id' => $product->id,
            'marketplace_id' => $marketplace->id,
            'internal_sku_snapshot' => $product->internal_sku,
            'product_name_snapshot' => $product->product_name,
            'quantity' => 1,
            'sold_at' => $soldAt,
            'status' => 'completed',
            'net_profit' => $profitCalculator->calculate($amounts),
            'shipped_at' => $soldAt->copy()->addDay(),
            'completed_at' => $soldAt->copy()->addDays(3),
        ]);

        return true;
    }

    private function salePrice(Product $product, int $index): int
    {
        $multiplierPercent = 130 + (($index * 17) % 130);

        return max(100, (int) (round(($product->purchase_unit_cost * $multiplierPercent) / 100 / 100) * 100));
    }

    private function saleShippingFee(int $index): int
    {
        return [230, 450, 520, 750, 1000][$index % 5];
    }

    private function integer(string $value): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new RuntimeException('Demo CSV has an invalid amount.');
        }

        return (int) $value;
    }

    private function positiveInteger(string $value): int
    {
        $integer = $this->integer($value);
        if ($integer < 1) {
            throw new RuntimeException('Demo CSV has an invalid quantity.');
        }

        return $integer;
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
