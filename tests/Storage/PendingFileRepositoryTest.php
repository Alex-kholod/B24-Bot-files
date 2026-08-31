<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Storage;

use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\PendingFileRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PendingFileRepositoryTest extends TestCase
{
    private PendingFileRepository $repository;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();
        $this->repository = new PendingFileRepository($db->pdo());
        $this->now = new DateTimeImmutable('2026-08-31 10:00:00', new \DateTimeZone('UTC'));
    }

    public function testEnqueueReturnsRowId(): void
    {
        $id = $this->repository->enqueue(789, 5, 77, 'act.pdf', $this->now);

        self::assertGreaterThan(0, $id);
    }

    public function testEnqueueIsIdempotentPerMessageAndFile(): void
    {
        $first = $this->repository->enqueue(789, 5, 77, 'act.pdf', $this->now);
        $second = $this->repository->enqueue(789, 5, 77, 'act.pdf', $this->now);

        self::assertSame($first, $second);
        self::assertCount(1, $this->repository->newForMessage(789));
    }

    public function testDueReturnsOnlyRowsWhoseTimeHasCome(): void
    {
        $this->repository->enqueue(789, 5, 77, 'act.pdf', $this->now);

        self::assertCount(1, $this->repository->due($this->now));
        self::assertCount(0, $this->repository->due($this->now->modify('-1 minute')));
    }

    public function testMarkDoneRemovesRowFromQueue(): void
    {
        $id = $this->repository->enqueue(789, 5, 77, 'act.pdf', $this->now);
        $this->repository->markDone($id, 555, $this->now);

        self::assertCount(0, $this->repository->due($this->now));
    }

    public function testMarkFailureSchedulesNextAttemptWithBackoff(): void
    {
        $id = $this->repository->enqueue(789, 5, 77, 'act.pdf', $this->now);

        $this->repository->markFailure($id, 'CRM не готова', 10, $this->now);
        self::assertCount(0, $this->repository->due($this->now));
        self::assertCount(1, $this->repository->due($this->now->modify('+1 minute')));

        $this->repository->markFailure($id, 'CRM не готова', 10, $this->now->modify('+1 minute'));
        self::assertCount(0, $this->repository->due($this->now->modify('+2 minutes')));
        self::assertCount(1, $this->repository->due($this->now->modify('+3 minutes')));
    }

    public function testBackoffIsCappedAtThirtyMinutes(): void
    {
        $id = $this->repository->enqueue(789, 5, 77, 'act.pdf', $this->now);

        for ($i = 0; $i < 8; ++$i) {
            $this->repository->markFailure($id, 'сбой', 100, $this->now);
        }

        self::assertCount(0, $this->repository->due($this->now->modify('+29 minutes')));
        self::assertCount(1, $this->repository->due($this->now->modify('+30 minutes')));
    }

    public function testMarkFailureMovesRowToFailedAfterMaxAttempts(): void
    {
        $id = $this->repository->enqueue(789, 5, 77, 'act.pdf', $this->now);

        $this->repository->markFailure($id, 'сбой', 2, $this->now);
        $this->repository->markFailure($id, 'сбой', 2, $this->now);

        self::assertCount(0, $this->repository->due($this->now->modify('+1 day')));
        self::assertSame(1, $this->repository->requeueFailed($this->now), 'строка перешла в статус failed');
    }

    public function testRequeueFailedReturnsRowsToQueue(): void
    {
        $id = $this->repository->enqueue(789, 5, 77, 'act.pdf', $this->now);
        $this->repository->markFailure($id, 'сбой', 1, $this->now);

        self::assertSame(1, $this->repository->requeueFailed($this->now));
        self::assertCount(1, $this->repository->due($this->now));
        self::assertSame(0, $this->repository->requeueFailed($this->now), 'строк в статусе failed не осталось');
    }
}
