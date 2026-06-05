<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Enum\OrderStatus;
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

    /** Pedidos pagos ainda não despachados para o provider */
    public function findPaidNotDispatched(): array
    {
        return $this->createQueryBuilder('o')
            ->where('o.status = :status')
            ->setParameter('status', OrderStatus::PAID)
            ->orderBy('o.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Pedidos em processamento sem atualização há mais de $minutes minutos */
    public function findStale(int $minutes = 30): array
    {
        $threshold = new \DateTimeImmutable("-{$minutes} minutes");

        return $this->createQueryBuilder('o')
            ->where('o.status IN (:statuses)')
            ->andWhere('o.updatedAt < :threshold')
            ->setParameter('statuses', [OrderStatus::QUEUED, OrderStatus::PROCESSING])
            ->setParameter('threshold', $threshold)
            ->orderBy('o.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Resumo financeiro por período */
    public function financialSummary(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('o')
            ->select(
                'COUNT(o.id) AS total_orders',
                'SUM(o.priceCents) AS total_revenue',
                'SUM(o.costCents) AS total_cost',
                'SUM(o.priceCents - o.costCents) AS total_profit'
            )
            ->where('o.status = :status')
            ->andWhere('o.createdAt BETWEEN :from AND :to')
            ->setParameter('status', OrderStatus::COMPLETED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleResult();
    }

    /** Pedidos por usuário com paginação */
    public function findByUserPaginated(int $userId, int $page = 1, int $limit = 20): array
    {
        return $this->createQueryBuilder('o')
            ->join('o.user', 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('o.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
