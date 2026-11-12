<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use ReyhanItsMe\AuthEngine\Adapters\Native\ArraySession;
use ReyhanItsMe\AuthEngine\Adapters\Native\NativeAdapter;
use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Core\ConfigLoader;
use ReyhanItsMe\AuthEngine\Notifications\ArrayNotifier;

/**
 * Boots a full engine on a real database (in-memory SQLite by default).
 * Set AUTH_ENGINE_TEST_DSN / _USER / _PASS to run the suite on MySQL or PostgreSQL.
 */
abstract class EngineTestCase extends TestCase
{
    protected const KEY = 'test-key-0123456789-0123456789-0123456789';

    protected int $now = 1_700_000_000;
    protected ArraySession $session;
    protected ArrayNotifier $mail;
    protected PDO $pdo;

    protected function setUp(): void
    {
        $dsn = getenv('AUTH_ENGINE_TEST_DSN') ?: 'sqlite::memory:';
        $this->pdo = new PDO($dsn, getenv('AUTH_ENGINE_TEST_USER') ?: null, getenv('AUTH_ENGINE_TEST_PASS') ?: null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        foreach ((array) ConfigLoader::defaults()['database']['tables'] as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }

        $this->session = new ArraySession();
        $this->mail = new ArrayNotifier();
    }

    /** @param array<string, mixed> $overrides */
    protected function engine(array $overrides = [], bool $newSession = false): AuthManager
    {
        if ($newSession) {
            $this->session = new ArraySession();
        }

        $config = ConfigLoader::load(ConfigLoader::merge([
            'app' => ['key' => self::KEY, 'url' => 'https://app.test', 'name' => 'Acme'],
            'hashing' => ['algo' => 'bcrypt', 'bcrypt' => ['cost' => 4]],
        ], $overrides));

        $auth = new AuthManager($config, new NativeAdapter($config, $this->session), null, fn (): int => $this->now);
        $auth->setPdo($this->pdo)->setNotifier($this->mail);
        $auth->installSchema();

        return $auth;
    }
}
