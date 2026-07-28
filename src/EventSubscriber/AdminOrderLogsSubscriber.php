<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Order;
use App\Repository\OrderLogRepository;
use EasyCorp\Bundle\EasyAdminBundle\Event\BeforeCrudActionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injeta os OrderLogs no contexto do EasyAdmin antes de renderizar
 * a p\u00e1gina de detalhe de um Order, para que o TemplateField possa us\u00e1-los.
 */
class AdminOrderLogsSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly OrderLogRepository $orderLogRepo,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [BeforeCrudActionEvent::class => 'onBeforeCrudAction'];
    }

    public function onBeforeCrudAction(BeforeCrudActionEvent $event): void
    {
        $context = $event->getAdminContext();
        if ($context === null) {
            return;
        }

        $crud = $context->getCrud();
        if ($crud === null || $crud->getCurrentAction() !== 'detail') {
            return;
        }

        $entity = $context->getEntity()->getInstance();
        if (!$entity instanceof Order) {
            return;
        }

        // Passa os logs como template var global do contexto Twig do EasyAdmin
        $context->getTemplateRegistry(); // warm up — sem efeito colateral

        // Armazena no atributo do request para o template acessar via app.request
        $logs = $this->orderLogRepo->findBy(
            ['order' => $entity],
            ['createdAt' => 'DESC']
        );

        // Disponibiliza via atributo do request: {{ app.request.attributes.get('orderLogs') }}
        $event->getAdminContext()
              ->getRequest()
              ->attributes
              ->set('orderLogs', $logs);
    }
}
