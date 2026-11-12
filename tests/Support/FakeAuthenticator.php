<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Support;

use OpenSSLAsymmetricKey;
use ReyhanItsMe\AuthEngine\Core\Crypto;

/**
 * Software passkey: produces the same structures a browser returns from
 * navigator.credentials.create() / get(), signed with a real key.
 */
final class FakeAuthenticator
{
    public string $credentialId;
    public int $counter = 0;
    private OpenSSLAsymmetricKey $key;

    public function __construct(public string $rpId = 'app.test', public string $origin = 'https://app.test', private string $type = 'ec')
    {
        $this->credentialId = random_bytes(32);
        $this->key = openssl_pkey_new($type === 'ec'
            ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
            : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    }

    public function cose(): string
    {
        $details = openssl_pkey_get_details($this->key);

        if ($this->type === 'ec') {
            $x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
            $y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

            return "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $x . "\x22\x58\x20" . $y;
        }

        return "\xA4\x01\x03\x03\x39\x01\x00\x20\x59\x01\x00" . $details['rsa']['n'] . "\x21\x43" . $details['rsa']['e'];
    }

    /** @return array<string, mixed> PublicKeyCredential.toJSON() of a registration */
    public function attestation(string $challenge, int $flags = 0x45): array
    {
        $authData = hash('sha256', $this->rpId, true) . chr($flags) . pack('N', $this->counter)
            . str_repeat("\x00", 16) . pack('n', strlen($this->credentialId)) . $this->credentialId . $this->cose();

        $object = "\xA3\x63fmt\x64none\x67attStmt\xA0\x68authData\x59" . pack('n', strlen($authData)) . $authData;

        return [
            'id' => Crypto::base64UrlEncode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->clientData('webauthn.create', $challenge),
                'attestationObject' => Crypto::base64UrlEncode($object),
                'transports' => ['internal'],
            ],
        ];
    }

    /** @return array<string, mixed> PublicKeyCredential.toJSON() of a login */
    public function assertion(string $challenge, int $flags = 0x05): array
    {
        $this->counter++;
        $clientData = $this->clientData('webauthn.get', $challenge);
        $authData = hash('sha256', $this->rpId, true) . chr($flags) . pack('N', $this->counter);

        openssl_sign($authData . hash('sha256', (string) Crypto::base64UrlDecode($clientData), true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return [
            'id' => Crypto::base64UrlEncode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $clientData,
                'authenticatorData' => Crypto::base64UrlEncode($authData),
                'signature' => Crypto::base64UrlEncode($signature),
            ],
        ];
    }

    private function clientData(string $type, string $challenge): string
    {
        return Crypto::base64UrlEncode((string) json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $this->origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES));
    }
}
