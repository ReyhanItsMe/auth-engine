<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Database;

/**
 * Creates the package tables (idempotent: CREATE TABLE IF NOT EXISTS).
 * Supports SQLite, MySQL/MariaDB and PostgreSQL.
 */
final class Schema
{
    private function __construct()
    {
    }

    public static function install(Connection $db): void
    {
        foreach (self::statements($db) as $sql) {
            $db->run($sql);
        }
    }

    /** @return list<string> */
    public static function statements(Connection $db): array
    {
        $id = match ($db->driver()) {
            'mysql' => 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY',
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            default => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        };
        $t = static fn (string $key): string => $db->table($key);

        return [
            "CREATE TABLE IF NOT EXISTS {$t('users')} (
                id {$id},
                name VARCHAR(255) NULL,
                email VARCHAR(255) NULL UNIQUE,
                username VARCHAR(64) NULL UNIQUE,
                phone_number VARCHAR(32) NULL UNIQUE,
                password VARCHAR(255) NULL,
                email_verified_at TIMESTAMP NULL,
                created_at TIMESTAMP NULL,
                updated_at TIMESTAMP NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$t('tokens')} (
                id {$id},
                type VARCHAR(32) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                token_hash VARCHAR(64) NOT NULL UNIQUE,
                attempts INTEGER NOT NULL DEFAULT 0,
                expires_at BIGINT NOT NULL,
                created_at BIGINT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$t('rate_limits')} (
                bucket VARCHAR(64) NOT NULL PRIMARY KEY,
                attempts INTEGER NOT NULL DEFAULT 0,
                expires_at BIGINT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$t('mfa')} (
                user_id VARCHAR(64) NOT NULL PRIMARY KEY,
                secret TEXT NOT NULL,
                confirmed_at BIGINT NULL,
                last_step BIGINT NOT NULL DEFAULT 0,
                recovery_codes TEXT NULL,
                created_at BIGINT NOT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$t('passkeys')} (
                id {$id},
                user_id VARCHAR(64) NOT NULL,
                credential_hash VARCHAR(64) NOT NULL UNIQUE,
                credential_id TEXT NOT NULL,
                public_key TEXT NOT NULL,
                sign_count BIGINT NOT NULL DEFAULT 0,
                name VARCHAR(255) NULL,
                transports VARCHAR(255) NULL,
                created_at BIGINT NOT NULL,
                last_used_at BIGINT NULL
            )",
            "CREATE TABLE IF NOT EXISTS {$t('oauth_accounts')} (
                id {$id},
                user_id VARCHAR(64) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                provider_user_id VARCHAR(191) NOT NULL,
                email VARCHAR(255) NULL,
                name VARCHAR(255) NULL,
                avatar TEXT NULL,
                created_at BIGINT NOT NULL,
                UNIQUE (provider, provider_user_id)
            )",
        ];
    }
}
