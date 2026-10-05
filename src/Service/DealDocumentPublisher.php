<?php

declare(strict_types=1);

namespace B24DocsBot\Service;

use B24DocsBot\Bitrix\B24Api;
use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Bitrix\FileDownloader;
use B24DocsBot\Storage\PinnedCommentRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

final class DealDocumentPublisher
{
    // Битрикс24 разрешает закрепить в таймлайне не больше трёх записей на сущность.
    private const MAX_PINNED = 3;

    public function __construct(
        private readonly B24Api $api,
        private readonly FileDownloader $downloader,
        private readonly PinnedCommentRepository $pinned,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Скачивает файл по одноразовой ссылке (она быстро протухает), добавляет в таймлайн
     * сделки комментарий с файлом и закрепляет его. Закрепление — best effort: комментарий
     * к этому моменту уже создан, и повтор строки очереди создал бы дубль.
     */
    public function publish(int $dealId, int $chatFileId, string $fallbackName, DateTimeImmutable $now): int
    {
        $url = $this->api->getChatFileDownloadUrl($chatFileId);
        $file = $this->downloader->download($url, $fallbackName !== '' ? $fallbackName : "file-{$chatFileId}");

        $commentId = $this->api->addDealTimelineComment(
            $dealId,
            'Документ от клиента — ' . $now->format('d.m.Y H:i'),
            $file['name'],
            $file['content']
        );

        $this->pin($dealId, $commentId, $now);

        return $commentId;
    }

    private function pin(int $dealId, int $commentId, DateTimeImmutable $now): void
    {
        try {
            $mine = $this->pinned->forDeal($dealId);

            // Освобождаем место, открепляя самые старые из закреплённых ботом комментариев.
            while (count($mine) >= self::MAX_PINNED) {
                $oldest = array_shift($mine);
                $this->pinned->remove($oldest);

                try {
                    $this->api->unpinTimelineItem($oldest, $dealId);
                } catch (B24ApiException $exception) {
                    $this->logger->warning('Не удалось открепить старый комментарий', [
                        'deal_id' => $dealId,
                        'comment_id' => $oldest,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $this->api->pinTimelineItem($commentId, $dealId);
            $this->pinned->add($dealId, $commentId, $now);
        } catch (B24ApiException $exception) {
            $this->logger->warning('Комментарий добавлен, но не закреплён', [
                'deal_id' => $dealId,
                'comment_id' => $commentId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
