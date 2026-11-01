<?php

declare(strict_types=1);

namespace App\Service;

final class BrowserPushEndpointValidator
{
    private const ALLOWED_HOSTS = [
        'fcm.googleapis.com',
        'updates.push.services.mozilla.com',
        'web.push.apple.com',
    ];

    public function isSupported(string $endpoint): bool
    {
        $scheme = strtolower((string) parse_url($endpoint, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        $port = parse_url($endpoint, PHP_URL_PORT);

        return $scheme === 'https'
            && ($port === null || $port === 443)
            && (in_array($host, self::ALLOWED_HOSTS, true) || str_ends_with($host, '.notify.windows.com'))
            && parse_url($endpoint, PHP_URL_USER) === null
            && parse_url($endpoint, PHP_URL_PASS) === null;
    }

    public function hasValidKeys(string $publicKey, string $authToken): bool
    {
        $decodedPublicKey = $this->decodeBase64Url($publicKey);
        $decodedAuthToken = $this->decodeBase64Url($authToken);

        return $decodedPublicKey !== false
            && strlen($decodedPublicKey) === 65
            && $decodedPublicKey[0] === "\x04"
            && $decodedAuthToken !== false
            && strlen($decodedAuthToken) === 16;
    }

    private function decodeBase64Url(string $value): string|false
    {
        $padding = str_repeat('=', (4 - strlen($value) % 4) % 4);

        return base64_decode(strtr($value.$padding, '-_', '+/'), true);
    }
}
