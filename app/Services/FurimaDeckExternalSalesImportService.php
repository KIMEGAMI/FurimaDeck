<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductInventoryEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class FurimaDeckExternalSalesImportService
{
    private const MAX_ROWS = 5000;

    private const MAX_CELL_LENGTH = 1000;

    private const DUPLICATE_CONFLICT_MESSAGE = '同じ取引IDの売上明細で内容が一致しません。CSVの重複行と金額を確認してください。';

    private const YAHOO_HEADERS = ['取扱内容', '商品ID', '取扱日', '状態', '決済金額', '送料'];

    private const MERCARI_HEADERS = ['注文番号', '明細番号', '明細種別', '購入日', 'キャンセル日', '商品名', '数量', '売上（税込）', 'メルカリ便送料（税込）', '送料（税込）', '販売手数料（税込）', '販売手数料率（%）', 'ショップ名'];

    /** @return array{rows:list<array{row_number:int, values:array<string,mixed>, errors:list<string>}>, skipped:int} */
    public function preview(User $user, UploadedFile $file, string $format): array
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
            $required = $format === 'yahoo_auctions'
                ? self::YAHOO_HEADERS
                : ['注文番号', '明細番号', '明細種別', '購入日', '支払日', '発送日', '売上移転日', 'キャンセル日', '商品名', '数量', '通貨', '販売利益', '売上（税込）', 'メルカリ便送料（税込）', '送料（税込）', '販売手数料（税込）', '販売手数料率（%）', 'クーポン割引金額', 'クーポンID', 'ショップ名', 'インボイス対象金額合計（販売手数料 + メルカリ便送料）', 'インボイス対象税率', '伝票番号', '請求書発行事業者'];
            $missing = array_values(array_diff($required, $headers));
            if ($missing !== []) {
                throw new RuntimeException('CSVに必要な列がありません。不足: '.implode(', ', $missing));
            }
            $rows = [];
            $skipped = 0;
            $line = 1;
            $seen = [];
            $dataRows = 0;
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if (count(array_filter($row, fn (mixed $value): bool => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                $dataRows++;
                if ($dataRows > (int) config('furimadeck.csv_import.max_rows', self::MAX_ROWS)) {
                    throw new RuntimeException('CSVは'.(int) config('furimadeck.csv_import.max_rows', self::MAX_ROWS).'行までです。');
                }
                // Yahoo exports may include trailing columns; keep the declared headers as the source of truth.
                $values = null;
                try {
                    $row = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
                    $values = array_combine($headers, array_map(fn (mixed $value): string => $this->cell($value), array_pad($row, count($headers), '')));
                    if ($values === false) {
                        throw new RuntimeException('列を読み取れませんでした。');
                    }
                    $parsed = $format === 'yahoo_auctions' ? $this->parseYahoo($values) : $this->parseMercari($values);
                } catch (RuntimeException $exception) {
                    $rows[] = [
                        'row_number' => $line,
                        'values' => isset($values) && is_array($values) ? $this->errorPreviewValues($values, $format) : [],
                        'errors' => [$exception->getMessage()],
                    ];

                    continue;
                }
                if ($parsed === null) {
                    $skipped++;

                    continue;
                }
                $key = $parsed['management_id'];
                if (isset($seen[$key])) {
                    if ($this->sameSaleImport($seen[$key], $parsed)) {
                        $skipped++;

                        continue;
                    }

                    $parsed['sold_at'] = $parsed['sold_at']->format('Y-m-d H:i:s');
                    $rows[] = ['row_number' => $line, 'values' => $parsed, 'errors' => [self::DUPLICATE_CONFLICT_MESSAGE]];

                    continue;
                }
                $seen[$key] = $parsed;
                $parsed['sold_at'] = $parsed['sold_at']->format('Y-m-d H:i:s');
                $errors = (int) ($parsed['quantity'] ?? 0) === Product::UNITS_PER_PRODUCT_ID
                    ? []
                    : ['売上CSVの数量が1点ではありません。商品IDごとの販売明細に分けてから取り込んでください。'];
                $rows[] = ['row_number' => $line, 'values' => $parsed, 'errors' => $errors];
            }

            if ($dataRows === 0) {
                throw new RuntimeException('CSVに売上明細がありません。明細行を含むレポートを再ダウンロードしてください。');
            }

            return ['rows' => $rows, 'skipped' => $skipped];
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $parsed */
    public function commitParsed(User $user, array $parsed, string $format): string
    {
        if ((int) ($parsed['quantity'] ?? 0) !== Product::UNITS_PER_PRODUCT_ID) {
            throw new RuntimeException('売上CSVの数量が1点ではありません。商品IDごとの販売明細に分けてから取り込んでください。');
        }

        $marketplaceCode = $format === 'yahoo_auctions' ? 'yahoo_auctions' : 'mercari';
        $marketplace = Marketplace::query()->where('code', $marketplaceCode)->where('is_active', true)->first();
        if ($marketplace === null) {
            throw new RuntimeException('対象の出品先が未登録または無効です。');
        }

        return DB::connection('furimadeck')->transaction(function () use ($user, $marketplace, $parsed): string {
            $soldAt = CarbonImmutable::parse((string) $parsed['sold_at'], config('app.timezone'));
            $listing = Listing::query()
                ->where('user_id', $user->id)
                ->where('marketplace_id', $marketplace->id)
                ->where('external_listing_id', $parsed['management_id'])
                ->lockForUpdate()
                ->first();
            if ($listing !== null && $listing->sales()->where('sold_at', $soldAt)->exists()) {
                return 'skipped';
            }

            $product = $listing === null ? null : Product::query()
                ->whereKey($listing->product_id)->where('user_id', $user->id)->lockForUpdate()->first();
            if ($product === null) {
                $product = Product::query()->where('user_id', $user->id)->where('internal_sku', $parsed['management_id'])->lockForUpdate()->first();
            }
            $matched = $product !== null && $product->purchase_info_confirmed;
            if ($matched && (int) $product->purchase_quantity !== Product::UNITS_PER_PRODUCT_ID) {
                throw new RuntimeException('取込対象の商品IDの仕入数量が1点ではありません。商品IDごとの商品に整理してから再取込してください。');
            }
            if ($matched && (int) $product->quantity_available > Product::UNITS_PER_PRODUCT_ID) {
                throw new RuntimeException('取込対象の商品IDに複数点の在庫があります。商品IDごとの在庫に整理してから再取込してください。');
            }
            if ($product !== null && $user->sales()->where('product_id', $product->id)
                ->where('marketplace_id', $marketplace->id)->where('sold_at', $soldAt)->exists()) {
                return 'skipped';
            }
            if ($matched && (int) $parsed['quantity'] > (int) $product->quantity_available) {
                throw new RuntimeException('取込対象の在庫が不足しています。商品と既存の売上を確認してください。');
            }
            if ($product === null) {
                $product = $user->products()->create([
                    'internal_sku' => $parsed['management_id'],
                    'product_name' => $parsed['title'],
                    'condition' => 'used',
                    'purchase_unit_cost' => 0,
                    'purchase_info_confirmed' => false,
                    'purchase_quantity' => Product::UNITS_PER_PRODUCT_ID,
                    'quantity_available' => Product::UNITS_PER_PRODUCT_ID,
                    'inventory_status' => 'in_stock',
                    'memo' => '外部売上CSV未照合。仕入れ値と出品情報を確認してください。',
                ]);
                app(ProductInventoryEventService::class)->recordOpening(
                    $product,
                    $user,
                    'external_sales_import',
                    (int) $product->id,
                    $soldAt,
                    '外部売上CSVから作成した商品の初期在庫',
                );
            }
            $costBasis = $matched ? (int) $product->purchase_unit_cost * (int) $parsed['quantity'] : 0;
            try {
                $netProfit = $matched ? app(SaleProfitCalculator::class)->calculate([
                    'sold_price' => (int) $parsed['sold_price'], 'cost_basis' => $costBasis,
                    'sales_fee' => (int) $parsed['sales_fee'], 'shipping_fee' => (int) $parsed['shipping_fee'],
                ]) : 0;
            } catch (InvalidArgumentException $exception) {
                throw new RuntimeException('取込金額が保存可能な範囲を超えています。明細を確認してください。', previous: $exception);
            }
            $sale = $user->sales()->create([
                'product_id' => $product->id,
                'listing_id' => $listing?->id,
                'marketplace_id' => $marketplace->id,
                'internal_sku_snapshot' => $product->internal_sku,
                'product_name_snapshot' => $product->product_name,
                'quantity' => $parsed['quantity'],
                'sold_price' => $parsed['sold_price'],
                'sold_at' => $soldAt,
                'status' => $matched ? 'completed' : 'pending',
                'cost_basis' => $costBasis,
                'sales_fee' => $parsed['sales_fee'],
                'shipping_fee' => $parsed['shipping_fee'],
                'net_profit' => $netProfit,
                'completed_at' => $matched ? $soldAt : null,
            ]);

            if ((int) $product->quantity_available > 0) {
                app(ProductInventoryEventService::class)->applyDelta(
                    $product,
                    -(int) $parsed['quantity'],
                    ProductInventoryEvent::TYPE_SALE,
                    'external_sales_import',
                    (int) $sale->id,
                    $user,
                    $soldAt,
                    '外部売上CSVに基づく在庫払出',
                );
            }
            if ($listing !== null) {
                $listing->update(['status' => 'sold', 'ended_at' => now()]);
            }
            if ($matched) {
                app(FurimaDeckAccountingEntryService::class)->recordSale($user, $sale);
            }

            return $matched ? 'imported' : 'provisional';
        });
    }

    /** @return array{imported:int, skipped:int, errors:list<string>} */
    public function __construct(private readonly CsvEncodingService $encoding) {}

    public function import(User $user, UploadedFile $file, string $format): array
    {
        $contents = $this->encoding->toUtf8($file);
        $handle = fopen('php://temp', 'r+b');
        if ($handle !== false) {
            fwrite($handle, $contents);
            rewind($handle);
        }
        if ($handle === false) {
            throw new RuntimeException('CSVファイルを読み込めませんでした。');
        }

        try {

            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                throw new RuntimeException('CSVヘッダーを読み込めませんでした。');
            }
            $headers = array_map(fn (mixed $header): string => trim((string) $header), $headers);
            $required = $format === 'yahoo_auctions' ? self::YAHOO_HEADERS : self::MERCARI_HEADERS;
            $missing = array_values(array_diff($required, $headers));
            if ($missing !== []) {
                throw new RuntimeException('CSVに必要な列がありません。不足: '.implode(', ', $missing));
            }

            $marketplaceCode = $format === 'yahoo_auctions' ? 'yahoo_auctions' : 'mercari';
            $marketplace = Marketplace::query()->where('code', $marketplaceCode)->where('is_active', true)->first();
            if ($marketplace === null) {
                throw new RuntimeException('対象の出品先が未登録または無効です。');
            }

            $result = ['imported' => 0, 'skipped' => 0, 'errors' => []];
            $line = 1;
            $dataRows = 0;
            $seen = [];
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if ($line > (int) config('furimadeck.csv_import.max_rows', self::MAX_ROWS) + 1) {
                    throw new RuntimeException('CSVは'.(int) config('furimadeck.csv_import.max_rows', self::MAX_ROWS).'行までです。');
                }
                if (count(array_filter($row, fn (mixed $value): bool => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                $dataRows++;
                // Keep compatibility with exports that contain extra trailing columns.
                try {
                    $row = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
                    $values = array_combine($headers, array_map(fn (mixed $value): string => $this->cell($value), array_pad($row, count($headers), '')));
                    if ($values === false) {
                        throw new RuntimeException('列を読み取れませんでした。');
                    }
                    $parsed = $format === 'yahoo_auctions'
                        ? $this->parseYahoo($values)
                        : $this->parseMercari($values);
                } catch (RuntimeException $exception) {
                    $result['errors'][] = '行'.$line.': '.$exception->getMessage();

                    continue;
                }
                if ($parsed === null) {
                    $result['skipped']++;

                    continue;
                }
                $key = $parsed['management_id'];
                if (isset($seen[$key])) {
                    if ($this->sameSaleImport($seen[$key], $parsed)) {
                        $result['skipped']++;
                    } else {
                        $result['errors'][] = '行'.$line.': '.self::DUPLICATE_CONFLICT_MESSAGE;
                    }

                    continue;
                }
                $seen[$key] = $parsed;
                if ((int) ($parsed['quantity'] ?? 0) !== Product::UNITS_PER_PRODUCT_ID) {
                    $result['errors'][] = '行'.$line.': 売上CSVの数量が1点ではありません。商品IDごとの販売明細に分けてから取り込んでください。';

                    continue;
                }
                try {
                    $created = DB::connection('furimadeck')->transaction(function () use ($user, $marketplace, $parsed): bool {
                        if (Product::query()->where('user_id', $user->id)->where('internal_sku', $parsed['management_id'])->exists()) {
                            return false;
                        }
                        $product = $user->products()->create([
                            'internal_sku' => $parsed['management_id'],
                            'product_name' => $parsed['title'],
                            'condition' => 'used',
                            'purchase_unit_cost' => 0,
                            'purchase_info_confirmed' => false,
                            'purchase_quantity' => Product::UNITS_PER_PRODUCT_ID,
                            'quantity_available' => Product::UNITS_PER_PRODUCT_ID,
                            'inventory_status' => 'in_stock',
                            'memo' => $parsed['note'].' 未照合のため要確認。',
                        ]);
                        app(ProductInventoryEventService::class)->recordOpening(
                            $product,
                            $user,
                            'external_sales_import',
                            (int) $product->id,
                            $parsed['sold_at'],
                            '外部売上CSVから作成した商品の初期在庫',
                        );
                        $amounts = [
                            'sold_price' => $parsed['sold_price'],
                            'cost_basis' => 0,
                            'sales_fee' => $parsed['sales_fee'],
                            'shipping_fee' => $parsed['shipping_fee'],
                        ];
                        $profit = 0;
                        $sale = $user->sales()->create([
                            'product_id' => $product->id,
                            'marketplace_id' => $marketplace->id,
                            'internal_sku_snapshot' => $product->internal_sku,
                            'product_name_snapshot' => $product->product_name,
                            'quantity' => $parsed['quantity'],
                            'sold_price' => $parsed['sold_price'],
                            'sold_at' => $parsed['sold_at'],
                            'status' => 'pending',
                            'cost_basis' => 0,
                            'sales_fee' => $parsed['sales_fee'],
                            'shipping_fee' => $parsed['shipping_fee'],
                            'net_profit' => $profit,
                            'completed_at' => null,
                        ]);
                        app(ProductInventoryEventService::class)->applyDelta(
                            $product,
                            -Product::UNITS_PER_PRODUCT_ID,
                            ProductInventoryEvent::TYPE_SALE,
                            'external_sales_import',
                            (int) $sale->id,
                            $user,
                            $parsed['sold_at'],
                            '外部売上CSVに基づく在庫払出',
                        );

                        return true;
                    });
                    $created ? $result['imported']++ : $result['skipped']++;
                } catch (RuntimeException $exception) {
                    $result['errors'][] = '行'.$line.': '.$exception->getMessage();
                }
            }

            if ($dataRows === 0) {
                throw new RuntimeException('CSVに売上明細がありません。明細行を含むレポートを再ダウンロードしてください。');
            }

            return $result;
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, string> $data @return array{management_id:string,title:string,sold_price:int,sales_fee:int,shipping_fee:int,sold_at:CarbonImmutable,note:string}|null */
    private function parseYahoo(array $data): ?array
    {
        if (($data['状態'] ?? '') !== '売上金' || ($data['商品ID'] ?? '') === '-' || ($data['商品ID'] ?? '') === '' || ($data['取扱内容'] ?? '') === '') {
            return null;
        }
        $managementId = $this->productText($data['商品ID'], '商品ID');
        $title = $this->productText($data['取扱内容'], '商品名');
        $soldAt = $this->date($data['取扱日'] ?? '');
        if ($soldAt === null) {
            throw new RuntimeException('売上行の取扱日が空欄または不正です。');
        }
        $soldPrice = $this->amount($data['決済金額'] ?? '', '決済金額', true);
        if ($soldPrice <= 0) {
            throw new RuntimeException('売上行の決済金額は1円以上で入力してください。');
        }
        $auctionFee = $this->amount($data['落札システム利用料'] ?? '', '落札システム利用料');
        $salesFee = $this->amount($data['販売手数料'] ?? '', '販売手数料');
        $shippingFee = $this->amount($data['送料'] ?? '', '送料');

        return [
            'management_id' => $managementId, 'title' => $title, 'quantity' => Product::UNITS_PER_PRODUCT_ID, 'sold_price' => $soldPrice,
            'sales_fee' => $auctionFee + $salesFee,
            'shipping_fee' => $shippingFee, 'sold_at' => $soldAt,
            'note' => 'ヤフオク売上CSVから取り込み。仕入原価はCSVにないため未設定。',
        ];
    }

    /** @param array<string, string> $data @return array{management_id:string,title:string,sold_price:int,sales_fee:int,shipping_fee:int,sold_at:CarbonImmutable,note:string}|null */
    private function parseMercari(array $data): ?array
    {
        if (($data['明細種別'] ?? '') !== '売上' || ($data['キャンセル日'] ?? '') !== '') {
            return null;
        }

        $orderId = trim($data['注文番号'] ?? '');
        $detailId = trim($data['明細番号'] ?? '');
        $title = trim($data['商品名'] ?? '');
        if ($orderId === '' || $detailId === '' || $title === '') {
            throw new RuntimeException('売上行の注文番号・明細番号・商品名が不足しています。');
        }
        $managementId = $this->productText($orderId.'-'.$detailId, '注文番号と明細番号');
        $title = $this->productText($title, '商品名');
        $soldAt = $this->date($data['購入日'] ?? '');
        if ($soldAt === null) {
            throw new RuntimeException('売上行の購入日が空欄または不正です。');
        }
        $soldPrice = $this->amount($data['売上（税込）'] ?? '', '売上（税込）', true);
        if ($soldPrice <= 0) {
            throw new RuntimeException('売上行の売価は1円以上で入力してください。');
        }
        $salesFee = $this->amount($data['販売手数料（税込）'] ?? '', '販売手数料（税込）');
        $mercariShipping = $this->amount($data['メルカリ便送料（税込）'] ?? '', 'メルカリ便送料（税込）');
        $shipping = $this->amount($data['送料（税込）'] ?? '', '送料（税込）');

        return [
            'management_id' => $managementId, 'title' => $title, 'quantity' => $this->quantity($data['数量'] ?? ''), 'sold_price' => $soldPrice,
            'sales_fee' => $salesFee,
            'shipping_fee' => $mercariShipping + $shipping,
            'sold_at' => $soldAt, 'note' => 'メルカリShops CSVから取り込み。仕入原価はCSVにないため未設定。',
        ];
    }

    /** @param array<string,string> $data @return array{management_id:string,title:string,sold_price:string} */
    private function errorPreviewValues(array $data, string $format): array
    {
        if ($format === 'yahoo_auctions') {
            return [
                'management_id' => $data['商品ID'] ?? '',
                'title' => $data['取扱内容'] ?? '',
                'sold_price' => $data['決済金額'] ?? '',
            ];
        }

        return [
            'management_id' => trim(($data['注文番号'] ?? '').'-'.($data['明細番号'] ?? ''), '-'),
            'title' => $data['商品名'] ?? '',
            'sold_price' => $data['売上（税込）'] ?? '',
        ];
    }

    private function quantity(string $value): int
    {
        $value = mb_convert_kana(trim($value), 'n');
        if (preg_match('/^(?:[1-9]\d*|[1-9]\d{0,2}(?:,\d{3})+)$/D', $value) !== 1) {
            return 0;
        }

        $quantity = filter_var(str_replace(',', '', $value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $quantity === false ? 0 : $quantity;
    }

    /**
     * @param  array{management_id:string,title:string,quantity:int,sold_price:int,sales_fee:int,shipping_fee:int,sold_at:CarbonImmutable}  $left
     * @param  array{management_id:string,title:string,quantity:int,sold_price:int,sales_fee:int,shipping_fee:int,sold_at:CarbonImmutable}  $right
     */
    private function sameSaleImport(array $left, array $right): bool
    {
        foreach (['management_id', 'title', 'quantity', 'sold_price', 'sales_fee', 'shipping_fee'] as $field) {
            if (($left[$field] ?? null) !== ($right[$field] ?? null)) {
                return false;
            }
        }

        return $left['sold_at']->equalTo($right['sold_at']);
    }

    private function cell(mixed $value): string
    {
        $value = trim(str_replace("\0", '', (string) $value));
        if (mb_strlen($value) > self::MAX_CELL_LENGTH) {
            throw new RuntimeException('セルの文字数が上限を超えています。');
        }

        return $value;
    }

    private function productText(string $value, string $label): string
    {
        if (mb_strlen($value) > 255) {
            throw new RuntimeException($label.'が保存可能な文字数（255文字）を超えています。');
        }

        return $value;
    }

    private function amount(string $value, string $label, bool $required = false): int
    {
        $amount = $this->money($value, $required);
        if ($amount === null) {
            throw new RuntimeException($label.'が空欄または不正です。金額を確認してください。');
        }

        return $amount;
    }

    private function money(string $value, bool $required = false): ?int
    {
        $value = mb_convert_kana(trim($value), 'n');
        if ($value === '' || $value === '-') {
            return $required ? null : 0;
        }

        if (preg_match('/^(?:[¥￥]\s*)?((?:\d+|\d{1,3}(?:,\d{3})+))(?:\s*円)?$/uD', $value, $matches) !== 1) {
            return null;
        }

        $normalized = str_replace(',', '', $matches[1]);
        $digits = ltrim($normalized, '0');
        $digits = $digits === '' ? '0' : $digits;
        $amount = filter_var($digits, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $amount === false || $amount > SaleProfitCalculator::MAX_UNSIGNED_AMOUNT ? null : $amount;
    }

    private function date(string $value): ?CarbonImmutable
    {
        $value = mb_convert_kana(trim($value), 'n');
        if ($value === '') {
            return null;
        }

        $normalized = str_replace(['年', '月', '日', '時', '分'], ['-', '-', '', ':', ''], $value);
        $normalized = preg_replace('/[\/\.]/', '-', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?? $normalized;

        if (preg_match('/^(?<year>\d{4})-(?<month>\d{1,2})-(?<day>\d{1,2})(?:[ T](?<hour>\d{1,2}):(?<minute>\d{2})(?::(?<second>\d{2}))?)?$/D', $normalized, $parts) !== 1) {
            return null;
        }

        $year = (int) $parts['year'];
        $month = (int) $parts['month'];
        $day = (int) $parts['day'];
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        if (isset($parts['hour']) && ((int) $parts['hour'] > 23 || (int) $parts['minute'] > 59 || (isset($parts['second']) && (int) $parts['second'] > 59))) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, config('app.timezone'))?->startOfDay();
    }
}
