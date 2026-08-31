<?php

declare(strict_types=1);

namespace B24DocsBot\Bot;

final class BotEvent
{
    /**
     * @param int[] $fileIds
     */
    public function __construct(
        public readonly string $event,
        public readonly int $botId,
        public readonly int $messageId,
        public readonly int $chatId,
        public readonly int $authorId,
        public readonly string $chatEntityType,
        public readonly bool $authorIsBot,
        public readonly array $fileIds,
    ) {
    }

    public function isOpenLine(): bool
    {
        return $this->chatEntityType === 'LINES';
    }

    public function hasFiles(): bool
    {
        return $this->fileIds !== [];
    }
}
