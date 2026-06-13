<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AffiliateCommission;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AffiliateCommission>
 */
class AffiliateCommissionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AffiliateCommission::class);
    }

    /** Comissões de um afiliado, mais recentes primeiro */
    public function findByAffiliate(User $affiliate, int $limit = 50): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.affiliate = :aff')
            ->setParameter('aff', $affiliate)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Total de comissões por status para um afiliado */
    public function sumByStatus(User $affiliate, string $status): float
    {
        $result = $this->createQueryBuilder('c')
            ->select('SUM(c.amount) as total')
            ->andWhere('c.affiliate = :aff')
            ->andWhere('c.status = :status')
            ->setParameter('aff', $affiliate)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /** Estatísticas globais para o admin */
    public function globalStats(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c.status, SUM(c.amount) as total, COUNT(c.id) as qty')
            ->groupBy('c.status')
            ->getQuery()
            ->getResult();

        $stats = ['pending' => ['total' => 0.0, 'qty' => 0], 'paid' => ['total' => 0.0, 'qty' => 0], 'cancelled' => ['total' => 0.0, 'qty' => 0]];
        foreach ($rows as $r) {
            $stats[$r['status']] = ['total' => (float) $r['total'], 'qty' => (int) $r['qty']];
        }
        return $stats;
    }

    /** Top afiliados por comissão total paga */
    public function topAffiliates(int $limit = 10): array
    {
        return $this->createQueryBuilder('c')
            ->select('IDENTITY(c.affiliate) as affiliateId, SUM(c.amount) as total, COUNT(c.id) as qty')
            ->andWhere("c.status = 'paid'")
            ->groupBy('c.affiliate')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
