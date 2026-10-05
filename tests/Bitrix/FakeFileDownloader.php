<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Bitrix;

use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Bitrix\FileDownloader;

final class FakeFileDownloader implements FileDownloader
{
    public array $urls = [];
    public ?string $name = null;
    public array $contents = [];             // url => содержимое (по умолчанию "BODY:<url>")
    public array $throwOnUrl = [];           // url => B24ApiException
    public ?B24ApiException $throw = null;

    public function download(string $url, string $fallbackName): array
    {
        $this->urls[] = $url;

        if ($this->throw !== null) {
            throw $this->throw;
        }

        if (isset($this->throwOnUrl[$url])) {
            throw $this->throwOnUrl[$url];
        }

        return ['name' => $this->name ?? $fallbackName, 'content' => $this->contents[$url] ?? "BODY:{$url}"];
    }
}
