<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Database;

use PDO;
use PDOStatement;
use ReyhanItsMe\AuthEngine\Core\Config;
use ReyhanItsMe\AuthEngine\Exceptions\AuthEngineException;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;

/**
 * Thin PDO helper. Every value goes through bound parameters; table names
 * come from config and are validated before they are placed in SQL.
 */
final class Connection
{
    public function __construct(private readonly PDO $pdo, private readonly Config $config)
    {
    }

    public static function fromConfig(Config $config): self
    {
        $dsn = $config->get('database.dsn');
        if (!is_string($dsn) || $dsn === '') {
            throw new ConfigurationException(
                'No database configured. Set "database.dsn" (AUTH_ENGINE_DB_DSN) or call Auth::setPdo($pdo).',
            );
        }

        $pdo = new PDO(
            $dsn,
            $config->get('database.username'),
            $config->get('database.password'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );

        return new self($pdo, $config);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** sqlite | mysql | pgsql | ... */
    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    public function table(string $key): string
    {
        $name = $this->config->get('database.tables.' . $key);

        if (!is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) !== 1) {
            throw new ConfigurationException(sprintf('Invalid table name configured for "database.tables.%s".', $key));
        }

        return $name;
    }

    /** @param list<mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        if ($statement === false || !$statement->execute($params)) {
            throw new AuthEngineException('Database query failed.');
        }

        return $statement;
    }

    /**
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $values column => value (column names must be trusted)
     */
    public function insert(string $table, array $values): string
    {
        $columns = array_keys($values);
        foreach ($columns as $column) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) !== 1) {
                throw new AuthEngineException('Invalid column name.');
            }
        }

        $this->run(
            sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))),
            array_values($values),
        );

        return (string) $this->pdo->lastInsertId();
    }
}
