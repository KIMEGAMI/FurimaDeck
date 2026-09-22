<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class ProductCsvPreviewService
{
    public function __construct(private readonly CsvEncodingService $encoding) {}

    private const PRODUCT_ID_HEADER = 'product_id';

    private const LEGACY_PRODUCT_ID_HEADER = 'internal_sku';

    private const REQUIRED_HEADERS = ProductCsvSchema::REQUIRED_PRODUCT_HEADERS;

    private const OPTIONAL_HEADERS = ProductCsvSchema::ALL_SUPPORTED_HEADERS;

    private const HEADER_ALIASES = [
        'management_id' => 'internal_sku',
        'purchase_price' => 'purchase_unit_cost',
        '商品ID' => 'internal_sku',
        '管理ID' => 'internal_sku',
        'title' => 'product_name',
        '商品タイトル' => 'product_name',
        'タイトル' => 'product_name',
        '大ジャンル' => 'parent_category',
        '小ジャンル' => 'category',
        '出品先' => 'platform',
        'ステータス' => 'status',
        '仕入れ値' => 'purchase_unit_cost',
        '仕入値' => 'purchase_unit_cost',
        '販売価格' => 'sold_price',
        '売値' => 'sold_price',
        '販売手数料率' => 'sales_fee_rate',
        '販売手数料' => 'sales_fee',
        '送料' => 'shipping_fee',
        '実利益' => 'net_profit',
        'profit' => 'net_profit',
        'SOLD日' => 'sold_at',
        '商品画像URL' => 'image_url',
        'SOLD画像URL' => 'sold_image_url',
        'コメント' => 'comment',
        '作成日' => 'created_at',
        '更新日' => 'updated_at',
    ];

    /** @return array{headers: array<int, string>, rows: array<int, array{row_number: int, values: array<string, string>, errors: array<int, string>}>} */
    public function preview(UploadedFile $file): array
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

            $headers = array_map(fn ($header) => trim((string) $header), $headers);
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]) ?? $headers[0];
            $legacyProductCsv = count(array_intersect($headers, ['product_id', 'management_id', '商品ID', '管理ID'])) > 0;
            $headers = array_map(
                fn (string $header) => self::HEADER_ALIASES[$header] ?? ($header === self::PRODUCT_ID_HEADER ? self::LEGACY_PRODUCT_ID_HEADER : $header),
                $headers,
            );
            if (count($headers) !== count(array_unique($headers))) {
                throw new RuntimeException('CSVヘッダーに重複があります。');
            }
            $unexpectedHeaders = array_diff($headers, [...self::REQUIRED_HEADERS, ...self::OPTIONAL_HEADERS]);
            if ($unexpectedHeaders !== []) {
                throw new RuntimeException('未対応の列があります: '.implode(', ', $unexpectedHeaders));
            }
            // Legacy product-only CSVs may omit the newer product fields.
            $missingHeaders = array_diff(['internal_sku', 'product_name'], $headers);
            if ($missingHeaders !== []) {
                throw new RuntimeException('必須列が不足しています: '.implode(', ', $missingHeaders));
            }

            $rows = [];
            $skuRows = [];
            $rowNumber = 1;
            $maxRows = (int) config('furimadeck.csv_import.max_rows');
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                if (count($rows) >= $maxRows) {
                    throw new RuntimeException('CSVは'.$maxRows.'行までです。');
                }
                if (count($row) > count($headers)) {
                    $rows[] = ['row_number' => $rowNumber, 'values' => [], 'errors' => ['列数がヘッダーより多くなっています。']];

                    continue;
                }

                $values = array_combine($headers, array_map(fn ($value) => trim((string) $value), array_pad($row, count($headers), '')));
                if ($values === false || collect($values)->every(fn (string $value) => $value === '')) {
                    continue;
                }
                if ($legacyProductCsv) {
                    $values['__legacy_product_csv'] = '1';
                }
                $values = $this->withDefaults($values);
                $errors = $this->errors($values);
                $rows[] = ['row_number' => $rowNumber, 'values' => $values, 'errors' => $errors];
                $sku = $values['internal_sku'] ?? '';
                if ($sku !== '') {
                    $skuRows[$sku][] = array_key_last($rows);
                }
            }

            foreach ($skuRows as $rowIndexes) {
                if (count($rowIndexes) < 2) {
                    continue;
                }
                foreach ($rowIndexes as $index) {
                    $rows[$index]['errors'][] = 'CSV内で商品IDが重複しています。';
                }
            }

            return ['headers' => $headers, 'rows' => $rows];
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, string> $values @return array<string, string> */
    private function withDefaults(array $values): array
    {
        $values['condition'] = $values['condition'] ?? 'used';
        $values['purchase_unit_cost'] = $values['purchase_unit_cost'] ?? '0';
        $values['purchase_quantity'] = $values['purchase_quantity'] ?? '1';
        $explicitSaleState = trim((string) ($values['sale_state'] ?? ''));
        $values['sale_state'] = strtolower($explicitSaleState !== '' ? $explicitSaleState : 'stock');
        $values['marketplace_code'] = trim((string) ($values['marketplace_code'] ?? ''));
        $values['quantity_available'] = $values['quantity_available'] ?? '1';
        $inventoryStatus = strtolower(trim((string) ($values['inventory_status'] ?? '')));
        if (($values['__legacy_product_csv'] ?? '') === '1' && in_array($inventoryStatus, ['draft', 'listing_ready', 'listed', 'reserved', 'sold', 'archived'], true)) {
            $values['inventory_status'] = Product::inventoryStatusForQuantity((int) $values['quantity_available']);
        } elseif ($inventoryStatus === '') {
            $values['inventory_status'] = Product::inventoryStatusForQuantity((int) $values['quantity_available']);
        }
        if ($values['sale_state'] === 'sold' && ($values['sale_quantity'] ?? '') === '') {
            $values['sale_quantity'] = (string) max(1, (int) $values['purchase_quantity'] - (int) $values['quantity_available']);
        }

        return $values;
    }

    /** @param array<string, string> $values @return array<int, string> */
    private function errors(array $values): array
    {
        $errors = [];
        $maxCellCharacters = (int) config('furimadeck.csv_import.max_cell_characters');
        foreach ($values as $key => $value) {
            if (str_starts_with($key, '__')) {
                continue;
            }
            if (mb_strlen($value) > $maxCellCharacters) {
                $errors[] = $key.'が長すぎます。';
            }
        }
        foreach (['internal_sku' => '商品ID', 'product_name' => '商品名'] as $key => $label) {
            if (trim($values[$key] ?? '') === '') {
                $errors[] = $label.'は必須です。';
            }
        }
        if (! in_array($values['condition'] ?? '', Product::CONDITIONS, true)) {
            $errors[] = '商品状態が不正です。';
        }
        if (! in_array($values['inventory_status'] ?? '', Product::INVENTORY_STATUSES, true)) {
            $errors[] = '在庫状態が不正です。';
        } elseif ($values['inventory_status'] !== Product::inventoryStatusForQuantity((int) ($values['quantity_available'] ?? 0))) {
            $errors[] = 'quantity_availableとinventory_statusが一致していません。';
        }
        foreach (['purchase_unit_cost', 'quantity_available'] as $key) {
            if (filter_var($values[$key] ?? null, FILTER_VALIDATE_INT) === false || (int) $values[$key] < 0) {
                $errors[] = $key.'は0以上の整数にしてください。';
            }
        }
        if (filter_var($values['purchase_quantity'] ?? null, FILTER_VALIDATE_INT) === false || (int) $values['purchase_quantity'] < 1) {
            $errors[] = 'purchase_quantityは1以上の整数にしてください。';
        }
        if (trim($values['parent_category'] ?? '') !== '' || trim($values['category'] ?? '') !== '') {
            if ($this->categoryId($values) === null) {
                $errors[] = '大ジャンル・小ジャンルの組み合わせが見つかりません。';
            }
        }
        foreach (['category_id', 'supplier_id'] as $key) {
            if (($values[$key] ?? '') !== '' && (filter_var($values[$key], FILTER_VALIDATE_INT) === false || (int) $values[$key] < 1)) {
                $errors[] = $key.'は1以上の整数にしてください。';
            }
        }
        foreach (['purchase_shipping_cost', 'other_purchase_expense', 'listing_price'] as $key) {
            if (($values[$key] ?? '') !== '' && (filter_var($values[$key], FILTER_VALIDATE_INT) === false || (int) $values[$key] < 0)) {
                $errors[] = $key.'は0以上の整数にしてください。';
            }
        }
        if (($values['purchase_date'] ?? '') !== '' && ! $this->isIsoDate($values['purchase_date'])) {
            $errors[] = 'purchase_dateはYYYY-MM-DD形式にしてください。';
        }
        if (! in_array($values['sale_state'] ?? 'stock', ['stock', 'sold'], true)) {
            $errors[] = 'sale_stateはstockまたはsoldにしてください。';
        }
        if (($values['sale_state'] ?? 'stock') === 'sold') {
            foreach (['marketplace_code', 'listed_at', 'listing_price', 'sale_quantity', 'sold_price', 'sold_at'] as $key) {
                if (trim((string) ($values[$key] ?? '')) === '') {
                    $errors[] = $key.'はsale_state=soldの場合に必須です。';
                }
            }
            if (filter_var($values['sale_quantity'] ?? null, FILTER_VALIDATE_INT) === false || (int) $values['sale_quantity'] < 1) {
                $errors[] = 'sale_quantityは1以上の整数にしてください。';
            }
            if (filter_var($values['sold_price'] ?? null, FILTER_VALIDATE_INT) === false || (int) $values['sold_price'] < 0) {
                $errors[] = 'sold_priceは0以上の整数にしてください。';
            }
            if (($values['sold_at'] ?? '') === '' || ! $this->isIsoDate($values['sold_at'])) {
                $errors[] = 'sold_atはYYYY-MM-DD形式で必須です。';
            }
            if (! in_array($values['marketplace_code'] ?? '', ProductCsvSchema::MARKETPLACE_CODES, true)) {
                $errors[] = 'marketplace_codeが不正です。';
            }
        } elseif (($values['sale_state'] ?? 'stock') === 'stock') {
            foreach (ProductCsvSchema::SALE_HEADERS as $key) {
                if (($values[$key] ?? '') !== '') {
                    $errors[] = 'sale_state=stockでは'.$key.'を空欄にしてください。';
                }
            }
        }
        foreach (['sale_quantity', 'sold_price', 'sales_fee', 'shipping_fee', 'sale_purchase_shipping_cost', 'packing_cost', 'repair_cost', 'cleaning_cost', 'sale_other_expense'] as $key) {
            if (($values[$key] ?? '') !== '' && (filter_var($values[$key], FILTER_VALIDATE_INT) === false || (int) $values[$key] < 0)) {
                $errors[] = $key.'は0以上の整数にしてください。';
            }
        }
        if (($values['sale_quantity'] ?? '') !== '' && (int) $values['sale_quantity'] > (int) $values['purchase_quantity']) {
            $errors[] = 'sale_quantityはpurchase_quantityを超えられません。';
        }
        if ((int) ($values['quantity_available'] ?? 0) + (int) ($values['sale_quantity'] ?? 0) > (int) ($values['purchase_quantity'] ?? 0)) {
            $errors[] = 'quantity_availableとsale_quantityの合計がpurchase_quantityを超えています。';
        }

        $purchaseDate = trim((string) ($values['purchase_date'] ?? ''));
        $listedAt = trim((string) ($values['listed_at'] ?? ''));
        $soldAt = trim((string) ($values['sold_at'] ?? ''));
        if ($listedAt !== '' && ! $this->isIsoDate($listedAt)) {
            $errors[] = 'listed_atはYYYY-MM-DD形式にしてください。';
        }
        if ($purchaseDate !== '' && $listedAt !== '' && $purchaseDate > $listedAt) {
            $errors[] = 'purchase_dateはlisted_at以前でなければなりません。';
        }
        if ($soldAt !== '' && ! $this->isIsoDate($soldAt)) {
            $errors[] = 'sold_atはYYYY-MM-DD形式にしてください。';
        }
        if ($listedAt !== '' && $soldAt !== '' && $listedAt > $soldAt) {
            $errors[] = 'listed_atはsold_at以前でなければなりません。';
        }
        if ($listedAt === '' && (($values['marketplace_code'] ?? '') !== '' || ($values['listing_price'] ?? '') !== '')) {
            $errors[] = 'listed_atが空欄の場合、marketplace_codeとlisting_priceも空欄にしてください。';
        }
        if ($listedAt !== '' && ! in_array($values['marketplace_code'] ?? '', ProductCsvSchema::MARKETPLACE_CODES, true)) {
            $errors[] = 'marketplace_codeが不正です。';
        }
        if ($listedAt !== '') {
            if (($values['marketplace_code'] ?? '') === '') {
                $errors[] = 'marketplace_codeはlisted_atがある場合に必須です。';
            }
            if (($values['listing_price'] ?? '') === '') {
                $errors[] = 'listing_priceはlisted_atがある場合に必須です。';
            }
        }
        if (($values['sale_state'] ?? 'stock') === 'sold' && (int) ($values['quantity_available'] ?? 0) !== (int) ($values['purchase_quantity'] ?? 0) - (int) ($values['sale_quantity'] ?? 0)) {
            $errors[] = 'sold行ではquantity_availableがpurchase_quantity-sale_quantityと一致しなければなりません。';
        }

        return $errors;
    }

    /** @param array<string,string> $values */
    private function categoryId(array $values): ?int
    {
        $parentName = trim($values['parent_category'] ?? '');
        $categoryName = trim($values['category'] ?? '');
        if ($parentName === '' && $categoryName === '') {
            return ($values['category_id'] ?? '') !== '' ? (int) $values['category_id'] : null;
        }
        if ($categoryName === '') {
            return null;
        }
        $query = ProductCategory::query()->where('name', $categoryName);
        if ($parentName === '') {
            $query->whereNull('parent_id');
        } else {
            $query->whereHas('parent', fn ($parent) => $parent->where('name', $parentName));
        }

        return ($category = $query->first()) === null ? null : (int) $category->id;
    }

    private function isIsoDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
