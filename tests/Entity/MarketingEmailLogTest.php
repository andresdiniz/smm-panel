<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\MarketingEmailLog;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class MarketingEmailLogTest extends TestCase
{
    public function testRecordsOpensAndFirstClickTimePerLink(): void
    {
        $email = new MarketingEmailLog(new User(), 'campaign-id', 'Assunto');
        $email->setLinks([
            '0' => 'https://example.com/a',
            '1' => 'https://example.com/b',
        ]);
        $email->markSent();

        $email->recordOpen();
        $email->recordOpen();
        $email->recordClick('1');
        $firstClickAt = $email->getClickedLinks()['1'];
        $email->recordClick('1');
        $email->recordClick('unknown');

        self::assertSame(MarketingEmailLog::STATUS_SENT, $email->getStatus());
        self::assertSame(2, $email->getOpenCount());
        self::assertSame(2, $email->getClickCount());
        self::assertSame($firstClickAt, $email->getClickedLinks()['1']);
        self::assertCount(1, $email->getClickedLinks());
    }
}
