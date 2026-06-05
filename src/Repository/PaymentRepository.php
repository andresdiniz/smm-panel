<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Payment;
use App\Enum\PaymentStatus;
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

    /** Pagamentos Pix pendentes criados há mais de $minutes sem confirmação */
    public function findExpiredPix(int $minutes = 30): array
    {
        $threshold = new \DateTimeImmutable("-{$minutes} minutes");

        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->andWhere('p.method = :method')
            ->andWhere('p.createdAt < :threshold')
            ->setParameter('status', PaymentStatus::PENDING)
            ->setParameter('method', 'pix')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getResult();
    }

    /** Relatório de taxas por gateway no período */
    public function feeReportByGateway(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('p')
            ->select(
                'p.gateway',
                'COUNT(p.id) AS total_transactions',
                'SUM(p.amountCents) AS total_amount',
                'SUM(p.feeCents) AS total_fees',
                'SUM(p.netCents) AS total_net'
            )
            ->where('p.status = :status')
            ->andWhere('p.paidAt BETWEEN :from AND :to')
            ->setParameter('status', PaymentStatus::PAID)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('p.gateway')
            ->getQuery()
            ->getResult();
    }
}
