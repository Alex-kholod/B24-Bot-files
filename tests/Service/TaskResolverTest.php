<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Service;

use B24DocsBot\Service\ClientRef;
use B24DocsBot\Service\TaskResolver;
use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\TaskLinkRepository;
use B24DocsBot\Tests\Bitrix\FakeB24Api;
use PHPUnit\Framework\TestCase;

final class TaskResolverTest extends TestCase
{
    private FakeB24Api $api;
    private TaskLinkRepository $links;
    private TaskResolver $resolver;
    private ClientRef $client;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();

        $this->api = new FakeB24Api();
        $this->links = new TaskLinkRepository($db->pdo());
        $this->resolver = new TaskResolver($this->api, $this->links, 1, 0, 3);
        $this->client = new ClientRef('CONTACT', 123, 'Иванов Иван');
    }

    private function openTask(int $id): array
    {
        return ['id' => $id, 'status' => 2, 'isDeleted' => false];
    }

    public function testUsesCachedTaskWhenItIsStillOpen(): void
    {
        $this->links->save('crm:CONTACT:123', 'CONTACT', 123, 555);
        $this->api->tasks[555] = $this->openTask(555);

        self::assertSame(555, $this->resolver->resolve($this->client));
        self::assertSame([], $this->api->addedTasks, 'задача не должна создаваться');
    }

    public function testFallsBackToSearchWhenCachedTaskIsCompleted(): void
    {
        $this->links->save('crm:CONTACT:123', 'CONTACT', 123, 555);
        $this->api->tasks[555] = ['id' => 555, 'status' => 5, 'isDeleted' => false];
        $this->api->findTaskResult = 600;
        $this->api->tasks[600] = $this->openTask(600);

        self::assertSame(600, $this->resolver->resolve($this->client));
        self::assertSame(600, $this->links->find('crm:CONTACT:123')['task_id']);
    }

    public function testFallsBackToSearchWhenCachedTaskIsDeleted(): void
    {
        $this->links->save('crm:CONTACT:123', 'CONTACT', 123, 555);
        $this->api->tasks[555] = ['id' => 555, 'status' => 2, 'isDeleted' => true];
        $this->api->findTaskResult = 601;
        $this->api->tasks[601] = $this->openTask(601);

        self::assertSame(601, $this->resolver->resolve($this->client));
    }

    public function testFallsBackToSearchWhenCachedTaskIsMissing(): void
    {
        $this->links->save('crm:CONTACT:123', 'CONTACT', 123, 555);
        $this->api->findTaskResult = 602;
        $this->api->tasks[602] = $this->openTask(602);

        self::assertSame(602, $this->resolver->resolve($this->client));
    }

    public function testDeferredTaskIsStillUsable(): void
    {
        $this->links->save('crm:CONTACT:123', 'CONTACT', 123, 555);
        $this->api->tasks[555] = ['id' => 555, 'status' => 6, 'isDeleted' => false];

        self::assertSame(555, $this->resolver->resolve($this->client));
    }

    public function testCreatesTaskWhenNothingFound(): void
    {
        $taskId = $this->resolver->resolve($this->client);

        self::assertGreaterThan(0, $taskId);
        self::assertCount(1, $this->api->addedTasks);
        self::assertSame($taskId, $this->links->find('crm:CONTACT:123')['task_id']);
    }

    public function testCreatedTaskCarriesTitleResponsibleAndCrmBinding(): void
    {
        $this->api->crmEntities['CONTACT:123'] = ['ID' => 123, 'TITLE' => 'Иванов Иван', 'ASSIGNED_BY_ID' => 42];

        $this->resolver->resolve($this->client);
        $fields = $this->api->addedTasks[0];

        self::assertSame('Документы клиента: Иванов Иван', $fields['TITLE']);
        self::assertSame(42, $fields['RESPONSIBLE_ID']);
        self::assertSame(3, $fields['CREATED_BY']);
        self::assertSame(['C_123'], $fields['UF_CRM_TASK']);
    }

    public function testUsesDefaultResponsibleWhenCrmEntityHasNone(): void
    {
        $this->api->crmEntities['CONTACT:123'] = ['ID' => 123, 'TITLE' => 'Иванов Иван'];

        $this->resolver->resolve($this->client);

        self::assertSame(1, $this->api->addedTasks[0]['RESPONSIBLE_ID']);
    }

    public function testPassesGroupIdOnlyWhenConfigured(): void
    {
        $this->resolver->resolve($this->client);
        self::assertArrayNotHasKey('GROUP_ID', $this->api->addedTasks[0]);

        $withGroup = new TaskResolver($this->api, $this->links, 1, 17, 3);
        $withGroup->resolve(new ClientRef('DEAL', 9, 'Сделка'));

        self::assertSame(17, $this->api->addedTasks[1]['GROUP_ID']);
    }

    public function testSecondCallUsesCacheWithoutSearching(): void
    {
        $first = $this->resolver->resolve($this->client);
        $this->api->findTaskResult = 999;

        self::assertSame($first, $this->resolver->resolve($this->client));
        self::assertCount(1, $this->api->addedTasks);
    }
}
