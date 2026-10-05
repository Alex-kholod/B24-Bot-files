<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Service;

use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Service\DealDocumentPublisher;
use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\PinnedCommentRepository;
use B24DocsBot\Tests\Bitrix\FakeB24Api;
use B24DocsBot\Tests\Bitrix\FakeFileDownloader;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DealDocumentPublisherTest extends TestCase
{
    private FakeB24Api $api;
    private FakeFileDownloader $downloader;
    private PinnedCommentRepository $pinned;
    private DealDocumentPublisher $publisher;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();

        $this->api = new FakeB24Api();
        $this->downloader = new FakeFileDownloader();
        $this->pinned = new PinnedCommentRepository($db->pdo());
        $this->publisher = new DealDocumentPublisher($this->api, $this->downloader, $this->pinned, new NullLogger());
    }

    private function publish(int $dealId = 5547, int $fileId = 77, string $at = '2026-10-05 15:35:00'): int
    {
        return $this->publisher->publish($dealId, $fileId, '', new DateTimeImmutable($at));
    }

    public function testDownloadsFileAndAddsCommentWithItToDealTimeline(): void
    {
        $this->downloader->name = 'Требования к дому.pdf';

        $commentId = $this->publish();

        self::assertSame(['https://disk/77'], $this->downloader->urls);
        self::assertSame(
            [5547, 'Документ от клиента — 05.10.2026 15:35', 'Требования к дому.pdf', 'BODY'],
            $this->api->comments[$commentId]
        );
    }

    public function testUsesSyntheticNameWhenNothingKnown(): void
    {
        $commentId = $this->publish();

        self::assertSame('file-77', $this->api->comments[$commentId][2]);
    }

    public function testPinsNewComment(): void
    {
        $commentId = $this->publish();

        self::assertSame([[5547, $commentId]], $this->api->pinned);
        self::assertSame([$commentId], $this->pinned->forDeal(5547));
    }

    public function testUnpinsOldestWhenLimitOfThreeIsReached(): void
    {
        $first = $this->publish(5547, 1, '2026-10-05 10:00:00');
        $second = $this->publish(5547, 2, '2026-10-05 11:00:00');
        $third = $this->publish(5547, 3, '2026-10-05 12:00:00');
        $fourth = $this->publish(5547, 4, '2026-10-05 13:00:00');

        self::assertSame([[5547, $first]], $this->api->unpinned);
        self::assertSame([$second, $third, $fourth], $this->pinned->forDeal(5547));
    }

    public function testPinsOfDifferentDealsDoNotInterfere(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->publish(1, $i);
        }

        $this->publish(2, 9);

        self::assertSame([], $this->api->unpinned);
    }

    public function testPinFailureDoesNotFailPublishingAndDoesNotDuplicateComment(): void
    {
        // Например, на сделке уже закреплены три чужие записи.
        $this->api->throwOnPin = new B24ApiException('Только три события можно добавить в избранное', '0');

        $commentId = $this->publish();

        self::assertCount(1, $this->api->comments);
        self::assertSame([], $this->pinned->forDeal(5547));
        self::assertGreaterThan(0, $commentId);
    }

    public function testUnpinFailureDoesNotPreventPinningNewComment(): void
    {
        $this->api->throwOnUnpin = new B24ApiException('нет такой записи', 'NOT_FOUND');

        for ($i = 1; $i <= 4; $i++) {
            $last = $this->publish(5547, $i, "2026-10-05 1{$i}:00:00");
        }

        self::assertContains($last, $this->pinned->forDeal(5547));
        self::assertCount(3, $this->pinned->forDeal(5547));
    }

    public function testDownloadFailureAddsNoComment(): void
    {
        $this->downloader->throw = new B24ApiException('протухла ссылка', '');

        try {
            $this->publish();
            self::fail('ожидалось исключение');
        } catch (B24ApiException) {
        }

        self::assertSame([], $this->api->comments);
    }

    public function testDownloadUrlFailurePropagates(): void
    {
        $this->api->throwOnDownloadUrl = new B24ApiException('лимит', 'QUERY_LIMIT_EXCEEDED');

        $this->expectException(B24ApiException::class);

        $this->publish();
    }
}
