<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CrmContact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CrmContact>
 */
class CrmContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CrmContact::class);
    }

    public function findOneByUserId(int $userId): ?CrmContact
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.user', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return CrmContact[]
     */
    public function findByTag(string $tag): array
    {
        // JSON_CONTAINS funciona em MySQL 5.7+/MariaDB 10.2+
        return $this->createQueryBuilder('c')
            ->where('JSON_CONTAINS(c.tags, :tag) = 1')
            ->setParameter('tag', json_encode($tag))
            ->orderBy('c.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return CrmContact[]
     */
    public function findRecentlyActive(int $days = 30, int $limit = 50): array
    {
        $since = new \DateTimeImmutable("-{$days} days");

        return $this->createQueryBuilder('c')
            ->where('c.updatedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('c.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
