<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProviderCredential;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProviderCredential>
 */
class ProviderCredentialRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProviderCredential::class);
    }

    public function findBySlug(string $type, string $slug): ?ProviderCredential
    {
        return $this->findOneBy(['type' => $type, 'slug' => $slug, 'active' => true]);
    }

    /** @return array<string, ProviderCredential> indexado por slug */
    public function findAllActiveByType(string $type): array
    {
        $rows = $this->createQueryBuilder('p')
            ->andWhere('p.type = :type')
            ->andWhere('p.active = true')
            ->setParameter('type', $type)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row->getSlug()] = $row;
        }
        return $indexed;
    }
}
