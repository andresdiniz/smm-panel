<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BrowserPushEndpointValidator;
use PHPUnit\Framework\TestCase;

final class BrowserPushEndpointValidatorTest extends TestCase
{
    public function testAllowsKnownHttpsPushServices(): void
    {
        $validator = new BrowserPushEndpointValidator();

        self::assertTrue($validator->isSupported('https://fcm.googleapis.com/fcm/send/endpoint'));
        self::assertTrue($validator->isSupported('https://updates.push.services.mozilla.com/wpush/v2/endpoint'));
        self::assertTrue($validator->isSupported('https://wns2.notify.windows.com/w/?token=endpoint'));
    }

    public function testRejectsUntrustedHostsSchemesPortsAndEmbeddedCredentials(): void
    {
        $validator = new BrowserPushEndpointValidator();

        self::assertFalse($validator->isSupported('https://localhost/push'));
        self::assertFalse($validator->isSupported('https://fcm.googleapis.com.attacker.test/push'));
        self::assertFalse($validator->isSupported('http://fcm.googleapis.com/push'));
        self::assertFalse($validator->isSupported('https://fcm.googleapis.com:8080/push'));
        self::assertFalse($validator->isSupported('https://user@fcm.googleapis.com/push'));
    }

    public function testAcceptsOnlyWebPushKeyLengthsAndUncompressedPublicKeys(): void
    {
        $validator = new BrowserPushEndpointValidator();
        $publicKey = rtrim(strtr(base64_encode("\x04".str_repeat("\x01", 64)), '+/', '-_'), '=');
        $authToken = rtrim(strtr(base64_encode(str_repeat("\x02", 16)), '+/', '-_'), '=');

        self::assertTrue($validator->hasValidKeys($publicKey, $authToken));
        self::assertFalse($validator->hasValidKeys('invalid', $authToken));
        self::assertFalse($validator->hasValidKeys($publicKey, 'invalid'));
    }
}
