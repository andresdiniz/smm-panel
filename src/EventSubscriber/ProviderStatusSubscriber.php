<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ProviderCredential;
use App\Entity\Service;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\Event\LifecycleEventArgs;

/**
 * Fallback: garante a cascata active/inactive também para qualquer
 * persistência de ProviderCredential fora do EasyAdmin
 * (ex: comandos de console, API interna, testes).
 *
 * Usa preUpdate onde o changeSet ainda está disponível no UoW.
 * O EasyAdmin já trata via updateEntity() no Controller.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
final class ProviderStatusSubscriber
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function preUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        if (!$entity instanceof ProviderCredential) {
            return;
        }

        $uow     = $this->em->getUnitOfWork();
        $changes = $uow->getEntityChangeSet($entity);

        if (!array_key_exists('active', $changes)) {
            return;
        }

        $newActive = $entity->isActive();
        $slug      = $entity->getSlug();

        $this->em->createQuery(
            'UPDATE ' . Service::class . ' s
             SET s.active = :active
             WHERE s.providerSlug = :slug'
        )
        ->setParameter('active', $newActive)
        ->setParameter('slug', $slug)
        ->execute();
    }
}
