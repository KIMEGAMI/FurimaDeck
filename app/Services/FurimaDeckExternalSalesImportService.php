<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FurimaDeckExternalSalesImportService
{
    private const MAX_ROWS = 5000;

    private const MAX_CELL_LENGTH = 1000;

    private const YAHOO_HEADERS = ['取扱内容', '商品ID', '取扱日', '状態', '決済金額', '送料'];

    private const MERCARI_HEADERS = ['注文番号', '明細番号', '購入日', '商品名', '売上（税込）', 'メルカリ便送料（税込）', '送料（税込）', '販売手数料（税込）', '販売手数料率（%）', 'ショップ名'];

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
                $row = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
                $values = array_combine($headers, array_map(fn (mixed $value): string => $this->cell($value), array_pad($row, count($headers), '')));
                if ($values === false) {
                    $rows[] = ['row_number' => $line, 'values' => [], 'errors' => ['列を読み取れませんでした。']];

                    continue;
                }
                $parsed = $format === 'yahoo_auctions' ? $this->parseYahoo($values) : $this->parseMercari($values);
                if ($parsed === null) {
                    $skipped++;

                    continue;
                }
                $key = $parsed['management_id'];
                if (isset($seen[$key])) {
                    $skipped++;

                    continue;
                }
                $seen[$key] = true;
                $parsed['sold_at'] = $parsed['sold_at']->format('Y-m-d H:i:s');
                $rows[] = ['row_number' => $line, 'values' => $parsed, 'errors' => []];
            }

            return ['rows' => $rows, 'skipped' => $skipped];
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string,mixed> $parsed */
    public function commitParsed(User $user, array $parsed, string $format): string
    {
        $marketplaceCode = $format === 'yahoo_auctions' ? 'yahoo_auctions' : 'mercari';
        $marketplace = Marketplace::query()->where('code', $marketplaceCode)->where('is_active', true)->first();
        if ($marketplace === null) {
            throw new RuntimeException('対象の出品先が未登録または無効です。');
        }

        return DB::transaction(function () use ($user, $marketplace, $parsed): string {
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

            $product = $listing?->product;
            $matched = $product !== null;
            if ($product === null) {
                $product = Product::query()->where('user_id', $user->id)->where('internal_sku', $parsed['management_id'])->lockForUpdate()->first();
            }
            if ($product === null) {
                $product = $user->products()->create([
                    'internal_sku' => $parsed['management_id'],
                    'product_name' => $parsed['title'],
                    'condition' => 'used',
                    'purchase_unit_cost' => 0,
                    'purchase_quantity' => 1,
                    'quantity_available' => 0,
                    'inventory_status' => 'out_of_stock',
                    'memo' => '外部売上CSV未照合。仕入れ値と出品情報を確認してください。',
                ]);
            }
            $costBasis = $matched ? (int) $product->purchase_unit_cost : 0;
            $netProfit = $matched
                ? (int) $parsed['sold_price'] - $costBasis - (int) $parsed['sales_fee'] - (int) $parsed['shipping_fee']
                : 0;
            $user->sales()->create([
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
            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if ($line > (int) config('furimadeck.csv_import.max_rows', self::MAX_ROWS) + 1) {
                    throw new RuntimeException('CSVは'.(int) config('furimadeck.csv_import.max_rows', self::MAX_ROWS).'行までです。');
                }
                if (count(array_filter($row, fn (mixed $value): bool => trim((string) $value) !== '')) === 0) {
                    continue;
                }
                // Keep compatibility with exports that contain extra trailing columns.
                $row = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
                $values = array_combine($headers, array_map(fn (mixed $value): string => $this->cell($value), array_pad($row, count($headers), '')));
                if ($values === false) {
                    $result['errors'][] = '行'.$line.': 列を読み取れませんでした。';

                    continue;
                }
                $parsed = $format === 'yahoo_auctions'
                    ? $this->parseYahoo($values)
                    : $this->parseMercari($values);
                if ($parsed === null) {
                    $result['skipped']++;

                    continue;
                }
                try {
                    $created = DB::transaction(function () use ($user, $marketplace, $parsed): bool {
                        if (Product::query()->where('user_id', $user->id)->where('internal_sku', $parsed['management_id'])->exists()) {
                            return false;
                        }
                        $product = $user->products()->create([
                            'internal_sku' => $parsed['management_id'],
                            'product_name' => $parsed['title'],
                            'condition' => 'used',
                            'purchase_unit_cost' => 0,
                            'purchase_quantity' => 1,
                            'quantity_available' => 0,
                            'inventory_status' => 'out_of_stock',
                            'memo' => $parsed['note'].' 未照合のため要確認。',
                        ]);
                        $amounts = [
                            'sold_price' => $parsed['sold_price'],
                            'cost_basis' => 0,
                            'sales_fee' => $parsed['sales_fee'],
                            'shipping_fee' => $parsed['shipping_fee'],
                        ];
                        $profit = 0;
                        $user->sales()->create([
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
                        $product->update(['quantity_available' => 0, 'inventory_status' => 'out_of_stock']);

                        return true;
                    });
                    $created ? $result['imported']++ : $result['skipped']++;
                } catch (RuntimeException $exception) {
                    $result['errors'][] = '行'.$line.': '.$exception->getMessage();
                }
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
        $soldAt = $this->date($data['取扱日'] ?? '');
        $soldPrice = $this->money($data['決済金額'] ?? '');
        if ($soldAt === null || $soldPrice <= 0) {
            return null;
        }

        return [
            'management_id' => $data['商品ID'], 'title' => $data['取扱内容'], 'quantity' => 1, 'sold_price' => $soldPrice,
            'sales_fee' => $this->money($data['落札システム利用料'] ?? '') + $this->money($data['販売手数料'] ?? ''),
            'shipping_fee' => $this->money($data['送料'] ?? ''), 'sold_at' => $soldAt,
            'note' => 'ヤフオク売上CSVから取り込み。仕入原価はCSVにないため未設定。',
        ];
    }

    /** @param array<string, string> $data @return array{management_id:string,title:string,sold_price:int,sales_fee:int,shipping_fee:int,sold_at:CarbonImmutable,note:string}|null */
    private function parseMercari(array $data): ?array
    {
        $managementId = trim($data['注文番号'].'-'.$data['明細番号'], '-');
        $soldAt = $this->date($data['購入日'] ?? '');
        $soldPrice = $this->money($data['売上（税込）'] ?? '');
        if ($managementId === '' || ($data['商品名'] ?? '') === '' || ($data['キャンセル日'] ?? '') !== '' || $soldAt === null || $soldPrice <= 0) {
            return null;
        }

        return [
            'management_id' => mb_substr($managementId, 0, 255), 'title' => $data['商品名'], 'quantity' => $this->quantity($data['数量'] ?? ''), 'sold_price' => $soldPrice,
            'sales_fee' => $this->money($data['販売手数料（税込）'] ?? ''),
            'shipping_fee' => $this->money($data['メルカリ便送料（税込）'] ?? '') + $this->money($data['送料（税込）'] ?? ''),
            'sold_at' => $soldAt, 'note' => 'メルカリShops CSVから取り込み。仕入原価はCSVにないため未設定。',
        ];
    }

    private function quantity(string $value): int
    {
        $value = str_replace([',', ' '], '', mb_convert_kana($value, 'n'));

        return preg_match('/^[1-9]\d*$/', $value) === 1 ? min((int) $value, 1000000) : 1;
    }

    private function cell(mixed $value): string
    {
        $value = trim(str_replace("\0", '', (string) $value));

        return mb_substr($value, 0, self::MAX_CELL_LENGTH);
    }

    private function money(string $value): int
    {
        $value = mb_convert_kana(trim($value), 'n');
        if ($value === '' || $value === '-') {
            return 0;
        }

        $normalized = preg_replace('/[^\d.-]/u', '', str_replace([',', '円', '¥', '￥', ' '], '', $value)) ?? '';

        return $normalized !== '' && $normalized !== '-' ? max(0, (int) round((float) $normalized)) : 0;
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

        try {
            return CarbonImmutable::parse($normalized, config('app.timezone'))->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
