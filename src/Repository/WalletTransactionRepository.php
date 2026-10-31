<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WalletTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WalletTransaction>
 */
class WalletTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WalletTransaction::class);
    }

    /**
     * Busca transações de uma wallet ordenadas por data desc.
     *
     * @return WalletTransaction[]
     */
    public function findByWalletDesc(int $walletId): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.wallet = :wid')
            ->setParameter('wid', $walletId)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Soma todos os créditos (type = 'credit') do mês corrente em centavos.
     */
    public function sumCreditThisMonth(): int
    {
        $start = new \DateTimeImmutable('first day of this month midnight');
        $end   = new \DateTimeImmutable('first day of next month midnight');

        return (int) $this->createQueryBuilder('t')
            ->select('SUM(t.amountCents)')
            ->andWhere('t.type = :type')
            ->andWhere('t.createdAt >= :start')
            ->andWhere('t.createdAt < :end')
            ->setParameter('type', 'credit')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Soma créditos de hoje em centavos.
     */
    public function sumCreditToday(): int
    {
        $start = new \DateTimeImmutable('today midnight');
        $end   = new \DateTimeImmutable('tomorrow midnight');

        return (int) $this->createQueryBuilder('t')
            ->select('SUM(t.amountCents)')
            ->andWhere('t.type = :type')
            ->andWhere('t.createdAt >= :start')
            ->andWhere('t.createdAt < :end')
            ->setParameter('type', 'credit')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Soma débitos (type = 'debit') do mês corrente — custo dos providers.
     */
    public function sumDebitThisMonth(): int
    {
        $start = new \DateTimeImmutable('first day of this month midnight');
        $end   = new \DateTimeImmutable('first day of next month midnight');

        return (int) $this->createQueryBuilder('t')
            ->select('SUM(t.amountCents)')
            ->andWhere('t.type = :type')
            ->andWhere('t.createdAt >= :start')
            ->andWhere('t.createdAt < :end')
            ->setParameter('type', 'debit')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Soma taxas (type = 'fee') do mês corrente — taxas de gateway.
     */
    public function sumFeesThisMonth(): int
    {
        $start = new \DateTimeImmutable('first day of this month midnight');
        $end   = new \DateTimeImmutable('first day of next month midnight');

        return (int) $this->createQueryBuilder('t')
            ->select('SUM(t.amountCents)')
            ->andWhere('t.type = :type')
            ->andWhere('t.createdAt >= :start')
            ->andWhere('t.createdAt < :end')
            ->setParameter('type', 'fee')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }
}
