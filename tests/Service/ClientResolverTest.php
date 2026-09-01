<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Service;

use B24DocsBot\Service\ClientResolver;
use B24DocsBot\Tests\Bitrix\FakeB24Api;
use PHPUnit\Framework\TestCase;

final class ClientResolverTest extends TestCase
{
    private FakeB24Api $api;
    private ClientResolver $resolver;

    protected function setUp(): void
    {
        $this->api = new FakeB24Api();
        $this->resolver = new ClientResolver($this->api);
    }

    public function testResolvesContactFromDialog(): void
    {
        $this->api->dialogs[5] = ['crm' => 'Y', 'crm_entity_type' => 'CONTACT', 'crm_entity_id' => '123'];
        $this->api->crmEntities['CONTACT:123'] = ['ID' => 123, 'TITLE' => 'Иванов Иван', 'ASSIGNED_BY_ID' => 7];

        $client = $this->resolver->resolve(5);

        self::assertNotNull($client);
        self::assertSame('CONTACT', $client->crmEntityType);
        self::assertSame(123, $client->crmEntityId);
        self::assertSame('crm:CONTACT:123', $client->clientKey());
        self::assertSame('C_123', $client->crmBinding());
    }

    public function testBuildsBindingPrefixPerEntityType(): void
    {
        $cases = ['LEAD' => 'L_1', 'CONTACT' => 'C_1', 'COMPANY' => 'CO_1', 'DEAL' => 'D_1'];

        foreach ($cases as $type => $expected) {
            $this->api->dialogs[5] = ['crm_entity_type' => $type, 'crm_entity_id' => '1'];
            $this->api->crmEntities["{$type}:1"] = ['ID' => 1, 'TITLE' => 'X'];

            self::assertSame($expected, $this->resolver->resolve(5)->crmBinding(), "тип {$type}");
        }
    }

    public function testReturnsNullWhenChatHasNoCrmEntity(): void
    {
        $this->api->dialogs[5] = ['crm' => 'N'];

        self::assertNull($this->resolver->resolve(5));
    }

    public function testReturnsNullWhenEntityIdIsZero(): void
    {
        $this->api->dialogs[5] = ['crm_entity_type' => 'CONTACT', 'crm_entity_id' => '0'];

        self::assertNull($this->resolver->resolve(5));
    }

    public function testReturnsNullForUnknownEntityType(): void
    {
        $this->api->dialogs[5] = ['crm_entity_type' => 'SMART_PROCESS', 'crm_entity_id' => '4'];

        self::assertNull($this->resolver->resolve(5));
    }

    public function testUsesEntityTitleAsClientTitle(): void
    {
        $this->api->dialogs[5] = ['crm_entity_type' => 'DEAL', 'crm_entity_id' => '9'];
        $this->api->crmEntities['DEAL:9'] = ['ID' => 9, 'TITLE' => 'Поставка бетона'];

        self::assertSame('Поставка бетона', $this->resolver->resolve(5)->title);
    }

    public function testFallsBackToGenericTitleWhenEntityUnavailable(): void
    {
        $this->api->dialogs[5] = ['crm_entity_type' => 'DEAL', 'crm_entity_id' => '9'];

        self::assertSame('DEAL 9', $this->resolver->resolve(5)->title);
    }

    public function testAcceptsUppercaseAndLowercaseDialogKeys(): void
    {
        $this->api->dialogs[5] = ['CRM_ENTITY_TYPE' => 'CONTACT', 'CRM_ENTITY_ID' => '55'];
        $this->api->crmEntities['CONTACT:55'] = ['ID' => 55, 'TITLE' => 'Пётр'];

        self::assertSame('crm:CONTACT:55', $this->resolver->resolve(5)->clientKey());
    }
}
