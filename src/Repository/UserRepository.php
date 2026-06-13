<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();
    }

    public function countCreatedToday(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.createdAt >= :start')
            ->setParameter('start', new \DateTimeImmutable('today midnight'))
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Todos os usuarios com dados CRM enriquecidos (gasto total + pedidos + UTM).
     * @return array<int, array{user:User, totalSpentCents:int, orderCount:int, utmSource:string|null, utmCampaign:string|null, utmMedium:string|null, tags:array}>
     */
    public function findCrmUsers(int $limit = 500): array
    {
        $em = $this->getEntityManager();

        $rows = $em->createQuery(
            'SELECT u,
                    COALESCE(SUM(CASE WHEN o.status != :cancelled THEN o.amountCents ELSE 0 END), 0) AS totalSpentCents,
                    COUNT(o.id) AS orderCount
             FROM App\Entity\User u
             LEFT JOIN App\Entity\Order o WITH o.user = u
             GROUP BY u.id
             ORDER BY totalSpentCents DESC'
        )
        ->setParameter('cancelled', 'cancelled')
        ->setMaxResults($limit)
        ->getResult();

        $contacts = $em->createQuery('SELECT c FROM App\Entity\CrmContact c INDEX BY c.user')
            ->getResult();

        $result = [];
        foreach ($rows as $row) {
            $user    = $row[0];
            $contact = $contacts[$user->getId()] ?? null;
            $result[] = [
                'user'            => $user,
                'totalSpentCents' => (int)$row['totalSpentCents'],
                'orderCount'      => (int)$row['orderCount'],
                'utmSource'       => $contact?->getUtmSource(),
                'utmCampaign'     => $contact?->getUtmCampaign(),
                'utmMedium'       => $contact?->getUtmMedium(),
                'tags'            => $contact?->getTags() ?? [],
            ];
        }
        return $result;
    }
}
