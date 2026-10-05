<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Storage;

use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\PinnedCommentRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PinnedCommentRepositoryTest extends TestCase
{
    private PinnedCommentRepository $repository;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();
        $this->repository = new PinnedCommentRepository($db->pdo());
    }

    public function testListsCommentsOfDealOldestFirst(): void
    {
        $this->repository->add(10, 502, new DateTimeImmutable('2026-10-05 12:00:00'));
        $this->repository->add(10, 501, new DateTimeImmutable('2026-10-05 11:00:00'));
        $this->repository->add(11, 600, new DateTimeImmutable('2026-10-05 10:00:00'));

        self::assertSame([501, 502], $this->repository->forDeal(10));
        self::assertSame([600], $this->repository->forDeal(11));
    }

    public function testRemoveForgetsComment(): void
    {
        $this->repository->add(10, 501, new DateTimeImmutable('2026-10-05 11:00:00'));
        $this->repository->remove(501);

        self::assertSame([], $this->repository->forDeal(10));
    }
}
