<?php

declare(strict_types=1);

namespace B24DocsBot\Bitrix;

use B24DocsBot\Config;
use B24DocsBot\Storage\TokenRepository;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\DefaultOAuthServerUrl;
use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Собирает `SdkB24Api` для установленного портала: читает сохранённые OAuth-токены,
 * настраивает ServiceBuilder официального SDK и подписывается на продление токенов.
 */
final class ServiceFactory
{
    public function __construct(
        private readonly Config $config,
        private readonly TokenRepository $tokens,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function create(): B24Api
    {
        $portal = $this->tokens->find();

        if ($portal === null) {
            throw new RuntimeException('Приложение не установлено: токены портала не найдены.');
        }

        return new SdkB24Api($this->buildServiceBuilder($portal));
    }

    private function buildServiceBuilder(array $portal): ServiceBuilder
    {
        $memberId = (string) ($portal['member_id'] ?? '');
        $accessToken = (string) ($portal['access_token'] ?? '');
        $refreshToken = (string) ($portal['refresh_token'] ?? '');
        $portalUrl = (string) ($portal['client_endpoint'] ?? '');

        if ($portalUrl === '') {
            $portalUrl = (string) ($portal['domain'] ?? '');
        }

        if ($memberId === '' || $accessToken === '' || $portalUrl === '') {
            throw new RuntimeException('Неполные данные авторизации портала: требуется переустановка приложения.');
        }

        $applicationProfile = ApplicationProfile::initFromArray([
            'BITRIX24_PHP_SDK_APPLICATION_CLIENT_ID' => $this->config->string('client_id'),
            'BITRIX24_PHP_SDK_APPLICATION_CLIENT_SECRET' => $this->config->string('client_secret'),
            'BITRIX24_PHP_SDK_APPLICATION_SCOPE' => $this->config->string('scope'),
        ]);

        $authToken = new AuthToken(
            $accessToken,
            $refreshToken !== '' ? $refreshToken : null,
            (int) ($portal['expires_at'] ?? 0),
        );

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(
            AuthTokenRenewedEvent::class,
            function (AuthTokenRenewedEvent $event) use ($memberId): void {
                $renewed = $event->getRenewedToken()->authToken;

                $this->tokens->updateTokens(
                    $memberId,
                    $renewed->accessToken,
                    (string) $renewed->refreshToken,
                    $renewed->expires,
                );

                // Значения токенов в лог не попадают — только сам факт продления.
                $this->logger->info('Токены портала Битрикс24 продлены', ['member_id' => $memberId]);
            }
        );

        $oauthServerUrl = $this->config->string('oauth_server_url');

        if ($oauthServerUrl === '') {
            $oauthServerUrl = DefaultOAuthServerUrl::default();
        }

        // SDK пишет значения токенов и полные тела ответов в свой логгер,
        // поэтому ему отдаётся NullLogger: наш логгер этого содержать не должен.
        return (new ServiceBuilderFactory($eventDispatcher, new NullLogger()))
            ->init($applicationProfile, $authToken, $portalUrl, $oauthServerUrl);
    }
}
