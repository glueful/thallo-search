<?php

declare(strict_types=1);

namespace Thallo\Search\Query;

/**
 * Signs and verifies cursors with an HMAC over their payload (search block spec §3.4). A token that
 * is malformed, tampered with, or bound to another query verifies as null.
 */
final class CursorSigner
{
    private const MAX_TOKEN = 512;

    public function __construct(private readonly string $key)
    {
    }

    public function sign(Cursor $cursor): string
    {
        $payload = self::b64(json_encode([$cursor->binding, $cursor->rawOffset], JSON_THROW_ON_ERROR));
        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, $this->key, true));
    }

    public function verify(mixed $token, string $binding): ?Cursor
    {
        if (
            !is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN
            || substr_count($token, '.') !== 1
        ) {
            return null;
        }
        [$payload, $mac] = explode('.', $token);
        if (!hash_equals(self::b64(hash_hmac('sha256', $payload, $this->key, true)), $mac)) {
            return null;
        }
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        $data = $decoded === false ? null : json_decode($decoded, true);
        if (!is_array($data) || ($data[0] ?? null) !== $binding || !is_int($data[1] ?? null) || $data[1] < 0) {
            return null;
        }
        return new Cursor($binding, $data[1]);
    }

    private static function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
