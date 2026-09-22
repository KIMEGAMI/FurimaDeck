<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class CsvEncodingService
{
    private const DETECTION_ORDER = ['UTF-8', 'SJIS-win', 'CP932', 'EUC-JP'];

    public function toUtf8(UploadedFile $file): string
    {
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw new RuntimeException('CSVファイルを読み込めませんでした。');
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $encoding = mb_detect_encoding($contents, self::DETECTION_ORDER, true);
        if ($encoding === false) {
            throw new RuntimeException('対応していない文字コードです。UTF-8、CP932、Shift_JIS、EUC-JPで保存してください。');
        }

        $converted = $encoding === 'UTF-8' ? $contents : mb_convert_encoding($contents, 'UTF-8', $encoding);
        if ($converted === false || ! mb_check_encoding($converted, 'UTF-8')) {
            throw new RuntimeException('CSVの文字コードを変換できませんでした。');
        }

        $this->assertWellFormedCsv($converted);

        return $converted;
    }

    private function assertWellFormedCsv(string $contents): void
    {
        $inQuotes = false;
        $length = strlen($contents);
        for ($index = 0; $index < $length; $index++) {
            if ($contents[$index] !== '"') {
                continue;
            }
            if ($inQuotes && $index + 1 < $length && $contents[$index + 1] === '"') {
                $index++;

                continue;
            }
            $inQuotes = ! $inQuotes;
        }
        if ($inQuotes) {
            throw new RuntimeException('CSVの引用符が閉じられていません。ファイル形式を確認してください。');
        }
    }
}
