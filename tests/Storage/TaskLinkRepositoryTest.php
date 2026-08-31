<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Storage;

use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\TaskLinkRepository;
use PHPUnit\Framework\TestCase;

final class TaskLinkRepositoryTest extends TestCase
{
    private TaskLinkRepository $repository;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();
        $this->repository = new TaskLinkRepository($db->pdo());
    }

    public function testFindReturnsNullForUnknownClient(): void
    {
        self::assertNull($this->repository->find('crm:CONTACT:1'));
    }

    public function testSaveThenFind(): void
    {
        $this->repository->save('crm:CONTACT:123', 'CONTACT', 123, 555);
        $link = $this->repository->find('crm:CONTACT:123');

        self::assertNotNull($link);
        self::assertSame(555, $link['task_id']);
        self::assertSame('CONTACT', $link['crm_entity_type']);
        self::assertSame(123, $link['crm_entity_id']);
        self::assertSame(0, $link['checklist_id']);
    }

    public function testSaveOverwritesTaskIdAndResetsChecklist(): void
    {
        $this->repository->save('crm:CONTACT:123', 'CONTACT', 123, 555);
        $this->repository->setChecklistId('crm:CONTACT:123', 900);
        $this->repository->save('crm:CONTACT:123', 'CONTACT', 123, 777);

        $link = $this->repository->find('crm:CONTACT:123');

        self::assertSame(777, $link['task_id']);
        self::assertSame(0, $link['checklist_id'], 'новая задача — новый чек-лист');
    }

    public function testSetChecklistId(): void
    {
        $this->repository->save('crm:DEAL:9', 'DEAL', 9, 100);
        $this->repository->setChecklistId('crm:DEAL:9', 42);

        self::assertSame(42, $this->repository->find('crm:DEAL:9')['checklist_id']);
    }

    public function testTouchUpdatesLastUsedWithoutChangingTask(): void
    {
        $this->repository->save('crm:DEAL:9', 'DEAL', 9, 100);
        $this->repository->touch('crm:DEAL:9');

        self::assertSame(100, $this->repository->find('crm:DEAL:9')['task_id']);
    }
}
