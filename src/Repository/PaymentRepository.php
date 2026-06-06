<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Payment;
use App\Entity\User;
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

    public function findRecentByUser(User $user, int $limit = 20): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findLastDepositByUser(User $user): ?Payment
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.type = :type')
            ->setParameter('user', $user)
            ->setParameter('type', 'deposit')
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function sumApprovedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('SUM(p.amountCents)')
            ->andWhere('p.status = :status')
            ->andWhere('p.type = :type')
            ->andWhere('p.createdAt >= :since')
            ->setParameter('status', 'approved')
            ->setParameter('type', 'deposit')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    public function sumExpensesSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('SUM(p.amountCents)')
            ->andWhere('p.type = :type')
            ->andWhere('p.status = :status')
            ->andWhere('p.createdAt >= :since')
            ->setParameter('type', 'expense')
            ->setParameter('status', 'approved')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    public function sumFeesSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('SUM(p.feeCents)')
            ->andWhere('p.createdAt >= :since')
            ->andWhere('p.status = :status')
            ->setParameter('status', 'approved')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }
}
