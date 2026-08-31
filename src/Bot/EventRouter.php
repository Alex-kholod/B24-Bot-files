<?php

declare(strict_types=1);

namespace B24DocsBot\Bot;

final class EventRouter
{
    private const MESSAGE_ADD = 'ONIMBOTV2MESSAGEADD';

    public function __construct(private readonly string $expectedApplicationToken)
    {
    }

    public function verify(array $request): bool
    {
        $token = (string) ($request['auth']['application_token'] ?? '');

        if ($token === '' || $this->expectedApplicationToken === '') {
            return false;
        }

        return hash_equals($this->expectedApplicationToken, $token);
    }

    public function parse(array $request): ?BotEvent
    {
        if ((string) ($request['event'] ?? '') !== self::MESSAGE_ADD) {
            return null;
        }

        $message = $request['data']['message'] ?? null;
        $chat = $request['data']['chat'] ?? null;

        if (!is_array($message) || !is_array($chat) || !isset($message['id'], $message['chatId'])) {
            return null;
        }

        $botId = (int) ($request['data']['bot']['id'] ?? 0);
        $authorId = (int) ($message['authorId'] ?? 0);
        $authorIsBot = ((string) ($request['data']['user']['bot'] ?? '0')) !== '0'
            || ($botId > 0 && $authorId === $botId);

        return new BotEvent(
            event: self::MESSAGE_ADD,
            botId: $botId,
            messageId: (int) $message['id'],
            chatId: (int) $message['chatId'],
            authorId: $authorId,
            chatEntityType: (string) ($chat['entityType'] ?? ''),
            authorIsBot: $authorIsBot,
            fileIds: $this->extractFileIds($message),
        );
    }

    /**
     * @return int[]
     */
    private function extractFileIds(array $message): array
    {
        $raw = $message['params']['FILE_ID'] ?? [];

        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
