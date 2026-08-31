<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Storage;

use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\ProcessedMessageRepository;
use PHPUnit\Framework\TestCase;

final class ProcessedMessageRepositoryTest extends TestCase
{
    private ProcessedMessageRepository $repository;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();
        $this->repository = new ProcessedMessageRepository($db->pdo());
    }

    public function testUnknownMessageIsNotProcessed(): void
    {
        self::assertFalse($this->repository->isProcessed(789));
    }

    public function testMarkProcessedThenIsProcessed(): void
    {
        $this->repository->markProcessed(789, 5);

        self::assertTrue($this->repository->isProcessed(789));
    }

    public function testMarkProcessedTwiceDoesNotThrow(): void
    {
        $this->repository->markProcessed(789, 5);
        $this->repository->markProcessed(789, 5);

        self::assertTrue($this->repository->isProcessed(789));
    }
}
