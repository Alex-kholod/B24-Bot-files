<?php

declare(strict_types=1);

namespace B24DocsBot\Tests\Service;

use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Service\DealDocumentPublisher;
use B24DocsBot\Storage\Database;
use B24DocsBot\Storage\DealCommentRepository;
use B24DocsBot\Tests\Bitrix\FakeB24Api;
use B24DocsBot\Tests\Bitrix\FakeFileDownloader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DealDocumentPublisherTest extends TestCase
{
    private FakeB24Api $api;
    private FakeFileDownloader $downloader;
    private DealCommentRepository $comments;
    private DealDocumentPublisher $publisher;

    protected function setUp(): void
    {
        $db = new Database(':memory:');
        $db->migrate();

        $this->api = new FakeB24Api();
        $this->downloader = new FakeFileDownloader();
        $this->comments = new DealCommentRepository($db->pdo());
        $this->publisher = new DealDocumentPublisher(
            $this->api,
            $this->downloader,
            $this->comments,
            new NullLogger(),
            sys_get_temp_dir() . '/b24-docs-bot-test-locks'
        );
    }

    private function names(int $commentId): array
    {
        return array_column($this->api->comments[$commentId][2], 'name');
    }

    public function testFirstDocumentCreatesAndPinsCommentWithFixedText(): void
    {
        $this->downloader->name = 'Требования к дому.pdf';

        $commentId = $this->publisher->publish(5547, 77, '');

        self::assertSame(5547, $this->api->comments[$commentId][0]);
        self::assertSame('документы из чата с клиентом', $this->api->comments[$commentId][1]);
        self::assertSame(['Требования к дому.pdf'], $this->names($commentId));
        self::assertSame([[5547, $commentId]], $this->api->pinned);
        self::assertSame($commentId, $this->comments->find(5547));
    }

    public function testSecondDocumentIsAddedToTheSameCommentKeepingOldFiles(): void
    {
        $first = $this->publisher->publish(5547, 1, 'a.pdf');
        $oldDiskId = $this->api->comments[$first][2][0]['id'];

        $second = $this->publisher->publish(5547, 2, 'b.pdf');

        self::assertSame($first, $second);
        self::assertCount(1, $this->api->comments, 'нового комментария нет');
        self::assertCount(1, $this->api->pinned, 'повторно не закрепляется');
        self::assertSame(['a.pdf', 'b.pdf'], $this->names($first));

        // Старый файл скачан заново с Диска по его id и отправлен вместе с новым.
        $sent = $this->api->updates[0][3];
        self::assertSame("BODY:https://disk/{$oldDiskId}", $sent[0]['content']);
        self::assertSame('BODY:https://chat/2', $sent[1]['content']);
    }

    public function testThreeDocumentsAccumulateInOneComment(): void
    {
        $id = $this->publisher->publish(5547, 1, 'a.pdf');
        $this->publisher->publish(5547, 2, 'b.pdf');
        $this->publisher->publish(5547, 3, 'c.pdf');

        self::assertSame(['a.pdf', 'b.pdf', 'c.pdf'], $this->names($id));
        self::assertCount(1, $this->api->comments);
    }

    public function testDifferentDealsGetDifferentComments(): void
    {
        $a = $this->publisher->publish(1, 1, 'a.pdf');
        $b = $this->publisher->publish(2, 2, 'b.pdf');

        self::assertNotSame($a, $b);
        self::assertCount(2, $this->api->pinned);
    }

    public function testFailureToFetchOldFileAbortsBeforeUpdateSoNothingIsLost(): void
    {
        $this->publisher->publish(5547, 1, 'a.pdf');
        $this->api->throwOnDiskUrl = new B24ApiException('access denied', '');

        try {
            $this->publisher->publish(5547, 2, 'b.pdf');
            self::fail('ожидалось исключение');
        } catch (B24ApiException) {
        }

        self::assertSame([], $this->api->updates);
        self::assertSame(['a.pdf'], $this->names(array_key_first($this->api->comments)));
    }

    public function testFailureToDownloadOldFileAbortsBeforeUpdate(): void
    {
        $this->publisher->publish(5547, 1, 'a.pdf');
        $oldDiskId = $this->api->comments[array_key_first($this->api->comments)][2][0]['id'];
        $this->downloader->throwOnUrl["https://disk/{$oldDiskId}"] = new B24ApiException('HTTP 403', '');

        $this->expectException(B24ApiException::class);

        try {
            $this->publisher->publish(5547, 2, 'b.pdf');
        } finally {
            self::assertSame([], $this->api->updates);
        }
    }

    public function testUpdateFailurePropagates(): void
    {
        $this->publisher->publish(5547, 1, 'a.pdf');
        $this->api->throwOnUpdate = new B24ApiException('лимит', 'QUERY_LIMIT_EXCEEDED');

        $this->expectException(B24ApiException::class);

        $this->publisher->publish(5547, 2, 'b.pdf');
    }

    public function testStartsNewCommentWhenPreviousOneWasDeleted(): void
    {
        $first = $this->publisher->publish(5547, 1, 'a.pdf');
        unset($this->api->comments[$first]);

        $second = $this->publisher->publish(5547, 2, 'b.pdf');

        self::assertNotSame($first, $second);
        self::assertSame($second, $this->comments->find(5547));
        self::assertSame(['b.pdf'], $this->names($second));
        self::assertCount(2, $this->api->pinned);
    }

    public function testPinFailureDoesNotFailPublishing(): void
    {
        // Например, на сделке уже закреплены три чужие записи.
        $this->api->throwOnPin = new B24ApiException('Только три события можно добавить в избранное', '0');

        $commentId = $this->publisher->publish(5547, 1, 'a.pdf');

        self::assertSame($commentId, $this->comments->find(5547), 'комментарий запомнен, дубля при повторе не будет');
        self::assertCount(1, $this->api->comments);
    }

    public function testChatDownloadFailureCreatesNothing(): void
    {
        $this->downloader->throw = new B24ApiException('протухла ссылка', '');

        try {
            $this->publisher->publish(5547, 1, 'a.pdf');
            self::fail('ожидалось исключение');
        } catch (B24ApiException) {
        }

        self::assertSame([], $this->api->comments);
        self::assertNull($this->comments->find(5547));
    }

    public function testChatUrlFailurePropagates(): void
    {
        $this->api->throwOnDownloadUrl = new B24ApiException('лимит', 'QUERY_LIMIT_EXCEEDED');

        $this->expectException(B24ApiException::class);

        $this->publisher->publish(5547, 1, 'a.pdf');
    }
}
