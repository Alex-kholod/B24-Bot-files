<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Service;

use B24DocsBot\Service\DealResolver;
use B24DocsBot\Tests\Bitrix\FakeB24Api;
use PHPUnit\Framework\TestCase;

final class DealResolverTest extends TestCase
{
    private FakeB24Api $api;
    private DealResolver $resolver;

    protected function setUp(): void
    {
        $this->api = new FakeB24Api();
        $this->resolver = new DealResolver($this->api);
    }

    public function testReadsDealFromDocumentedFields(): void
    {
        $this->api->dialogs[5] = ['crm_entity_type' => 'DEAL', 'crm_entity_id' => '77'];

        self::assertSame(77, $this->resolver->resolve(5));
    }

    public function testReadsDealFromEntityData2(): void
    {
        // Реальный ответ imopenlines.dialog.get живого портала.
        $this->api->dialogs[5] = ['entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|5329|DEAL|5547'];

        self::assertSame(5547, $this->resolver->resolve(5));
    }

    public function testIgnoresContactWhenThereIsNoDeal(): void
    {
        $this->api->dialogs[5] = ['entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|5329|DEAL|0'];

        self::assertNull($this->resolver->resolve(5));
    }

    public function testIgnoresNonDealDocumentedBinding(): void
    {
        $this->api->dialogs[5] = ['crm_entity_type' => 'CONTACT', 'crm_entity_id' => '9'];

        self::assertNull($this->resolver->resolve(5));
    }

    public function testNullWhenNoCrmData(): void
    {
        $this->api->dialogs[5] = ['crm' => 'N'];

        self::assertNull($this->resolver->resolve(5));
    }

    public function testNullForUnknownChat(): void
    {
        self::assertNull($this->resolver->resolve(404));
    }
}
