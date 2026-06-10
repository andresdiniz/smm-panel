<?php

declare(strict_types=1);

namespace App\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Agenda a sincronização automática de pedidos SMM.
 *
 * Frequência: a cada 2 minutos (ajuste a expressão cron conforme necessidade).
 *
 * Para rodar em produção (Linux), mantenha o worker ativo:
 *   php bin/console messenger:consume scheduler_default --time-limit=3600
 *
 * Sugestão com Supervisor (/etc/supervisor/conf.d/smm-scheduler.conf):
 *   [program:smm-scheduler]
 *   command=php /var/www/smm-panel/bin/console messenger:consume scheduler_default --time-limit=3600
 *   autostart=true
 *   autorestart=true
 *   stderr_logfile=/var/log/smm-scheduler.err.log
 *   stdout_logfile=/var/log/smm-scheduler.out.log
 */
#[AsSchedule('default')]
final class OrderSyncSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $cache,
    ) {}

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->stateful($this->cache)          // retoma agendamentos após restart
            ->add(
                RecurringMessage::cron(
                    '*/2 * * * *',             // a cada 2 minutos
                    new SyncOrdersMessage(100)  // processa até 100 pedidos por ciclo
                )
            );
    }
}
