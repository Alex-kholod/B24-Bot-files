<?php

declare(strict_types=1);

namespace B24DocsBot\Bot;

use B24DocsBot\Service\DealDocumentPublisher;
use B24DocsBot\Service\DealResolver;
use B24DocsBot\Storage\PendingFileRepository;
use B24DocsBot\Storage\ProcessedMessageRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Throwable;

final class MessageHandler
{
    public function __construct(
        private readonly ProcessedMessageRepository $processed,
        private readonly PendingFileRepository $pending,
        private readonly DealResolver $deals,
        private readonly DealDocumentPublisher $publisher,
        private readonly LoggerInterface $logger,
        private readonly int $maxAttempts,
    ) {
    }

    public function handle(BotEvent $event, DateTimeImmutable $now): void
    {
        if ($event->authorIsBot || !$event->isOpenLine() || !$event->hasFiles()) {
            $this->logger->debug('Сообщение пропущено фильтром', [
                'message_id' => $event->messageId,
                'author_is_bot' => $event->authorIsBot,
                'entity_type' => $event->chatEntityType,
                'files' => count($event->fileIds),
            ]);

            return;
        }

        if ($this->processed->isProcessed($event->messageId)) {
            $this->logger->debug('Повторная доставка события', ['message_id' => $event->messageId]);

            return;
        }

        foreach ($event->fileIds as $fileId) {
            $this->pending->enqueue($event->messageId, $event->chatId, $fileId, '', $now);
        }

        $rows = $this->pending->newForMessage($event->messageId);
        $allDone = true;

        foreach ($rows as $row) {
            if (!$this->processRow($row, $now)) {
                $allDone = false;
            }
        }

        if ($allDone) {
            $this->processed->markProcessed($event->messageId, $event->chatId);
        }
    }

    public function processRow(array $row, DateTimeImmutable $now): bool
    {
        if (!$this->pending->claim($row['id'], $now)) {
            // Строку уже забрал другой обработчик (параллельный вебхук или другой тик cron) —
            // это не сбой этого файла, ничего не трогаем в Битрикс24. Возвращаем false, а не
            // true: false здесь не значит "не получилось" в смысле markFailure, оно означает
            // "не наша работа", и оно намеренно не даёт handle() пометить сообщение обработанным —
            // владелец аренды доведёт строку до markDone/markFailure сам.
            $this->logger->debug('Строка уже в обработке у другого воркера', ['pending_id' => $row['id']]);

            return false;
        }

        try {
            $dealId = $this->deals->resolve($row['chat_id']);

            if ($dealId === null) {
                $this->pending->markFailure(
                    $row['id'],
                    'Чат ещё не привязан к сделке',
                    $this->maxAttempts,
                    $now
                );
                $this->logger->info('Чат без сделки, файл отложен', [
                    'chat_id' => $row['chat_id'],
                    'pending_id' => $row['id'],
                ]);

                return false;
            }

            $commentId = $this->publisher->publish(
                $dealId,
                $row['chat_file_id'],
                (string) $row['file_name'],
                $now
            );

            $this->pending->markDone($row['id'], $dealId, $now);

            $this->logger->info('Документ добавлен в таймлайн сделки', [
                'pending_id' => $row['id'],
                'chat_id' => $row['chat_id'],
                'deal_id' => $dealId,
                'comment_id' => $commentId,
            ]);

            return true;
        } catch (Throwable $exception) {
            $this->pending->markFailure($row['id'], $exception->getMessage(), $this->maxAttempts, $now);

            $this->logger->error('Не удалось обработать файл', [
                'pending_id' => $row['id'],
                'chat_id' => $row['chat_id'],
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
