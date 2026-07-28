<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CronLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CronLog>
 */
class CronLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CronLog::class);
    }

    /** Últimos N logs de um comando específico */
    public function findByCommand(string $command, int $limit = 50): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.command = :cmd')
            ->setParameter('cmd', $command)
            ->orderBy('c.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Últimos erros (success=false) nas últimas 24h */
    public function findRecentErrors(int $hours = 24): array
    {
        $since = new \DateTimeImmutable("-{$hours} hours");
        return $this->createQueryBuilder('c')
            ->where('c.success = false')
            ->andWhere('c.startedAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('c.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Resumo por comando: total, erros, última execução */
    public function getSummary(): array
    {
        return $this->createQueryBuilder('c')
            ->select(
                'c.command',
                'COUNT(c.id) AS total',
                'SUM(CASE WHEN c.success = false THEN 1 ELSE 0 END) AS errors',
                'MAX(c.startedAt) AS lastRun',
                'AVG(c.durationMs) AS avgMs'
            )
            ->groupBy('c.command')
            ->orderBy('lastRun', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
