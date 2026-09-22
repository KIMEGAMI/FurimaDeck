<?php

namespace App\Services;

use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FurimaDeckRestoreCsvService
{
    private const HEADERS = ['management_id', 'title', 'comment', 'platform', 'parent_category', 'category', 'purchase_price', 'sold_price', 'sales_fee_rate', 'shipping_fee', 'status', 'sold_at'];

    private const MAX_CELL_LENGTH = 1000;

    public function __construct(private readonly CsvEncodingService $encoding) {}

    /** @return array{headers: list<string>, rows: list<array{row_number:int, values:array<string,string>, errors:list<string>}>} */
    public function preview(User $user, UploadedFile $file): array
    {
        $contents = $this->encoding->toUtf8($file);
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            throw new RuntimeException('CSVファイルを読み込めませんでした。');
        }
        fwrite($handle, $contents);
        rewind($handle);

        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                throw new RuntimeException('CSVヘッダーを読み込めませんでした。');
            }
            $headers = array_map(fn (mixed $value): string => trim((string) $value), $headers);
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
            if ($headers !== self::HEADERS) {
                throw new RuntimeException('復元CSVの列が仕様と一致しません。');
            }

            $rows = [];
            $managementIds = [];
            $line = 1;
            $maxRows = (int) config('furimadeck.csv_import.max_rows', 5000);
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if (count($rows) >= $maxRows) {
                    throw new RuntimeException('CSVは'.$maxRows.'行までです。');
                }
                if (count(array_filter($row, fn (mixed $value): bool => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                if (count($row) > count($headers)) {
                    $rows[] = ['row_number' => $line, 'values' => [], 'errors' => ['列数がヘッダーより多くなっています。']];

                    continue;
                }
                $values = array_combine($headers, array_map(fn (mixed $value): string => mb_substr(trim((string) $value), 0, self::MAX_CELL_LENGTH), array_pad($row, count($headers), '')));
                if ($values === false) {
                    throw new RuntimeException('CSVの列を読み込めませんでした。');
                }
                $errors = $this->validateRow($user, $values);
                if (($values['management_id'] ?? '') !== '') {
                    $managementIds[$values['management_id']][] = count($rows);
                }
                $rows[] = ['row_number' => $line, 'values' => $values, 'errors' => $errors];
            }

            foreach ($managementIds as $indexes) {
                if (count($indexes) < 2) {
                    continue;
                }
                foreach ($indexes as $index) {
                    $rows[$index]['errors'][] = 'CSV内で管理IDが重複しています。';
                }
            }

            return ['headers' => $headers, 'rows' => $rows];
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,string> $values */
    public function commit(User $user, array $values): string
    {
        return DB::transaction(function () use ($user, $values): string {
            if (Product::query()->where('user_id', $user->id)->where('internal_sku', $values['management_id'])->lockForUpdate()->exists()) {
                return 'skipped';
            }
            $marketplace = $this->marketplace($values['platform']);
            if ($marketplace === null) {
                throw new RuntimeException('出品先が未登録または無効です。');
            }
            $status = $this->status($values['status']);
            $soldAt = $status === 'sold' ? $this->date($values['sold_at']) : null;
            if ($status === 'sold' && $soldAt === null) {
                throw new RuntimeException('SOLD商品のSOLD日は必須です。');
            }
            $categoryId = $this->categoryId($values);
            $product = $user->products()->create([
                'internal_sku' => $values['management_id'],
                'product_name' => $values['title'],
                'category_id' => $categoryId,
                'condition' => 'used',
                'purchase_unit_cost' => $this->money($values['purchase_price']),
                'purchase_quantity' => 1,
                'quantity_available' => $status === 'sold' ? 0 : 1,
                'inventory_status' => $status === 'sold' ? 'out_of_stock' : 'in_stock',
                'memo' => $values['comment'] !== '' ? $values['comment'] : null,
            ]);
            $listing = $user->listings()->create([
                'product_id' => $product->id,
                'marketplace_id' => $marketplace->id,
                'listing_title' => $values['title'],
                'listing_description' => $values['comment'] !== '' ? $values['comment'] : null,
                'listing_price' => $this->money($values['sold_price']),
                'expected_fee_rate' => $this->rate($values['sales_fee_rate']),
                'shipping_fee' => $this->money($values['shipping_fee']),
                'status' => $status === 'sold' ? 'sold' : 'active',
                'listed_at' => $status === 'sold' ? $soldAt : now(),
                'ended_at' => $soldAt,
            ]);
            if ($status === 'sold') {
                $soldPrice = $this->money($values['sold_price']);
                $salesFee = (int) round($soldPrice * ($this->rate($values['sales_fee_rate']) / 100));
                $shippingFee = $this->money($values['shipping_fee']);
                $cost = $this->money($values['purchase_price']);
                $user->sales()->create([
                    'product_id' => $product->id,
                    'listing_id' => $listing->id,
                    'marketplace_id' => $marketplace->id,
                    'internal_sku_snapshot' => $product->internal_sku,
                    'product_name_snapshot' => $product->product_name,
                    'quantity' => 1,
                    'sold_price' => $soldPrice,
                    'sold_at' => $soldAt,
                    'status' => 'completed',
                    'cost_basis' => $cost,
                    'sales_fee' => $salesFee,
                    'shipping_fee' => $shippingFee,
                    'net_profit' => $soldPrice - $cost - $salesFee - $shippingFee,
                    'completed_at' => $soldAt,
                ]);
            }

            return 'imported';
        });
    }

    /** @param array<string,string> $values */
    private function categoryId(array $values): ?int
    {
        $parentName = trim($values['parent_category'] ?? '');
        $categoryName = trim($values['category'] ?? '');
        if ($parentName === '' && $categoryName === '') {
            return null;
        }
        if ($categoryName === '') {
            throw new RuntimeException('小ジャンルが空のためカテゴリを復元できません。');
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

    /** @param array<string,string> $values @return list<string> */
    private function validateRow(User $user, array $values): array
    {
        $errors = [];
        foreach (['management_id' => '管理ID', 'title' => 'タイトル', 'platform' => '出品先', 'status' => 'ステータス'] as $key => $label) {
            if ($values[$key] === '') {
                $errors[] = $label.'は必須です。';
            }
        }
        if ($this->marketplace($values['platform']) === null) {
            $errors[] = '出品先が未登録または無効です。';
        }
        if (trim($values['parent_category'] ?? '') !== '' || trim($values['category'] ?? '') !== '') {
            try {
                $this->categoryId($values);
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        $status = $this->status($values['status']);
        if (! in_array($status, ['selling', 'sold'], true)) {
            $errors[] = 'ステータスはsellingまたはsoldにしてください。';
        }
        foreach (['purchase_price', 'sold_price', 'shipping_fee'] as $key) {
            if ($this->moneyStringIsInvalid($values[$key])) {
                $errors[] = $key.'は0以上の整数にしてください。';
            }
        }
        if ($status === 'sold' && $this->date($values['sold_at']) === null) {
            $errors[] = 'SOLD商品のSOLD日はYYYY-MM-DD形式で必須です。';
        }

        return $errors;
    }

    private function marketplace(string $label): ?Marketplace
    {
        $names = match ($label) {
            'PayPayフリマ', 'Yahoo!フリマ' => ['Yahoo!フリマ', 'PayPayフリマ'],
            'Yahoo!オークション', 'ヤフオク' => ['ヤフオク', 'Yahoo!オークション'],
            default => [$label],
        };

        return Marketplace::query()->where('is_active', true)->where(function ($query) use ($names, $label): void {
            $query->whereIn('name', $names)->orWhere('code', $label);
        })->first();
    }

    private function normalizeMarketplace(string $label): string
    {
        return match ($label) {
            'PayPayフリマ' => 'Yahoo!フリマ',
            'Yahoo!オークション' => 'ヤフオク',
            default => $label,
        };
    }

    private function status(string $status): string
    {
        return strtolower(trim($status));
    }

    private function money(string $value): int
    {
        return (int) str_replace([',', '円', '¥', '￥', ' '], '', mb_convert_kana($value, 'n'));
    }

    private function moneyStringIsInvalid(string $value): bool
    {
        return $value !== '' && preg_match('/^\d+$/', str_replace([',', '円', '¥', '￥', ' '], '', mb_convert_kana($value, 'n'))) !== 1;
    }

    private function rate(string $value): float
    {
        return is_numeric($value) ? max(0, (float) $value) : 0;
    }

    private function date(string $value): ?CarbonImmutable
    {
        if ($value === '') {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, config('app.timezone'));

        return $date !== false && $date->format('Y-m-d') === $value ? $date->startOfDay() : null;
    }
}
