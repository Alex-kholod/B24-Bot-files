<?php

declare(strict_types=1);

namespace B24DocsBot\Bitrix;

final class CurlFileDownloader implements FileDownloader
{
    private const MAX_BYTES = 40 * 1024 * 1024;

    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/zip' => 'zip',
        'text/plain' => 'txt',
    ];

    public function download(string $url, string $fallbackName): array
    {
        $headers = [];
        $handle = curl_init($url);

        if ($handle === false) {
            throw new B24ApiException('Не удалось инициализировать скачивание файла', 'NETWORK_ERROR');
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static fn ($r, $total, $downloaded): int => $downloaded > self::MAX_BYTES ? 1 : 0,
            CURLOPT_HEADERFUNCTION => static function ($r, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body) || $status !== 200) {
            throw new B24ApiException(
                sprintf('Не удалось скачать файл: HTTP %d %s', $status, $error),
                $status >= 500 || $status === 0 ? 'NETWORK_ERROR' : ''
            );
        }

        $contentType = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));

        if ($contentType === 'application/json') {
            // Битрикс24 отдаёт ошибки (например expired_token) телом JSON со статусом 200.
            throw new B24ApiException('Не удалось скачать файл: ' . mb_substr($body, 0, 200), '');
        }

        $name = self::sanitizeName(self::nameFromDisposition($headers['content-disposition'] ?? ''));

        if ($name === '') {
            $name = self::sanitizeName($fallbackName);
        }

        if (!str_contains($name, '.') && isset(self::EXTENSIONS[$contentType])) {
            $name .= '.' . self::EXTENSIONS[$contentType];
        }

        return ['name' => $name, 'content' => $body];
    }

    public static function nameFromDisposition(string $header): string
    {
        if (preg_match("/filename\\*\\s*=\\s*[^']*'[^']*'([^;]+)/i", $header, $m) === 1) {
            return rawurldecode(trim($m[1], " \t\""));
        }

        if (preg_match('/filename\s*=\s*"([^"]+)"/i', $header, $m) === 1
            || preg_match('/filename\s*=\s*([^;]+)/i', $header, $m) === 1) {
            return trim($m[1]);
        }

        return '';
    }

    public static function sanitizeName(string $name): string
    {
        $name = preg_replace('/[\\\\\/\x00-\x1F]+/u', '_', $name) ?? '';

        return trim($name, " .\t");
    }
}
