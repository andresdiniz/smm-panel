<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WalletTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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
     * @return array{credit_month: int, credit_today: int, debit_month: int, fees_month: int}
     */
    public function getDashboardSummary(): array
    {
        $monthStart = new \DateTimeImmutable('first day of this month midnight');
        $monthEnd = new \DateTimeImmutable('first day of next month midnight');
        $todayStart = new \DateTimeImmutable('today midnight');
        $tomorrowStart = new \DateTimeImmutable('tomorrow midnight');

        $summary = $this->createQueryBuilder('t')
            ->select(
                'COALESCE(SUM(CASE WHEN t.type = :credit AND t.createdAt >= :monthStart AND t.createdAt < :monthEnd THEN t.amountCents ELSE 0 END), 0) AS creditMonth',
                'COALESCE(SUM(CASE WHEN t.type = :credit AND t.createdAt >= :todayStart AND t.createdAt < :tomorrowStart THEN t.amountCents ELSE 0 END), 0) AS creditToday',
                'COALESCE(SUM(CASE WHEN t.type = :debit AND t.createdAt >= :monthStart AND t.createdAt < :monthEnd THEN t.amountCents ELSE 0 END), 0) AS debitMonth',
                'COALESCE(SUM(CASE WHEN t.type = :fee AND t.createdAt >= :monthStart AND t.createdAt < :monthEnd THEN t.amountCents ELSE 0 END), 0) AS feesMonth',
            )
            ->where('t.createdAt >= :monthStart')
            ->setParameter('credit', 'credit')
            ->setParameter('debit', 'debit')
            ->setParameter('fee', 'fee')
            ->setParameter('monthStart', $monthStart)
            ->setParameter('monthEnd', $monthEnd)
            ->setParameter('todayStart', $todayStart)
            ->setParameter('tomorrowStart', $tomorrowStart)
            ->getQuery()
            ->getSingleResult();

        return [
            'credit_month' => (int) $summary['creditMonth'],
            'credit_today' => (int) $summary['creditToday'],
            'debit_month' => (int) $summary['debitMonth'],
            'fees_month' => (int) $summary['feesMonth'],
        ];
    }

    /**
     * Returns a bounded, unified page of wallet, payment, and order activity.
     *
     * @return list<array{date: string, type: string, subtype: string, description: string, amountCents: int, balanceCents: int|null, method: string|null, sourceId: int}>
     */
    public function findUnifiedFeed(int $userId, int $offset, int $limit): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT activity.*
                FROM (
                    (
                    SELECT
                        wt.created_at AS activity_date,
                        'wallet' AS activity_type,
                        wt.type AS activity_subtype,
                        wt.description AS activity_description,
                        wt.amount_cents AS amount_cents,
                        wt.balance_after_cents AS balance_cents,
                        NULL AS payment_method,
                        wt.id AS source_id
                    FROM wallet_transactions wt
                    INNER JOIN wallets w ON w.id = wt.wallet_id
                    WHERE w.user_id = :wallet_user_id
                    ORDER BY wt.created_at DESC, wt.id DESC
                    LIMIT :wallet_candidate_limit
                    )

                    UNION ALL

                    (
                    SELECT
                        p.created_at AS activity_date,
                        'payment' AS activity_type,
                        p.status AS activity_subtype,
                        CASE p.status
                            WHEN 'approved' THEN CONCAT('Depósito aprovado via ', UPPER(p.method))
                            WHEN 'pending' THEN CONCAT('Depósito pendente via ', UPPER(p.method), ' — aguardando pagamento')
                            WHEN 'failed' THEN CONCAT('Depósito falhou via ', UPPER(p.method))
                            WHEN 'cancelled' THEN CONCAT('Depósito cancelado via ', UPPER(p.method))
                            WHEN 'refunded' THEN CONCAT('Depósito estornado via ', UPPER(p.method), ' (webhook)')
                            ELSE 'Pagamento'
                        END AS activity_description,
                        p.amount_cents AS amount_cents,
                        NULL AS balance_cents,
                        p.method AS payment_method,
                        p.id AS source_id
                    FROM payments p
                    WHERE p.user_id = :payment_user_id
                    ORDER BY p.created_at DESC, p.id DESC
                    LIMIT :payment_candidate_limit
                    )

                    UNION ALL

                    (
                    SELECT
                        o.created_at AS activity_date,
                        'order' AS activity_type,
                        o.status AS activity_subtype,
                        CONCAT(
                            'Pedido #', o.id, ' — ', s.name, ' (',
                            CASE o.status
                                WHEN 'completed' THEN 'Concluído'
                                WHEN 'partial' THEN 'Parcial'
                                WHEN 'cancelled' THEN 'Cancelado — saldo estornado'
                                WHEN 'refunded' THEN 'Estornado automaticamente'
                                WHEN 'pending' THEN 'Pendente'
                                WHEN 'processing' THEN 'Processando'
                                WHEN 'in_progress' THEN 'Em andamento'
                                ELSE o.status
                            END,
                            ')'
                        ) AS activity_description,
                        o.amount_cents AS amount_cents,
                        NULL AS balance_cents,
                        NULL AS payment_method,
                        o.id AS source_id
                    FROM orders o
                    INNER JOIN services s ON s.id = o.service_id
                    WHERE o.user_id = :order_user_id
                    ORDER BY o.created_at DESC, o.id DESC
                    LIMIT :order_candidate_limit
                    )
                ) activity
                ORDER BY activity_date DESC, source_id DESC, activity_type ASC
                LIMIT :limit OFFSET :offset
                SQL,
            [
                'wallet_user_id' => $userId,
                'payment_user_id' => $userId,
                'order_user_id' => $userId,
                'wallet_candidate_limit' => $offset + $limit,
                'payment_candidate_limit' => $offset + $limit,
                'order_candidate_limit' => $offset + $limit,
                'limit' => $limit,
                'offset' => $offset,
            ],
            [
                'wallet_user_id' => ParameterType::INTEGER,
                'payment_user_id' => ParameterType::INTEGER,
                'order_user_id' => ParameterType::INTEGER,
                'wallet_candidate_limit' => ParameterType::INTEGER,
                'payment_candidate_limit' => ParameterType::INTEGER,
                'order_candidate_limit' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'date' => $row['activity_date'],
            'type' => $row['activity_type'],
            'subtype' => $row['activity_subtype'],
            'description' => $row['activity_description'],
            'amountCents' => (int) $row['amount_cents'],
            'balanceCents' => $row['balance_cents'] !== null ? (int) $row['balance_cents'] : null,
            'method' => $row['payment_method'],
            'sourceId' => (int) $row['source_id'],
        ], $rows);
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
