<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BrowserPushSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrowserPushSubscription>
 */
class BrowserPushSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrowserPushSubscription::class);
    }

    /**
     * @return list<BrowserPushSubscription>
     */
    public function findForAudience(string $audience, ?int $userId, string $category): array
    {
        $conditions = [
            'u.active = 1',
            'JSON_CONTAINS(s.categories, :category) = 1',
        ];
        $parameters = ['category' => json_encode($category, JSON_THROW_ON_ERROR)];
        $types = ['category' => ParameterType::STRING];

        if ($audience === 'user') {
            $conditions[] = 'u.id = :user_id';
            $parameters['user_id'] = $userId;
            $types['user_id'] = ParameterType::INTEGER;
        } elseif ($audience === 'admins') {
            $conditions[] = 'JSON_CONTAINS(u.roles, :admin_role) = 1';
            $parameters['admin_role'] = json_encode('ROLE_ADMIN', JSON_THROW_ON_ERROR);
            $types['admin_role'] = ParameterType::STRING;
        } elseif ($audience !== 'marketing') {
            throw new \InvalidArgumentException('Unsupported browser push audience.');
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            sprintf(
                'SELECT s.id FROM browser_push_subscriptions s INNER JOIN users u ON u.id = s.user_id WHERE %s',
                implode(' AND ', $conditions),
            ),
            $parameters,
            $types,
        )->fetchFirstColumn();

        if ($rows === []) {
            return [];
        }

        return $this->findBy(['id' => array_map('intval', $rows)]);
    }

    public function findOneByEndpointHash(string $endpointHash): ?BrowserPushSubscription
    {
        return $this->findOneBy(['endpointHash' => $endpointHash]);
    }
}
