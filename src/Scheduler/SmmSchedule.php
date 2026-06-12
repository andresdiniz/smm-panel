<?php

declare(strict_types=1);

namespace App\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule('smm')]
final class SmmSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)
            ->add(
                // Reconcilia pagamentos Pix pendentes a cada 5 min via Command
                RecurringMessage::every('5 minutes', new RunCommandMessage('smm:billing:reconcile'))
            )
            ->add(
                // Sincroniza saldo dos providers a cada hora
                RecurringMessage::every('1 hour', new \App\Message\Scheduler\SyncProviderBalanceMessage())
            )
            ->add(
                // Relatório financeiro diário às 8h
                RecurringMessage::cron('0 8 * * *', new \App\Message\Scheduler\DailyFinanceReportMessage())
            )
            ->add(
                // Envia e-mails de remarketing (carrinho abandonado) a cada hora
                RecurringMessage::every('1 hour', new \App\Message\Scheduler\RemarketingEmailsMessage())
            )
            ->add(
                // Limpa tokens expirados de verificação de e-mail (1x/dia meia-noite)
                RecurringMessage::cron('0 0 * * *', new \App\Message\Scheduler\CleanupExpiredTokensMessage())
            );
    }
}
