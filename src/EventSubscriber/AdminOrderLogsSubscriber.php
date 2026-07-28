<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeCrudActionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber vazio mantido para não quebrar o container caso haja referência.
 * A lógica de logs foi movida para o TemplateField via Order::getOrderLogs().
 *
 * @deprecated Pode ser deletado com segurança.
 */
class AdminOrderLogsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [BeforeCrudActionEvent::class => 'onBeforeCrudAction'];
    }

    public function onBeforeCrudAction(BeforeCrudActionEvent $event): void
    {
        // Nenhuma ação necessária — os logs são carregados via
        // Order::getOrderLogs() diretamente pelo TemplateField do EasyAdmin.
    }
}
