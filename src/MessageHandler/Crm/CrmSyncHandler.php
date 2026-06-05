<?php

declare(strict_types=1);

namespace App\MessageHandler\Crm;

use App\Message\Crm\CrmSyncMessage;
use App\Repository\CrmContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CrmSyncHandler
{
    public function __construct(
        private readonly CrmContactRepository   $contacts,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(CrmSyncMessage $message): void
    {
        $contact = $this->contacts->findOneByUserId($message->userId);
        if (!$contact) {
            return;
        }

        $contact->addEvent($message->event, $message->payload);

        // Segmentação automática de tags baseada no evento
        match ($message->event) {
            'order.completed'        => $contact->addTag('buyer'),
            'order.failed'           => $contact->addTag('failed_order'),
            'payment.chargeback'     => $contact->addTag('chargeback'),
            'checkout.abandoned'     => $contact->addTag('abandoned_cart'),
            'user.registered'        => $contact->addTag('new_user'),
            default                  => null,
        };

        $this->em->flush();
    }
}
