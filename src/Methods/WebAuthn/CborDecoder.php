<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Methods\WebAuthn;

use ReyhanItsMe\AuthEngine\Exceptions\WebAuthnException;

/**
 * Minimal CBOR (RFC 8949) decoder covering what WebAuthn needs:
 * integers, byte/text strings, arrays, maps, tags and simple values,
 * with definite lengths only.
 */
final class CborDecoder
{
    private const MAX_DEPTH = 8;

    private int $offset = 0;

    private function __construct(private readonly string $data)
    {
    }

    public static function decode(string $data): mixed
    {
        return self::decodeFirst($data)[0];
    }

    /**
     * Decode the first item and report how many bytes it used.
     *
     * @return array{0: mixed, 1: int}
     */
    public static function decodeFirst(string $data): array
    {
        $decoder = new self($data);
        $value = $decoder->item(0);

        return [$value, $decoder->offset];
    }

    private function item(int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new WebAuthnException('CBOR data is nested too deeply.');
        }

        $initial = ord($this->read(1));
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                default => throw new WebAuthnException('Unsupported CBOR simple value.'),
            };
        }

        $value = $this->argument($info);

        switch ($major) {
            case 0:
                return $value;
            case 1:
                return -1 - $value;
            case 2:
            case 3:
                return $this->read($value);
            case 4:
                $list = [];
                for ($i = 0; $i < $value; $i++) {
                    $list[] = $this->item($depth + 1);
                }

                return $list;
            case 5:
                $map = [];
                for ($i = 0; $i < $value; $i++) {
                    $key = $this->item($depth + 1);
                    if (!is_int($key) && !is_string($key)) {
                        throw new WebAuthnException('Unsupported CBOR map key.');
                    }
                    $map[$key] = $this->item($depth + 1);
                }

                return $map;
            default: // 6: tag, the tagged value is returned as-is
                return $this->item($depth + 1);
        }
    }

    private function argument(int $info): int
    {
        if ($info < 24) {
            return $info;
        }

        $value = match ($info) {
            24 => ord($this->read(1)),
            25 => unpack('n', $this->read(2))[1],
            26 => unpack('N', $this->read(4))[1],
            27 => unpack('J', $this->read(8))[1],
            default => throw new WebAuthnException('Indefinite-length CBOR is not supported.'),
        };

        if ($value < 0) {
            throw new WebAuthnException('CBOR integer out of range.');
        }

        return $value;
    }

    private function read(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->data)) {
            throw new WebAuthnException('Truncated CBOR data.');
        }

        $chunk = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $chunk;
    }
}
