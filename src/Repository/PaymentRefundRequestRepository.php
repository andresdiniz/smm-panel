<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Payment;
use App\Entity\PaymentRefundRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentRefundRequest>
 */
class PaymentRefundRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentRefundRequest::class);
    }

    public function findOneByPayment(Payment $payment): ?PaymentRefundRequest
    {
        return $this->findOneBy(['payment' => $payment]);
    }

    /**
     * @return PaymentRefundRequest[]
     */
    public function findLatest(int $limit = 100): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('p', 'u')
            ->join('r.payment', 'p')
            ->join('r.user', 'u')
            ->orderBy('r.requestedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return PaymentRefundRequest[]
     */
    public function findRecentByUser(User $user, int $limit = 20): array
    {
        return $this->findBy(['user' => $user], ['requestedAt' => 'DESC'], $limit);
    }
}
