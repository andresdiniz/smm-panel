<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\PasswordResetRequest;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class PasswordResetRequestTest extends TestCase
{
    public function testTokenIsUsableOnlyBeforeExpiryAndBeforeItIsConsumed(): void
    {
        $now = new \DateTimeImmutable('2026-10-31 12:00:00 UTC');
        $token = 'A'.str_repeat('b', 42);
        $request = new PasswordResetRequest(
            new User(),
            str_repeat('a', 32),
            hash('sha256', $token),
            $now,
            $now->modify('+1 hour'),
        );

        self::assertTrue($request->isUsable($token, $now));
        self::assertFalse($request->isUsable('wrong-token', $now));
        self::assertFalse($request->isUsable($token, $now->modify('+1 hour')));

        $request->markUsed($now);
        self::assertFalse($request->isUsable($token, $now));
    }
}
