<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Database;

use ReyhanItsMe\AuthEngine\Contracts\AuthenticatableInterface;
use ReyhanItsMe\AuthEngine\Contracts\MutableUserProviderInterface;
use ReyhanItsMe\AuthEngine\Core\ConfigLoader;

/**
 * Default user provider on top of the "users" table
 * (compatible with Laravel's default users table).
 */
final class PdoUserProvider implements MutableUserProviderInterface
{
    private const WRITABLE = ['name', 'email', 'username', 'phone_number', 'password', 'email_verified_at'];

    public function __construct(private readonly Connection $db)
    {
    }

    public function findById(int|string $id): ?AuthenticatableInterface
    {
        return $this->fetch('id', (string) $id);
    }

    public function findByIdentifier(string $field, string $value): ?AuthenticatableInterface
    {
        // The column name is whitelisted; the value is always bound.
        return in_array($field, ConfigLoader::IDENTIFIERS, true) ? $this->fetch($field, $value) : null;
    }

    public function create(array $attributes): AuthenticatableInterface
    {
        $values = array_filter(
            array_intersect_key($attributes, array_flip(self::WRITABLE)),
            static fn (mixed $value): bool => $value !== null,
        );
        $values['created_at'] = $values['updated_at'] = gmdate('Y-m-d H:i:s');

        $id = $this->db->insert($this->db->table('users'), $values);

        return new GenericUser(['id' => $id] + $values + array_fill_keys(self::WRITABLE, null));
    }

    public function updatePasswordHash(int|string $id, string $hash): void
    {
        $this->db->run(
            "UPDATE {$this->db->table('users')} SET password = ?, updated_at = ? WHERE id = ?",
            [$hash, gmdate('Y-m-d H:i:s'), (string) $id],
        );
    }

    private function fetch(string $column, string $value): ?AuthenticatableInterface
    {
        $row = $this->db->first("SELECT * FROM {$this->db->table('users')} WHERE {$column} = ? LIMIT 1", [$value]);

        return $row === null ? null : new GenericUser($row);
    }
}
