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

    /**
     * @return list<Payment>
     */
    public function findPendingForReconciliation(
        \DateTimeImmutable $eligibleBefore,
        \DateTimeImmutable $retryBefore,
        int $limit = 50,
    ): array {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.type = :type')
            ->andWhere('p.createdAt <= :eligible_before')
            ->andWhere('p.externalId IS NOT NULL')
            ->andWhere('(p.lastReconciledAt IS NULL OR p.lastReconciledAt <= :retry_before)')
            ->setParameter('status', Payment::STATUS_PENDING)
            ->setParameter('type', Payment::TYPE_DEPOSIT)
            ->setParameter('eligible_before', $eligibleBefore)
            ->setParameter('retry_before', $retryBefore)
            ->orderBy('p.lastReconciledAt', 'ASC')
            ->addOrderBy('p.createdAt', 'ASC')
            ->setMaxResults(max(1, min($limit, 100)))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Payment>
     */
    public function findExpiredPix(int $minutes = 30, int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.type = :type')
            ->andWhere('p.method = :method')
            ->andWhere('p.status = :status')
            ->andWhere('p.externalId IS NOT NULL')
            ->andWhere('p.createdAt <= :cutoff')
            ->setParameter('type', Payment::TYPE_DEPOSIT)
            ->setParameter('method', Payment::METHOD_PIX)
            ->setParameter('status', Payment::STATUS_PENDING)
            ->setParameter('cutoff', new \DateTimeImmutable(sprintf('-%d minutes', max(1, $minutes))))
            ->orderBy('p.createdAt', 'ASC')
            ->setMaxResults(max(1, min($limit, 100)))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Payment>
     */
    public function findPendingOlderThan(\DateTimeImmutable $cutoff, int $limit = 20): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.type = :type')
            ->andWhere('p.status = :status')
            ->andWhere('p.externalId IS NOT NULL')
            ->andWhere('p.createdAt <= :cutoff')
            ->setParameter('type', Payment::TYPE_DEPOSIT)
            ->setParameter('status', Payment::STATUS_PENDING)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('p.createdAt', 'ASC')
            ->setMaxResults(max(1, min($limit, 50)))
            ->getQuery()
            ->getResult();
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
