<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Kernel;
use PHPUnit\Framework\TestCase;

final class ProductionErrorHandlingTest extends TestCase
{
    public function testDebugIsDisabledOutsideDevelopmentEvenWhenEnvironmentRequestsIt(): void
    {
        foreach (['prod', 'staging'] as $environment) {
            self::assertFalse((new Kernel($environment, true))->isDebug(), $environment);
        }
    }

    public function testDevelopmentCanStillEnableDebug(): void
    {
        self::assertTrue((new Kernel('dev', true))->isDebug());
        self::assertFalse((new Kernel('dev', false))->isDebug());
    }
}
