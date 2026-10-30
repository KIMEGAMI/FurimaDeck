<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class FurimaDeckRestoreCsvService
{
    private const HEADERS = ['management_id', 'title', 'comment', 'platform', 'parent_category', 'category', 'purchase_price', 'sold_price', 'sales_fee_rate', 'shipping_fee', 'status', 'sold_at'];

    private const MAX_CELL_LENGTH = 1000;

    private const MAX_MANAGEMENT_ID_LENGTH = 80;

    private const MAX_TITLE_LENGTH = 255;

    private const MAX_UNSIGNED_AMOUNT = SaleProfitCalculator::MAX_UNSIGNED_AMOUNT;

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
                $rawValues = array_map(fn (mixed $value): string => trim((string) $value), array_pad($row, count($headers), ''));
                $hasOversizedCell = false;
                foreach ($rawValues as $rawValue) {
                    if (mb_strlen($rawValue) > self::MAX_CELL_LENGTH) {
                        $hasOversizedCell = true;
                        break;
                    }
                }
                $values = array_combine($headers, array_map(fn (string $value): string => mb_substr($value, 0, self::MAX_CELL_LENGTH), $rawValues));
                if ($values === false) {
                    throw new RuntimeException('CSVの列を読み込めませんでした。');
                }
                $errors = $this->validateRow($user, $values);
                if ($hasOversizedCell) {
                    $errors[] = 'CSVのセルが1000文字を超えています。内容を短くしてください。';
                }
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
            $errors = $this->validateRow($user, $values);
            if ($errors !== []) {
                throw new RuntimeException(implode(' ', array_unique($errors)));
            }

            if (Product::query()->where('user_id', $user->id)->where('internal_sku', $values['management_id'])->lockForUpdate()->exists()) {
                return 'skipped';
            }
            $status = $this->status($values['status']);
            $unlisted = $status === 'unlisted';
            $marketplace = $values['platform'] === '' ? null : $this->marketplace($values['platform']);
            if (! $unlisted && $marketplace === null) {
                throw new RuntimeException('出品先が未登録または無効です。');
            }
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
            $listing = null;
            if (! $unlisted) {
                $listingStatus = $status === 'selling' ? 'active' : $status;
                $listing = $user->listings()->create([
                    'product_id' => $product->id,
                    'marketplace_id' => $marketplace->id,
                    'listing_title' => $values['title'],
                    'listing_description' => $values['comment'] !== '' ? $values['comment'] : null,
                    'listing_price' => $this->money($values['sold_price']),
                    'expected_fee_rate' => $this->rate($values['sales_fee_rate']),
                    'shipping_fee' => $this->money($values['shipping_fee']),
                    'status' => $listingStatus,
                    'listed_at' => $status === 'sold' ? $soldAt : ($listingStatus === 'active' ? now() : null),
                    'ended_at' => $soldAt,
                ]);
            }
            if ($status === 'sold') {
                if ($listing === null || $marketplace === null) {
                    throw new RuntimeException('SOLD商品の出品情報を復元できません。');
                }
                $sale = $user->sales()->create([
                    'product_id' => $product->id,
                    'listing_id' => $listing->id,
                    'marketplace_id' => $marketplace->id,
                    'internal_sku_snapshot' => $product->internal_sku,
                    'product_name_snapshot' => $product->product_name,
                    'quantity' => 1,
                    ...$this->saleAmounts($values),
                    'sold_at' => $soldAt,
                    'status' => 'completed',
                    'completed_at' => $soldAt,
                ]);
                app(FurimaDeckAccountingEntryService::class)->recordSale($user, $sale);
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
        foreach (['management_id' => '管理ID', 'title' => 'タイトル', 'status' => 'ステータス'] as $key => $label) {
            if ($values[$key] === '') {
                $errors[] = $label.'は必須です。';
            }
        }
        if (mb_strlen($values['management_id']) > self::MAX_MANAGEMENT_ID_LENGTH) {
            $errors[] = '管理IDは'.self::MAX_MANAGEMENT_ID_LENGTH.'文字以内にしてください。';
        }
        if (mb_strlen($values['title']) > self::MAX_TITLE_LENGTH) {
            $errors[] = 'タイトルは'.self::MAX_TITLE_LENGTH.'文字以内にしてください。';
        }
        $status = $this->status($values['status']);
        $unlisted = $status === 'unlisted';
        if ($unlisted && $values['platform'] !== '') {
            $errors[] = '未出品商品の出品先は空欄にしてください。';
        } elseif (! $unlisted && ($values['platform'] === '' || $this->marketplace($values['platform']) === null)) {
            $errors[] = '出品先が未登録または無効です。';
        }
        if (trim($values['parent_category'] ?? '') !== '' || trim($values['category'] ?? '') !== '') {
            try {
                $this->categoryId($values);
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        if (! in_array($status, ['selling', 'sold', 'unlisted', ...Listing::USER_SETTABLE_STATUSES], true)) {
            $errors[] = 'ステータスが復元用CSVの対応範囲外です。';
        }
        $hasInvalidMoney = false;
        foreach (['purchase_price', 'sold_price', 'shipping_fee'] as $key) {
            if ($this->moneyStringIsInvalid($values[$key])) {
                $errors[] = $key.'は0以上'.number_format(self::MAX_UNSIGNED_AMOUNT).'以下の整数にしてください。';
                $hasInvalidMoney = true;
            }
        }
        $hasInvalidRate = $this->rateStringIsInvalid($values['sales_fee_rate']);
        if ($hasInvalidRate) {
            $errors[] = '販売手数料率は0から100までの数値（小数点以下2桁まで）にしてください。';
        }
        if ($status === 'sold' && $this->date($values['sold_at']) === null) {
            $errors[] = 'SOLD商品のSOLD日はYYYY-MM-DD形式で必須です。';
        }
        if ($status === 'sold' && ! $hasInvalidMoney && ! $hasInvalidRate) {
            try {
                $this->saleAmounts($values);
            } catch (InvalidArgumentException) {
                $errors[] = '計算後の利益が保存可能な範囲を超えています。価格・仕入れ値・送料を確認してください。';
            }
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

    /** @param array<string, string> $values @return array{sold_price:int,cost_basis:int,sales_fee:int,shipping_fee:int,net_profit:int} */
    private function saleAmounts(array $values): array
    {
        $soldPrice = $this->money($values['sold_price']);
        $cost = $this->money($values['purchase_price']);
        $salesFee = app(MarketplaceFeeService::class)->amountAtRate($soldPrice, $this->rate($values['sales_fee_rate']));
        $shippingFee = $this->money($values['shipping_fee']);

        return [
            'sold_price' => $soldPrice,
            'cost_basis' => $cost,
            'sales_fee' => $salesFee,
            'shipping_fee' => $shippingFee,
            'net_profit' => app(SaleProfitCalculator::class)->calculate([
                'sold_price' => $soldPrice,
                'cost_basis' => $cost,
                'sales_fee' => $salesFee,
                'shipping_fee' => $shippingFee,
            ]),
        ];
    }

    private function money(string $value): int
    {
        if ($this->moneyStringIsInvalid($value)) {
            throw new RuntimeException('価格・仕入れ値・送料は0以上'.number_format(self::MAX_UNSIGNED_AMOUNT).'以下の整数にしてください。');
        }

        return (int) str_replace([',', '円', '¥', '￥', ' '], '', mb_convert_kana($value, 'n'));
    }

    private function moneyStringIsInvalid(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $digits = str_replace([',', '円', '¥', '￥', ' '], '', mb_convert_kana($value, 'n'));
        if (preg_match('/\A\d+\z/', $digits) !== 1) {
            return true;
        }

        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return false;
        }

        $maxUnsignedAmount = (string) self::MAX_UNSIGNED_AMOUNT;

        return strlen($digits) > strlen($maxUnsignedAmount)
            || (strlen($digits) === strlen($maxUnsignedAmount) && strcmp($digits, $maxUnsignedAmount) > 0);
    }

    private function rate(string $value): float
    {
        if ($this->rateStringIsInvalid($value)) {
            throw new RuntimeException('販売手数料率は0から100までの数値（小数点以下2桁まで）にしてください。');
        }

        return (float) $value;
    }

    private function rateStringIsInvalid(string $value): bool
    {
        if (preg_match('/\A\d+(?:\.\d{1,2})?\z/', $value) !== 1) {
            return true;
        }

        return (float) $value > 100;
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
