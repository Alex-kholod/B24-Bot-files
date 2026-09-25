<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Bitrix;

use B24DocsBot\Bitrix\ServiceFactory;
use PHPUnit\Framework\TestCase;

final class ServiceFactoryTest extends TestCase
{
    public function testOriginIsTakenFromClientEndpoint(): void
    {
        self::assertSame(
            'https://totem-arch.bitrix24.ru',
            ServiceFactory::portalOrigin(['client_endpoint' => 'https://totem-arch.bitrix24.ru/rest/', 'domain' => 'x'])
        );
    }

    public function testOriginFallsBackToDomain(): void
    {
        self::assertSame('https://totem-arch.bitrix24.ru', ServiceFactory::portalOrigin(['domain' => 'totem-arch.bitrix24.ru']));
    }
}
