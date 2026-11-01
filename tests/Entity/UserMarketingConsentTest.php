<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserMarketingConsentTest extends TestCase
{
    public function testMarketingConsentIsOptInAndItsTimestampTracksChanges(): void
    {
        $user = new User();

        self::assertFalse($user->hasMarketingConsent());
        self::assertNull($user->getMarketingConsentAt());

        $user->setMarketingConsent(true);
        $consentedAt = $user->getMarketingConsentAt();
        self::assertTrue($user->hasMarketingConsent());
        self::assertInstanceOf(\DateTimeImmutable::class, $consentedAt);

        $user->setMarketingConsent(true);
        self::assertSame($consentedAt, $user->getMarketingConsentAt());

        $user->setMarketingConsent(false);
        self::assertFalse($user->hasMarketingConsent());
        self::assertNull($user->getMarketingConsentAt());
    }
}
