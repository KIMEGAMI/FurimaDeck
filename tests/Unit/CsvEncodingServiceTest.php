<?php

namespace Tests\Unit;

use App\Services\CsvEncodingService;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CsvEncodingServiceTest extends TestCase
{
    #[Test]
    public function it_strips_a_utf8_bom(): void
    {
        $file = UploadedFile::fake()->createWithContent('products.csv', "\xEF\xBB\xBFtitle\n商品");

        $result = app(CsvEncodingService::class)->toUtf8($file);

        $this->assertSame("title\n商品", $result);
    }

    #[Test]
    public function it_converts_cp932_csv_to_utf8(): void
    {
        $cp932 = mb_convert_encoding("title\n商品", 'CP932', 'UTF-8');
        $file = UploadedFile::fake()->createWithContent('products.csv', $cp932);

        $result = app(CsvEncodingService::class)->toUtf8($file);

        $this->assertSame("title\n商品", $result);
    }

    #[Test]
    public function it_rejects_unclosed_csv_quotes(): void
    {
        $file = UploadedFile::fake()->createWithContent('broken.csv', "title,comment\n商品,\"閉じていない\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CSVの引用符が閉じられていません');
        app(CsvEncodingService::class)->toUtf8($file);
    }
}
