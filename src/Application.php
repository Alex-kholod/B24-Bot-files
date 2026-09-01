<?php

declare(strict_types=1);

namespace B24DocsBot;

use B24DocsBot\Bitrix\B24Api;
use B24DocsBot\Bitrix\ServiceFactory;
use B24DocsBot\Bot\EventRouter;
use B24DocsBot\Bot\MessageHandler;
use B24DocsBot\Logging\RedactingProcessor;
use B24DocsBot\Service\ChecklistWriter;
use B24DocsBot\Service\ClientResolver;
use B24DocsBot\Service\FileAttacher;
use B24DocsBot\Service\TaskResolver;
use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\PendingFileRepository;
use B24DocsBot\Storage\ProcessedMessageRepository;
use B24DocsBot\Storage\SettingsRepository;
use B24DocsBot\Storage\TaskLinkRepository;
use B24DocsBot\Storage\TokenRepository;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

final class Application
{
    private readonly Database $database;
    private ?TokenRepository $tokens = null;
    private ?PendingFileRepository $pending = null;
    private ?ProcessedMessageRepository $processed = null;
    private ?TaskLinkRepository $links = null;
    private ?SettingsRepository $settings = null;
    private ?LoggerInterface $logger = null;
    private ?B24Api $api = null;
    private ?MessageHandler $messageHandler = null;

    public function __construct(private readonly Config $config)
    {
        $this->database = new Database($config->string('db_path'));
        $this->database->migrate();
    }

    public function database(): Database
    {
        return $this->database;
    }

    public function tokens(): TokenRepository
    {
        return $this->tokens ??= new TokenRepository($this->database->pdo());
    }

    public function pending(): PendingFileRepository
    {
        return $this->pending ??= new PendingFileRepository($this->database->pdo());
    }

    public function processed(): ProcessedMessageRepository
    {
        return $this->processed ??= new ProcessedMessageRepository($this->database->pdo());
    }

    public function links(): TaskLinkRepository
    {
        return $this->links ??= new TaskLinkRepository($this->database->pdo());
    }

    public function settings(): SettingsRepository
    {
        return $this->settings ??= new SettingsRepository($this->database->pdo());
    }

    public function logger(): LoggerInterface
    {
        if ($this->logger === null) {
            $logger = new Logger('b24-docs-bot');
            $logger->pushProcessor(new RedactingProcessor());
            $logger->pushHandler(new RotatingFileHandler(
                $this->config->string('log_path') . '/bot.log',
                30,
                Level::fromName($this->config->string('log_level'))
            ));

            $this->logger = $logger;
        }

        return $this->logger;
    }

    public function api(): B24Api
    {
        return $this->api ??= (new ServiceFactory($this->config, $this->tokens(), $this->logger()))->create();
    }

    public function eventRouter(): EventRouter
    {
        return new EventRouter($this->tokens()->applicationToken());
    }

    public function messageHandler(): MessageHandler
    {
        if ($this->messageHandler === null) {
            $api = $this->api();
            $createdById = (int) ($this->tokens()->find()['installed_by_user_id'] ?? 0);

            $checklistWriter = new ChecklistWriter(
                $api,
                $this->settings(),
                $this->links(),
                $this->config->string('checklist_title')
            );

            $this->messageHandler = new MessageHandler(
                $this->processed(),
                $this->pending(),
                new ClientResolver($api),
                new TaskResolver(
                    $api,
                    $this->links(),
                    $this->config->int('default_responsible_id'),
                    $this->config->int('task_group_id'),
                    $createdById > 0 ? $createdById : $this->config->int('default_responsible_id')
                ),
                new FileAttacher($api, $checklistWriter),
                $this->logger(),
                $this->config->int('max_attempts')
            );
        }

        return $this->messageHandler;
    }
}
