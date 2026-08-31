<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Storage;

use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\SettingsRepository;
use PHPUnit\Framework\TestCase;

final class SettingsRepositoryTest extends TestCase
{
    private SettingsRepository $repository;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();
        $this->repository = new SettingsRepository($db->pdo());
    }

    public function testGetReturnsNullForUnknownKey(): void
    {
        self::assertNull($this->repository->get('checklist_attachments_supported'));
    }

    public function testSetThenGet(): void
    {
        $this->repository->set('checklist_attachments_supported', '1');

        self::assertSame('1', $this->repository->get('checklist_attachments_supported'));
    }

    public function testSetOverwritesExistingValue(): void
    {
        $this->repository->set('checklist_attachments_supported', '1');
        $this->repository->set('checklist_attachments_supported', '0');

        self::assertSame('0', $this->repository->get('checklist_attachments_supported'));
    }

    public function testDeleteRemovesKey(): void
    {
        $this->repository->set('checklist_attachments_supported', '1');
        $this->repository->delete('checklist_attachments_supported');

        self::assertNull($this->repository->get('checklist_attachments_supported'));
    }
}
