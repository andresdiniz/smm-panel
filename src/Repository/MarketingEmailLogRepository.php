<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MarketingEmailLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketingEmailLog>
 */
class MarketingEmailLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketingEmailLog::class);
    }

    public function findOneByToken(string $token): ?MarketingEmailLog
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * @return list<array{campaignId: string, subject: string, sentAt: \DateTimeImmutable, recipients: int, opened: int, clicked: int, clicks: int}>
     */
    public function findRecentCampaignSummaries(int $limit = 20): array
    {
        return $this->createQueryBuilder('m')
            ->select(
                'm.campaignId AS campaignId',
                'm.subject AS subject',
                'MAX(m.sentAt) AS sentAt',
                'COUNT(m.id) AS recipients',
                'SUM(CASE WHEN m.openedAt IS NOT NULL THEN 1 ELSE 0 END) AS opened',
                'SUM(CASE WHEN m.clickCount > 0 THEN 1 ELSE 0 END) AS clicked',
                'SUM(m.clickCount) AS clicks',
            )
            ->where('m.status = :sent')
            ->groupBy('m.campaignId, m.subject')
            ->orderBy('sentAt', 'DESC')
            ->setParameter('sent', MarketingEmailLog::STATUS_SENT)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<MarketingEmailLog>
     */
    public function findByCampaignId(string $campaignId): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->innerJoin('m.user', 'u')
            ->where('m.campaignId = :campaignId')
            ->orderBy('m.createdAt', 'DESC')
            ->setParameter('campaignId', $campaignId)
            ->getQuery()
            ->getResult();
    }
}
