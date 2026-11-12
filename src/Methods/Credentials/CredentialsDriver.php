<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Methods\Credentials;

use ReyhanItsMe\AuthEngine\Contracts\AuthenticatableInterface;
use ReyhanItsMe\AuthEngine\Contracts\AuthMethodInterface;
use ReyhanItsMe\AuthEngine\Contracts\MutableUserProviderInterface;
use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Core\AuthResult;
use ReyhanItsMe\AuthEngine\Core\Identifier;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;
use ReyhanItsMe\AuthEngine\Exceptions\ValidationException;

/**
 * Password-based registration and login with multi-identifier support.
 */
final class CredentialsDriver implements AuthMethodInterface
{
    private const MAX_PASSWORD_BYTES = 1024;

    private static ?string $dummyHash = null;

    public function __construct(private readonly AuthManager $auth)
    {
    }

    public function name(): string
    {
        return 'credentials';
    }

    /**
     * Validate the input and create the account (does not log in).
     *
     * @param array<string, mixed> $data one or more configured identifiers, "password", optional "name"
     * @throws ValidationException with a field => message map
     */
    public function register(array $data): AuthenticatableInterface
    {
        $users = $this->auth->users();
        if (!$users instanceof MutableUserProviderInterface) {
            throw new ConfigurationException('Registration needs a user provider implementing MutableUserProviderInterface.');
        }

        $errors = [];
        $attributes = [];

        foreach ($this->identifiers() as $field) {
            $raw = $data[$field] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }

            $value = is_string($raw) ? Identifier::normalize($field, $raw) : null;
            if ($value === null) {
                $errors[$field] = sprintf('The %s is not valid.', str_replace('_', ' ', $field));
            } elseif ($users->findByIdentifier($field, $value) !== null) {
                $errors[$field] = sprintf('The %s is already taken.', str_replace('_', ' ', $field));
            } else {
                $attributes[$field] = $value;
            }
        }

        if ($attributes === [] && $errors === []) {
            $errors[$this->identifiers()[0]] = sprintf('One of [%s] is required.', implode(', ', $this->identifiers()));
        }

        $password = $data['password'] ?? null;
        $passwordError = is_string($password) ? $this->passwordError($password) : 'The password is required.';
        if ($passwordError !== null) {
            $errors['password'] = $passwordError;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $name = isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';
        $attributes['name'] = mb_substr($name !== '' ? $name : self::defaultName($attributes), 0, 255);
        $attributes['password'] = $this->auth->hasher()->make((string) $password);

        return $users->create($attributes);
    }

    public function attempt(string $identifier, string $password): AuthResult
    {
        $identifier = trim($identifier);
        $throttle = 'login|' . mb_strtolower($identifier) . '|' . ($this->auth->adapter()->ipAddress() ?? '-');

        if (($wait = $this->auth->throttled($throttle)) !== null) {
            return AuthResult::rateLimited($wait);
        }

        $user = null;
        if (strlen($password) <= self::MAX_PASSWORD_BYTES) {
            foreach (Identifier::candidates($identifier, $this->identifiers()) as $field => $value) {
                $user = $this->auth->users()->findByIdentifier($field, $value);
                if ($user !== null) {
                    break;
                }
            }
        }

        $hasher = $this->auth->hasher();
        $hash = $user?->getAuthPasswordHash();

        if ($hash === null) {
            // Spend the same time as a real check so unknown accounts cannot be detected by timing.
            $hasher->verify($password, self::$dummyHash ??= $hasher->make('auth-engine-dummy'));
            $valid = false;
        } else {
            $valid = $hasher->verify($password, $hash);
        }

        if (!$valid || $user === null) {
            $this->auth->hitThrottle($throttle);

            return AuthResult::failed('invalid_credentials');
        }

        $this->auth->clearThrottle($throttle);

        $users = $this->auth->users();
        if ($hasher->needsRehash((string) $hash) && $users instanceof MutableUserProviderInterface) {
            $users->updatePasswordHash($user->getAuthIdentifier(), $hasher->make($password));
        }

        return $this->auth->authenticate($user, 'credentials');
    }

    /** Change a password after validating it (the caller must have authorised the change). */
    public function setPassword(AuthenticatableInterface $user, string $password): void
    {
        if (($error = $this->passwordError($password)) !== null) {
            throw new ValidationException(['password' => $error]);
        }

        $users = $this->auth->users();
        if (!$users instanceof MutableUserProviderInterface) {
            throw new ConfigurationException('Changing passwords needs a user provider implementing MutableUserProviderInterface.');
        }

        $users->updatePasswordHash($user->getAuthIdentifier(), $this->auth->hasher()->make($password));
    }

    private function passwordError(string $password): ?string
    {
        $min = (int) $this->auth->config()->get('password.min_length', 8);

        return match (true) {
            mb_strlen($password) < $min => sprintf('The password must be at least %d characters.', $min),
            strlen($password) > self::MAX_PASSWORD_BYTES => 'The password is too long.',
            default => null,
        };
    }

    /** @return list<string> */
    private function identifiers(): array
    {
        return array_values((array) $this->auth->config()->get('identifiers', ['email']));
    }

    /** @param array<string, string> $attributes */
    private static function defaultName(array $attributes): string
    {
        return $attributes['username'] ?? (isset($attributes['email']) ? strstr($attributes['email'], '@', true) : '') ?: 'User';
    }
}
