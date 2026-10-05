<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Storage;

use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\DealCommentRepository;
use PHPUnit\Framework\TestCase;

final class DealCommentRepositoryTest extends TestCase
{
    private DealCommentRepository $repository;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();
        $this->repository = new DealCommentRepository($db->pdo());
    }

    public function testFindsNothingForUnknownDeal(): void
    {
        self::assertNull($this->repository->find(1));
    }

    public function testSavesAndReplacesCommentOfDeal(): void
    {
        $this->repository->save(1, 100);
        $this->repository->save(1, 101);
        $this->repository->save(2, 200);

        self::assertSame(101, $this->repository->find(1));
        self::assertSame(200, $this->repository->find(2));
    }

    public function testForgetRemovesLink(): void
    {
        $this->repository->save(1, 100);
        $this->repository->forget(1);

        self::assertNull($this->repository->find(1));
    }
}
