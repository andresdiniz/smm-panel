<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    public function findRecentByUser(User $user, int $limit = 10): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.user = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Retorna todos os pedidos do usuário filtrando por uma lista de status. */
    public function findByUserAndStatuses(User $user, array $statuses): array
    {
        return $this->createQueryBuilder('o')
            ->join('o.service', 's')
            ->addSelect('s')
            ->andWhere('o.user = :user')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', $statuses)
            ->orderBy('o.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('o')
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Pedidos em processamento com externalOrderId definido e sem
     * atualização de status há mais de $minutes minutos.
     * Usado pelo SyncStaleOrdersCommand para reenfileirar syncs perdidos.
     */
    public function findStale(int $minutes = 30): array
    {
        $cutoff = new \DateTimeImmutable("-{$minutes} minutes");

        return $this->createQueryBuilder('o')
            ->join('o.service', 's')
            ->where('o.status IN (:statuses)')
            ->andWhere('o.externalOrderId IS NOT NULL')
            ->andWhere('o.updatedAt < :cutoff')
            ->andWhere('s.providerSlug IS NOT NULL')
            ->setParameter('statuses', [
                Order::STATUS_PROCESSING,
                Order::STATUS_IN_PROGRESS,
                Order::STATUS_PARTIAL,
            ])
            ->setParameter('cutoff', $cutoff)
            ->orderBy('o.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByUserSince(User $user, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.user = :user')
            ->andWhere('o.createdAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countActiveByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.user = :user')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('user', $user)
            ->setParameter('statuses', [
                Order::STATUS_PENDING,
                Order::STATUS_PROCESSING,
                Order::STATUS_IN_PROGRESS,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function sumSpentByUserSince(User $user, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('SUM(o.amountCents)')
            ->andWhere('o.user = :user')
            ->andWhere('o.createdAt >= :since')
            ->andWhere('o.status != :cancelled')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->setParameter('cancelled', Order::STATUS_CANCELLED)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    public function countToday(): int
    {
        $start = new \DateTimeImmutable('today midnight');

        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.createdAt >= :start')
            ->setParameter('start', $start)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countThisMonth(): int
    {
        $start = new \DateTimeImmutable('first day of this month midnight');
        $end   = new \DateTimeImmutable('first day of next month midnight');

        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.createdAt >= :start')
            ->andWhere('o.createdAt < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
