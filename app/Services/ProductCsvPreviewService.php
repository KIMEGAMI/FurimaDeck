<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class ProductCsvPreviewService
{
    private const REQUIRED_HEADERS = ['internal_sku', 'product_name', 'condition', 'purchase_unit_cost', 'purchase_quantity', 'quantity_available', 'inventory_status'];

    private const OPTIONAL_HEADERS = [
        'description_base',
        'purchase_date',
        'purchase_shipping_cost',
        'other_purchase_expense',
        'jan_ean',
        'isbn',
        'manufacturer_model_number',
        'serial_number',
        'storage_location',
        'memo',
    ];

    /** @return array{headers: array<int, string>, rows: array<int, array{row_number: int, values: array<string, string>, errors: array<int, string>}>} */
    public function preview(UploadedFile $file): array
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || ! mb_check_encoding($contents, 'UTF-8')) {
            throw new RuntimeException('CSVはUTF-8形式で保存してください。');
        }

        $handle = fopen($file->getRealPath(), 'rb');

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
            if (count($headers) !== count(array_unique($headers))) {
                throw new RuntimeException('CSVヘッダーに重複があります。');
            }
            $unexpectedHeaders = array_diff($headers, [...self::REQUIRED_HEADERS, ...self::OPTIONAL_HEADERS]);
            if ($unexpectedHeaders !== []) {
                throw new RuntimeException('未対応の列があります: '.implode(', ', $unexpectedHeaders));
            }
            $missingHeaders = array_diff(self::REQUIRED_HEADERS, $headers);
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
                    $rows[$index]['errors'][] = 'CSV内でSKUが重複しています。';
                }
            }

            return ['headers' => $headers, 'rows' => $rows];
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, string> $values @return array<int, string> */
    private function errors(array $values): array
    {
        $errors = [];
        $maxCellCharacters = (int) config('furimadeck.csv_import.max_cell_characters');
        foreach ($values as $key => $value) {
            if (mb_strlen($value) > $maxCellCharacters) {
                $errors[] = $key.'が長すぎます。';
            }
        }
        foreach (['internal_sku' => 'SKU', 'product_name' => '商品名'] as $key => $label) {
            if (trim($values[$key] ?? '') === '') {
                $errors[] = $label.'は必須です。';
            }
        }
        if (! in_array($values['condition'] ?? '', Product::CONDITIONS, true)) {
            $errors[] = '商品状態が不正です。';
        }
        if (! in_array($values['inventory_status'] ?? '', Product::INVENTORY_STATUSES, true)) {
            $errors[] = '在庫状態が不正です。';
        }
        foreach (['purchase_unit_cost', 'quantity_available'] as $key) {
            if (filter_var($values[$key] ?? null, FILTER_VALIDATE_INT) === false || (int) $values[$key] < 0) {
                $errors[] = $key.'は0以上の整数にしてください。';
            }
        }
        if (filter_var($values['purchase_quantity'] ?? null, FILTER_VALIDATE_INT) === false || (int) $values['purchase_quantity'] < 1) {
            $errors[] = 'purchase_quantityは1以上の整数にしてください。';
        }
        foreach (['purchase_shipping_cost', 'other_purchase_expense'] as $key) {
            if (($values[$key] ?? '') !== '' && (filter_var($values[$key], FILTER_VALIDATE_INT) === false || (int) $values[$key] < 0)) {
                $errors[] = $key.'は0以上の整数にしてください。';
            }
        }
        if (($values['purchase_date'] ?? '') !== '' && ! $this->isIsoDate($values['purchase_date'])) {
            $errors[] = 'purchase_dateはYYYY-MM-DD形式にしてください。';
        }

        return $errors;
    }

    private function isIsoDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
