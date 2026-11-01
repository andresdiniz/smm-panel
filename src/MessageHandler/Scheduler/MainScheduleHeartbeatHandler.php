<?php

declare(strict_types=1);

namespace App\MessageHandler\Scheduler;

use App\Entity\CronLog;
use App\Message\Scheduler\MainScheduleHeartbeatMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class MainScheduleHeartbeatHandler
{
    private const COMMAND = 'scheduler:main:heartbeat';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(MainScheduleHeartbeatMessage $message): void
    {
        $this->em->createQuery(
            'DELETE FROM App\Entity\CronLog cronLog WHERE cronLog.command = :command'
        )->setParameter('command', self::COMMAND)->execute();

        $log = (new CronLog(self::COMMAND))
            ->finish(true)
            ->setItemsProcessed(1);

        $this->em->persist($log);
        $this->em->flush();
    }
}
