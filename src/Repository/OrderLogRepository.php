<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrderLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderLog>
 */
class OrderLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderLog::class);
    }

    /**
     * Últimos N logs, ordenados do mais recente para o mais antigo.
     *
     * @return OrderLog[]
     */
    public function findLatest(int $limit = 100): array
    {
        return $this->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Todos os logs de uma ordem específica.
     *
     * @return OrderLog[]
     */
    public function findByOrder(int $orderId): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.order = :orderId')
            ->setParameter('orderId', $orderId)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Logs com erro das últimas N horas.
     *
     * @return OrderLog[]
     */
    public function findRecentErrors(int $hours = 24): array
    {
        $since = new \DateTimeImmutable("-{$hours} hours");

        return $this->createQueryBuilder('l')
            ->andWhere('l.errorMessage IS NOT NULL')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('l.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
