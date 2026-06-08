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
 * Desabilita/reabilita em cascata todos os Service vinculados a um
 * ProviderCredential quando o campo `active` do provedor muda.
 *
 * Funciona com o toggle switch do EasyAdmin e qualquer outra persistência
 * da entidade.
 *
 * Exemplo:
 *   Provedor "smmkings" desabilitado → todos os Service com
 *   providerSlug = "smmkings" ficam active = false automaticamente.
 *   Ao reabilitar o provedor → os mesmos serviços voltam a active = true.
 */
#[AsDoctrineListener(event: Events::postUpdate)]
final class ProviderStatusSubscriber
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        // Somente ProviderCredential do tipo SMM interessa —
        // gateways de pagamento não têm Service vinculados.
        if (!$entity instanceof ProviderCredential) {
            return;
        }

        // Verifica se o campo `active` realmente mudou neste update.
        $uow      = $this->em->getUnitOfWork();
        $changes  = $uow->getEntityChangeSet($entity);

        if (!array_key_exists('active', $changes)) {
            return; // Outro campo foi alterado, não precisa fazer nada.
        }

        $newActive = $entity->isActive(); // valor atual (após o update)
        $slug      = $entity->getSlug();

        // UPDATE único em massa — sem carregar entidades em memória.
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
