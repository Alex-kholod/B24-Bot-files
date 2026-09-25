<?php

declare(strict_types=1);

namespace B24DocsBot\Bitrix;

interface FileDownloader
{
    /**
     * Скачивает файл по одноразовой ссылке.
     *
     * @return array{name: string, content: string} имя из заголовков ответа (или $fallbackName) и тело файла
     */
    public function download(string $url, string $fallbackName): array;
}
