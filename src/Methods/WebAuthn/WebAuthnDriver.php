<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Methods\WebAuthn;

use ReyhanItsMe\AuthEngine\Contracts\AuthenticatableInterface;
use ReyhanItsMe\AuthEngine\Contracts\AuthMethodInterface;
use ReyhanItsMe\AuthEngine\Contracts\NotifiableInterface;
use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Core\AuthResult;
use ReyhanItsMe\AuthEngine\Core\Crypto;
use ReyhanItsMe\AuthEngine\Exceptions\WebAuthnException;

/**
 * Passkeys (WebAuthn Level 2): FaceID, TouchID, Windows Hello, security keys.
 *
 * All binary values travel as base64url, matching PublicKeyCredential.toJSON()
 * and PublicKeyCredential.parseCreationOptionsFromJSON() in browsers.
 * Attestation is requested as "none": the authenticator model is not verified.
 */
final class WebAuthnDriver implements AuthMethodInterface
{
    private const FLAG_USER_PRESENT = 0x01;
    private const FLAG_USER_VERIFIED = 0x04;
    private const FLAG_ATTESTED_DATA = 0x40;
    private const CHALLENGE_TTL = 300;

    public function __construct(private readonly AuthManager $auth)
    {
    }

    public function name(): string
    {
        return 'webauthn';
    }

