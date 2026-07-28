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

    /**
     * Retorna os pagamentos mais recentes de um usuário, ordenados por data desc.
     *
     * @return Payment[]
     */
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

    /**
     * Retorna o último depósito (type = 'deposit') de um usuário, ou null.
     */
    public function findLastDepositByUser(User $user): ?Payment
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.user = :user')
            ->andWhere('p.type = :type')
            ->setParameter('user', $user)
            ->setParameter('type', Payment::TYPE_DEPOSIT)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
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
