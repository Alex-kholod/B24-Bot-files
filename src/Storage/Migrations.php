<?php

declare(strict_types=1);

namespace B24DocsBot\Storage;

final class Migrations
{
    /**
     * @return array<string, string> имя миграции => SQL
     */
    public static function all(): array
    {
        return [
            '001_auth_tokens' => <<<'SQL'
                CREATE TABLE IF NOT EXISTS auth_tokens (
                    member_id TEXT PRIMARY KEY,
                    domain TEXT NOT NULL,
                    client_endpoint TEXT NOT NULL,
                    access_token TEXT NOT NULL,
                    refresh_token TEXT NOT NULL,
                    expires_at INTEGER NOT NULL DEFAULT 0,
                    application_token TEXT NOT NULL DEFAULT '',
                    bot_id INTEGER NOT NULL DEFAULT 0,
                    bot_code TEXT NOT NULL DEFAULT '',
                    installed_by_user_id INTEGER NOT NULL DEFAULT 0,
                    updated_at TEXT NOT NULL
                )
                SQL,
            '002_task_links' => <<<'SQL'
                CREATE TABLE IF NOT EXISTS task_links (
                    client_key TEXT PRIMARY KEY,
                    crm_entity_type TEXT NOT NULL,
                    crm_entity_id INTEGER NOT NULL,
                    task_id INTEGER NOT NULL,
                    checklist_id INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    last_used_at TEXT NOT NULL
                )
                SQL,
            '003_processed_messages' => <<<'SQL'
                CREATE TABLE IF NOT EXISTS processed_messages (
                    message_id INTEGER PRIMARY KEY,
                    chat_id INTEGER NOT NULL,
                    processed_at TEXT NOT NULL
                )
                SQL,
            '004_pending_files' => <<<'SQL'
                CREATE TABLE IF NOT EXISTS pending_files (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    message_id INTEGER NOT NULL,
                    chat_id INTEGER NOT NULL,
                    chat_file_id INTEGER NOT NULL,
                    file_name TEXT NOT NULL DEFAULT '',
                    client_key TEXT NOT NULL DEFAULT '',
                    task_id INTEGER NOT NULL DEFAULT 0,
                    status TEXT NOT NULL DEFAULT 'new',
                    attempts INTEGER NOT NULL DEFAULT 0,
                    next_attempt_at TEXT NOT NULL,
                    last_error TEXT NOT NULL DEFAULT '',
                    created_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL
                )
                SQL,
            '005_pending_files_unique' => <<<'SQL'
                CREATE UNIQUE INDEX IF NOT EXISTS idx_pending_files_message_file
                    ON pending_files (message_id, chat_file_id)
                SQL,
            '006_pending_files_due' => <<<'SQL'
                CREATE INDEX IF NOT EXISTS idx_pending_files_due
                    ON pending_files (status, next_attempt_at)
                SQL,
            '007_settings' => <<<'SQL'
                CREATE TABLE IF NOT EXISTS settings (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                )
                SQL,
        ];
    }
}