    /**
     * Options for navigator.credentials.create(). The user must be logged in
     * (or otherwise verified) before a passkey is added to the account.
     *
     * @return array<string, mixed>
     */
    public function registrationOptions(AuthenticatableInterface $user, ?string $displayName = null): array
    {
        $id = (string) $user->getAuthIdentifier();
        $name = ($user instanceof NotifiableInterface ? $user->routeNotificationFor('email') : null) ?? $id;

        return [
            'challenge' => $this->newChallenge('webauthn_register', $id),
            'rp' => ['id' => $this->rpId(), 'name' => $this->rpName()],
            'user' => ['id' => Crypto::base64UrlEncode($id), 'name' => $name, 'displayName' => $displayName ?? $name],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => CoseKey::ES256],
                ['type' => 'public-key', 'alg' => CoseKey::RS256],
            ],
            'timeout' => $this->timeout(),
            'attestation' => 'none',
            'excludeCredentials' => $this->descriptors($user),
            'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => $this->userVerification()],
        ];
    }

    /**
     * Verify the browser's response to registrationOptions() and store the passkey.
     *
     * @param array<string, mixed> $credential PublicKeyCredential.toJSON()
     * @return array{id: int, name: ?string, created_at: int}
     * @throws WebAuthnException when verification fails
     */
    public function register(AuthenticatableInterface $user, array $credential, ?string $name = null): array
    {
        $userId = (string) $user->getAuthIdentifier();
        $challenge = $this->takeChallenge('webauthn_register');
        if ($challenge === null || $challenge['user_id'] !== $userId) {
            throw new WebAuthnException('The passkey registration expired. Please try again.');
        }

        $response = is_array($credential['response'] ?? null) ? $credential['response'] : [];
        $clientData = $this->clientData($response['clientDataJSON'] ?? null, 'webauthn.create', $challenge['challenge']);

        $attestation = self::decode($response['attestationObject'] ?? null, 'attestationObject');
        $object = CborDecoder::decode($attestation);
        $authData = is_array($object) && is_string($object['authData'] ?? null) ? $object['authData'] : '';

        $flags = $this->verifyAuthenticatorData($authData);
        if (($flags & self::FLAG_ATTESTED_DATA) === 0 || strlen($authData) < 55) {
            throw new WebAuthnException('The authenticator returned no credential data.');
        }

        $idLength = unpack('n', substr($authData, 53, 2))[1];
        $credentialId = substr($authData, 55, $idLength);
        if ($idLength < 1 || $idLength > 1023 || strlen($credentialId) !== $idLength) {
            throw new WebAuthnException('Invalid credential ID.');
        }

        $cose = CborDecoder::decode(substr($authData, 55 + $idLength));
        $pem = CoseKey::toPem(is_array($cose) ? $cose : []);

        $db = $this->auth->db();
        $hash = hash('sha256', $credentialId);
        if ($db->first("SELECT id FROM {$db->table('passkeys')} WHERE credential_hash = ?", [$hash]) !== null) {
            throw new WebAuthnException('This passkey is already registered.');
        }

        $transports = array_filter((array) ($response['transports'] ?? []), static fn (mixed $t): bool => is_string($t) && preg_match('/^[a-z-]{2,16}$/', $t) === 1);
        $name = $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null;
        $now = $this->auth->now();

        $id = $db->insert($db->table('passkeys'), [
            'user_id' => $userId,
            'credential_hash' => $hash,
            'credential_id' => Crypto::base64UrlEncode($credentialId),
            'public_key' => $pem,
            'sign_count' => unpack('N', substr($authData, 33, 4))[1],
            'name' => $name,
            'transports' => implode(',', array_slice($transports, 0, 8)),
            'created_at' => $now,
        ]);
        unset($clientData);

        return ['id' => (int) $id, 'name' => $name, 'created_at' => $now];
    }

    /**
     * Options for navigator.credentials.get().
     *
     * @param AuthenticatableInterface|null $user restrict to this user's passkeys; null allows any
     *                                            discoverable passkey ("sign in with a passkey" button)
     * @return array<string, mixed>
     */
    public function authenticationOptions(?AuthenticatableInterface $user = null): array
    {
        return [
            'challenge' => $this->newChallenge('webauthn_login', $user === null ? null : (string) $user->getAuthIdentifier()),
            'rpId' => $this->rpId(),
            'timeout' => $this->timeout(),
            'userVerification' => $this->userVerification(),
            'allowCredentials' => $user === null ? [] : $this->descriptors($user),
        ];
    }

    /**
     * Verify the browser's response to authenticationOptions() and log the owner in.
     * A user-verified passkey (biometrics/PIN) counts as multi-factor.
     *
     * @param array<string, mixed> $credential PublicKeyCredential.toJSON()
     */
    public function authenticate(array $credential): AuthResult
    {
        try {
            [$user, $verified] = $this->verifyAssertion($credential);
        } catch (WebAuthnException) {
            return AuthResult::failed('invalid_passkey');
        }

        return $this->auth->authenticate($user, 'webauthn', $verified);
    }

    /**
     * @return list<array{id: int, name: ?string, created_at: int, last_used_at: ?int}>
     */
    public function passkeys(AuthenticatableInterface $user): array
    {
        $db = $this->auth->db();
        $rows = $db->all(
            "SELECT id, name, created_at, last_used_at FROM {$db->table('passkeys')} WHERE user_id = ? ORDER BY id",
            [(string) $user->getAuthIdentifier()],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'] === null ? null : (string) $row['name'],
            'created_at' => (int) $row['created_at'],
            'last_used_at' => $row['last_used_at'] === null ? null : (int) $row['last_used_at'],
        ], $rows);
    }

    public function delete(AuthenticatableInterface $user, int $passkeyId): bool
    {
        $db = $this->auth->db();

        return $db->run(
            "DELETE FROM {$db->table('passkeys')} WHERE id = ? AND user_id = ?",
            [$passkeyId, (string) $user->getAuthIdentifier()],
        )->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $credential
     * @return array{0: AuthenticatableInterface, 1: bool} the owner and whether the user was verified
     */
    private function verifyAssertion(array $credential): array
    {
        $challenge = $this->takeChallenge('webauthn_login');
        if ($challenge === null) {
            throw new WebAuthnException('The passkey challenge expired.');
        }

        $response = is_array($credential['response'] ?? null) ? $credential['response'] : [];
        $credentialId = self::decode($credential['id'] ?? null, 'id');

        $db = $this->auth->db();
        $table = $db->table('passkeys');
        $row = $db->first("SELECT * FROM {$table} WHERE credential_hash = ?", [hash('sha256', $credentialId)]);
        if ($row === null || ($challenge['user_id'] !== null && $challenge['user_id'] !== (string) $row['user_id'])) {
            throw new WebAuthnException('Unknown passkey.');
        }

        $clientData = $this->clientData($response['clientDataJSON'] ?? null, 'webauthn.get', $challenge['challenge']);
        $authData = self::decode($response['authenticatorData'] ?? null, 'authenticatorData');
        $signature = self::decode($response['signature'] ?? null, 'signature');
        $flags = $this->verifyAuthenticatorData($authData);

        $signed = $authData . hash('sha256', $clientData, true);
        if (openssl_verify($signed, $signature, (string) $row['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
            throw new WebAuthnException('Invalid passkey signature.');
        }

        // A counter that does not increase means the credential may have been cloned.
        $count = unpack('N', substr($authData, 33, 4))[1];
        $stored = (int) $row['sign_count'];
        if (($count !== 0 || $stored !== 0) && $count <= $stored) {
            throw new WebAuthnException('Passkey counter mismatch.');
        }

        $user = $this->auth->users()->findById((string) $row['user_id']);
        if ($user === null) {
            throw new WebAuthnException('Unknown passkey.');
        }

        $db->run("UPDATE {$table} SET sign_count = ?, last_used_at = ? WHERE id = ?", [$count, $this->auth->now(), $row['id']]);

        return [$user, ($flags & self::FLAG_USER_VERIFIED) !== 0];
    }

    /** @return string the raw clientDataJSON bytes */
    private function clientData(mixed $encoded, string $type, string $challenge): string
    {
        $raw = self::decode($encoded, 'clientDataJSON');
        $data = json_decode($raw, true);

        if (
            !is_array($data)
            || ($data['type'] ?? null) !== $type
            || !is_string($data['challenge'] ?? null)
            || !hash_equals($challenge, $data['challenge'])
        ) {
            throw new WebAuthnException('The passkey response does not match the challenge.');
        }

        if (!in_array($data['origin'] ?? null, $this->origins(), true) || ($data['crossOrigin'] ?? false) === true) {
            throw new WebAuthnException('The passkey response came from an unexpected origin.');
        }

        return $raw;
    }

    /** @return int the flags byte */
    private function verifyAuthenticatorData(string $authData): int
    {
        if (strlen($authData) < 37 || !hash_equals(hash('sha256', $this->rpId(), true), substr($authData, 0, 32))) {
            throw new WebAuthnException('The passkey belongs to a different site.');
        }

        $flags = ord($authData[32]);

        if (($flags & self::FLAG_USER_PRESENT) === 0) {
            throw new WebAuthnException('User presence was not confirmed.');
        }

        if ($this->userVerification() === 'required' && ($flags & self::FLAG_USER_VERIFIED) === 0) {
            throw new WebAuthnException('User verification is required.');
        }

        return $flags;
    }

    private function newChallenge(string $entry, ?string $userId): string
    {
        $challenge = Crypto::base64UrlEncode(random_bytes(32));
        $this->auth->session()->put($this->auth->sessionKey($entry), [
            'challenge' => $challenge,
            'user_id' => $userId,
            'expires_at' => $this->auth->now() + self::CHALLENGE_TTL,
        ]);

        return $challenge;
    }

    /**
     * Challenges are single-use: reading one removes it.
     *
     * @return array{challenge: string, user_id: ?string}|null
     */
    private function takeChallenge(string $entry): ?array
    {
        $session = $this->auth->session();
        $key = $this->auth->sessionKey($entry);
        $stored = $session->get($key);
        $session->forget($key);

        if (!is_array($stored) || !is_string($stored['challenge'] ?? null) || (int) ($stored['expires_at'] ?? 0) <= $this->auth->now()) {
            return null;
        }

        return ['challenge' => $stored['challenge'], 'user_id' => isset($stored['user_id']) ? (string) $stored['user_id'] : null];
    }

    /** @return list<array{type: string, id: string, transports?: list<string>}> */
    private function descriptors(AuthenticatableInterface $user): array
    {
        $db = $this->auth->db();
        $rows = $db->all(
            "SELECT credential_id, transports FROM {$db->table('passkeys')} WHERE user_id = ?",
            [(string) $user->getAuthIdentifier()],
        );

        return array_map(static function (array $row): array {
            $descriptor = ['type' => 'public-key', 'id' => (string) $row['credential_id']];
            if ((string) $row['transports'] !== '') {
                $descriptor['transports'] = explode(',', (string) $row['transports']);
            }

            return $descriptor;
        }, $rows);
    }

    private static function decode(mixed $value, string $field): string
    {
        $bytes = is_string($value) && strlen($value) <= 65536 ? Crypto::base64UrlDecode($value) : null;

        if ($bytes === null) {
            throw new WebAuthnException(sprintf('Missing or malformed "%s".', $field));
        }

        return $bytes;
    }

    private function rpId(): string
    {
        $configured = $this->auth->config()->get('drivers.webauthn.rp_id');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return (string) (parse_url($this->auth->adapter()->baseUrl(), PHP_URL_HOST) ?: 'localhost');
    }

    private function rpName(): string
    {
        $configured = $this->auth->config()->get('drivers.webauthn.rp_name');

        return is_string($configured) && $configured !== '' ? $configured : (string) $this->auth->config()->get('app.name', 'Auth Engine');
    }

    /** @return list<string> */
    private function origins(): array
    {
        $configured = array_values(array_filter((array) $this->auth->config()->get('drivers.webauthn.origins', []), 'is_string'));
        if ($configured !== []) {
            return array_map(static fn (string $origin): string => rtrim($origin, '/'), $configured);
        }

        $parts = parse_url($this->auth->adapter()->baseUrl());
        $origin = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? 'localhost') . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return [$origin];
    }

    private function userVerification(): string
    {
        $value = $this->auth->config()->get('drivers.webauthn.user_verification', 'preferred');

        return in_array($value, ['required', 'preferred', 'discouraged'], true) ? $value : 'preferred';
    }

    private function timeout(): int
    {
        return max(1000, (int) $this->auth->config()->get('drivers.webauthn.timeout', 60000));
    }
}
