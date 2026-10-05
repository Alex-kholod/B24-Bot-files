<?php

declare(strict_types=1);

namespace B24DocsBot\Service;

use B24DocsBot\Bitrix\B24Api;
use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Bitrix\FileDownloader;
use B24DocsBot\Storage\DealCommentRepository;
use Psr\Log\LoggerInterface;

final class DealDocumentPublisher
{
    public const COMMENT_TEXT = 'документы из чата с клиентом';

    public function __construct(
        private readonly B24Api $api,
        private readonly FileDownloader $downloader,
        private readonly DealCommentRepository $comments,
        private readonly LoggerInterface $logger,
        private readonly string $lockDir,
    ) {
    }

    /**
     * Кладёт файл в единственный закреплённый комментарий сделки: создаёт его при первом
     * документе, а при следующих заменяет файлы комментария итоговым набором.
     */
    public function publish(int $dealId, int $chatFileId, string $fallbackName): int
    {
        $lock = $this->lock($dealId);

        try {
            // Ссылка на файл чата одноразовая и быстро протухает — качаем сразу.
            $url = $this->api->getChatFileDownloadUrl($chatFileId);
            $new = $this->downloader->download($url, $fallbackName !== '' ? $fallbackName : "file-{$chatFileId}");

            $commentId = $this->comments->find($dealId);

            if ($commentId !== null) {
                $existing = $this->api->getTimelineComment($commentId);

                if ($existing !== null) {
                    // Битрикс24 удаляет файлы, которых нет в запросе обновления, поэтому старые
                    // файлы скачиваются и отправляются заново. Любой сбой здесь прерывает работу
                    // до обновления: иначе уже сохранённые документы клиента были бы потеряны.
                    $files = [];

                    foreach ($existing['files'] as $file) {
                        $oldUrl = $this->api->getDiskFileDownloadUrl($file['id']);
                        $files[] = [
                            'name' => $file['name'],
                            'content' => $this->downloader->download($oldUrl, $file['name'])['content'],
                        ];
                    }

                    $files[] = $new;
                    $this->api->updateTimelineCommentFiles($commentId, $dealId, self::COMMENT_TEXT, $files);

                    return $commentId;
                }

                // Комментарий удалили вручную — начинаем новый.
                $this->comments->forget($dealId);
            }

            $commentId = $this->api->addDealTimelineComment($dealId, self::COMMENT_TEXT, [$new]);
            $this->comments->save($dealId, $commentId);
            $this->pin($dealId, $commentId);

            return $commentId;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Закрепление — best effort: комментарий к этому моменту уже создан и записан, повтор
     * строки очереди создал бы второй комментарий. Битрикс24 разрешает закрепить не больше
     * трёх записей на сделку.
     */
    private function pin(int $dealId, int $commentId): void
    {
        try {
            $this->api->pinTimelineItem($commentId, $dealId);
        } catch (B24ApiException $exception) {
            $this->logger->warning('Комментарий добавлен, но не закреплён', [
                'deal_id' => $dealId,
                'comment_id' => $commentId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Два документа одной сделки, обработанные параллельно (вебхук и cron), иначе прочитали бы
     * один и тот же набор файлов и затёрли бы друг друга при обновлении.
     *
     * @return resource
     */
    private function lock(int $dealId)
    {
        if (!is_dir($this->lockDir) && !mkdir($this->lockDir, 0o775, true) && !is_dir($this->lockDir)) {
            throw new B24ApiException("Не удалось создать каталог блокировок: {$this->lockDir}", 'NETWORK_ERROR');
        }

        $handle = fopen($this->lockDir . "/deal-{$dealId}.lock", 'c');

        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new B24ApiException('Не удалось заблокировать сделку для записи', 'NETWORK_ERROR');
        }

        return $handle;
    }
}
