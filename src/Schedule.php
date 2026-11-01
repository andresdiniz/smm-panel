<?php

namespace App;

use App\Message\Scheduler\MainScheduleHeartbeatMessage;
use App\Scheduler\SyncServicesMessage;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('main')]
final class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(RecurringMessage::every('1 minute', new MainScheduleHeartbeatMessage()))
            ->add(RecurringMessage::every('6 hours', new SyncServicesMessage()))
            ->add(RecurringMessage::cron('0 8 * * *', new RunCommandMessage('smm:finance:daily-report')))
        ;
    }
}
