<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Payment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function sumApprovedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amountCents), 0)')
            ->where('p.status = :status')
            ->andWhere('p.createdAt >= :since')
            ->setParameter('status', 'approved')
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    public function sumApprovedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amountCents), 0)')
            ->where('p.status = :status')
            ->andWhere('p.createdAt >= :from')
            ->andWhere('p.createdAt < :to')
            ->setParameter('status', 'approved')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()->getSingleScalarResult();
    }

    public function sumExpensesSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amountCents), 0)')
            ->where('p.type = :type')
            ->andWhere('p.createdAt >= :since')
            ->setParameter('type', 'expense')
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    public function sumFeesSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.amountCents), 0)')
            ->where('p.type = :type')
            ->andWhere('p.createdAt >= :since')
            ->setParameter('type', 'fee')
            ->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }
}
