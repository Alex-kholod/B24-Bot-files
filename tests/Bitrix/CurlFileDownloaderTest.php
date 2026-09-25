<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Bitrix;

use B24DocsBot\Bitrix\CurlFileDownloader;
use PHPUnit\Framework\TestCase;

final class CurlFileDownloaderTest extends TestCase
{
    public function testParsesQuotedFilename(): void
    {
        self::assertSame('a b.pdf', CurlFileDownloader::nameFromDisposition('attachment; filename="a b.pdf"'));
    }

    public function testParsesUtf8ExtendedFilename(): void
    {
        $header = "attachment; filename*=UTF-8''%D0%A2%D0%B5%D1%81%D1%82.pdf";

        self::assertSame('Тест.pdf', CurlFileDownloader::nameFromDisposition($header));
    }

    public function testEmptyWhenNoFilename(): void
    {
        self::assertSame('', CurlFileDownloader::nameFromDisposition('inline'));
    }

    public function testSanitizeStripsPathSeparators(): void
    {
        self::assertSame('_x.pdf', CurlFileDownloader::sanitizeName('../x.pdf'));
        self::assertSame('a_b.pdf', CurlFileDownloader::sanitizeName('a\\b.pdf'));
    }
}
