<?php

declare(strict_types=1);

namespace B24DocsBot\Service;

use B24DocsBot\Bitrix\B24Api;
use B24DocsBot\Bitrix\B24ApiException;
use B24DocsBot\Storage\SettingsRepository;
use B24DocsBot\Storage\TaskLinkRepository;
use DateTimeImmutable;

final class ChecklistWriter
{
    public const SETTING_KEY = 'checklist_attachments_supported';

    public function __construct(
        private readonly B24Api $api,
        private readonly SettingsRepository $settings,
        private readonly TaskLinkRepository $links,
        private readonly string $checklistTitle,
    ) {
    }

    public function attachmentsSupported(): ?bool
    {
        $stored = $this->settings->get(self::SETTING_KEY);

        return $stored === null ? null : $stored === '1';
    }

    public function write(
        string $clientKey,
        int $taskId,
        int $diskFileId,
        string $fileName,
        string $downloadUrl,
        DateTimeImmutable $now,
        int $pendingId
    ): int {
        $rootId = $this->checklistRootId($clientKey, $taskId);
        $label = sprintf('%s — %s', $fileName, $now->format('d.m.Y H:i'));

        // Незавершённая проба для этой же строки очереди имеет приоритет перед сохранённым
        // флагом поддержки вложений: флаг мог быть закоммичен ДО того, как оборвался предыдущий
        // проход probeAndWrite() (он пишет флаг раньше, чем делает последние вызовы API), поэтому
        // одного лишь "флаг уже известен" недостаточно — нужно доиграть именно ту пробу, которая
        // осталась недоделанной, а не пойти по общей ветке и создать второй пункт.
        if ($this->settings->get(self::probeStateKey($pendingId)) !== null) {
            return $this->probeAndWrite($taskId, $rootId, $label, $diskFileId, $downloadUrl, $pendingId);
        }

        $supported = $this->attachmentsSupported();

        if ($supported === null) {
            return $this->probeAndWrite($taskId, $rootId, $label, $diskFileId, $downloadUrl, $pendingId);
        }

        return $supported
            ? $this->writeWithAttachment($taskId, $rootId, $label, $diskFileId)
            : $this->writeWithLink($taskId, $rootId, $label, $diskFileId, $downloadUrl);
    }

    /**
     * Первая в жизни портала запись (флаг поддержки вложений ещё не известен) состоит из
     * нескольких вызовов API. Если процесс упадёт между созданием пункта и завершением пробы —
     * сеть, лимит запроса — строка вернётся в очередь и попадёт сюда снова; без этой защиты
     * повтор создал бы ВТОРОЙ пункт чек-листа, а первый остался бы висеть с "голым" заголовком
     * без ссылки на файл. Поэтому идентификатор созданного пункта сохраняется в SettingsRepository
     * до последующих вызовов под ключом, привязанным к pendingId — идентификатору строки очереди
     * pending_files. pendingId уникален на файл (PendingFileRepository::enqueue уникализирует
     * по паре message_id+chat_file_id), поэтому два разных файла, летящих одновременно, пишут в
     * разные ключи настроек и не пересекаются; на повторной попытке этой же строки читается уже
     * сохранённый itemId вместо создания нового пункта. Ключ подчищается по завершении.
     */
    private function probeAndWrite(
        int $taskId,
        int $rootId,
        string $label,
        int $diskFileId,
        string $downloadUrl,
        int $pendingId
    ): int {
        $stateKey = self::probeStateKey($pendingId);
        $existingItemId = $this->settings->get($stateKey);

        if ($existingItemId !== null) {
            $itemId = (int) $existingItemId;
        } else {
            try {
                $itemId = $this->writeWithAttachment($taskId, $rootId, $label, $diskFileId);
            } catch (B24ApiException $exception) {
                if ($exception->isTransient()) {
                    // Сетевой сбой или лимит запроса ничего не говорит о поддержке ATTACHMENTS —
                    // ретраим как обычную временную ошибку, флаг режима не трогаем.
                    throw $exception;
                }

                // На части порталов task.checklistitem.add не молча игнорирует незнакомое поле
                // ATTACHMENTS, а отклоняет вызов целиком (wrong_arguments) — пункт чек-листа
                // при этом не создаётся вовсе, доигрывать нечего, сразу идём запасным путём.
                $this->settings->set(self::SETTING_KEY, '0');
                $this->settings->delete($stateKey);

                return $this->writeWithLink($taskId, $rootId, $label, $diskFileId, $downloadUrl);
            }

            $this->settings->set($stateKey, (string) $itemId);
        }

        $item = $this->api->getChecklistItem($taskId, $itemId);

        if ($this->hasAttachment($item, $diskFileId)) {
            $this->settings->set(self::SETTING_KEY, '1');
            $this->settings->delete($stateKey);

            return $itemId;
        }

        $this->settings->set(self::SETTING_KEY, '0');
        $this->api->attachFilesToTask($taskId, [$diskFileId]);
        $this->api->updateChecklistItem($taskId, $itemId, ['TITLE' => $label . ' — ' . $downloadUrl]);
        $this->settings->delete($stateKey);

        return $itemId;
    }

    private static function probeStateKey(int $pendingId): string
    {
        return 'checklist_probe_item:' . $pendingId;
    }

    private function writeWithAttachment(int $taskId, int $rootId, string $label, int $diskFileId): int
    {
        return $this->api->addChecklistItem($taskId, [
            'TITLE' => $label,
            'PARENT_ID' => $rootId,
            'ATTACHMENTS' => [$diskFileId],
        ]);
    }

    private function writeWithLink(
        int $taskId,
        int $rootId,
        string $label,
        int $diskFileId,
        string $downloadUrl
    ): int {
        $this->api->attachFilesToTask($taskId, [$diskFileId]);

        return $this->api->addChecklistItem($taskId, [
            'TITLE' => $label . ' — ' . $downloadUrl,
            'PARENT_ID' => $rootId,
        ]);
    }

    private function hasAttachment(array $item, int $diskFileId): bool
    {
        foreach ($item['ATTACHMENTS'] ?? [] as $attachment) {
            if ((int) ($attachment['FILE_ID'] ?? 0) === $diskFileId) {
                return true;
            }
        }

        return false;
    }

    private function checklistRootId(string $clientKey, int $taskId): int
    {
        $link = $this->links->find($clientKey);
        $cachedId = (int) ($link['checklist_id'] ?? 0);

        if ($cachedId > 0 && $this->rootExists($taskId, $cachedId)) {
            return $cachedId;
        }

        $rootId = $this->api->addChecklistItem($taskId, [
            'TITLE' => $this->checklistTitle,
            'PARENT_ID' => 0,
        ]);

        $this->links->setChecklistId($clientKey, $rootId);

        return $rootId;
    }

    private function rootExists(int $taskId, int $rootId): bool
    {
        foreach ($this->api->getChecklistItems($taskId) as $item) {
            if ((int) ($item['ID'] ?? 0) === $rootId) {
                return true;
            }
        }

        return false;
    }
}
