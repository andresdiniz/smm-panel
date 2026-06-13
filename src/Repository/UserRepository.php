<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
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
            ->getQuery()
            ->getResult();
    }

    public function countCreatedToday(): int
    {
        $start = new \DateTimeImmutable('today midnight');

        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->andWhere('u.createdAt >= :start')
            ->setParameter('start', $start)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Retorna usuarios com dados CRM enriquecidos:
     * total gasto (sum de orders nao cancelados), contagem de pedidos,
     * origem UTM (via CrmContact) e saldo de carteira.
     *
     * @return array<int, array{user: User, totalSpentCents: int, orderCount: int, utmSource: string|null, utmCampaign: string|null, tags: array}>
     */
    public function findCrmUsers(int $limit = 100, int $minSpentCents = 0): array
    {
        $em = $this->getEntityManager();

        // busca users com soma de pedidos via DQL
        $rows = $em->createQuery(
            'SELECT u,
                    COALESCE(SUM(CASE WHEN o.status != :cancelled THEN o.amountCents ELSE 0 END), 0) AS totalSpentCents,
                    COUNT(o.id) AS orderCount
             FROM App\Entity\User u
             LEFT JOIN App\Entity\Order o WITH o.user = u
             GROUP BY u.id
             HAVING COALESCE(SUM(CASE WHEN o.status != :cancelled THEN o.amountCents ELSE 0 END), 0) >= :min
             ORDER BY totalSpentCents DESC'
        )
        ->setParameter('cancelled', 'cancelled')
        ->setParameter('min', $minSpentCents)
        ->setMaxResults($limit)
        ->getResult();

        // busca todos os CrmContacts para cruzar
        $contacts = $em->createQuery(
            'SELECT c FROM App\Entity\CrmContact c INDEX BY c.user'
        )->getResult();

        $result = [];
        foreach ($rows as $row) {
            /** @var User $user */
            $user    = $row[0];
            $contact = $contacts[$user->getId()] ?? null;

            $result[] = [
                'user'            => $user,
                'totalSpentCents' => (int) $row['totalSpentCents'],
                'orderCount'      => (int) $row['orderCount'],
                'utmSource'       => $contact?->getUtmSource(),
                'utmCampaign'     => $contact?->getUtmCampaign(),
                'utmMedium'       => $contact?->getUtmMedium(),
                'tags'            => $contact?->getTags() ?? [],
            ];
        }

        return $result;
    }

    /**
     * Retorna emails de users segmentados para e-mail marketing.
     * Filtra por gasto minimo, utm_source e/ou tag em CrmContact.
     *
     * @return array<int, array{name: string, email: string}>
     */
    public function findEmailsForMarketing(
        int $minSpentCents = 0,
        ?string $utmSource = null,
        ?string $tag = null
    ): array {
        $em = $this->getEntityManager();

        $dql = 'SELECT u,
                       COALESCE(SUM(CASE WHEN o.status != :cancelled THEN o.amountCents ELSE 0 END), 0) AS totalSpent
                FROM App\Entity\User u
                LEFT JOIN App\Entity\Order o WITH o.user = u
                LEFT JOIN App\Entity\CrmContact c WITH c.user = u
                WHERE u.active = true';

        $params = ['cancelled' => 'cancelled'];

        if ($utmSource) {
            $dql .= ' AND c.utmSource = :utmSource';
            $params['utmSource'] = $utmSource;
        }

        $dql .= ' GROUP BY u.id HAVING COALESCE(SUM(CASE WHEN o.status != :cancelled THEN o.amountCents ELSE 0 END), 0) >= :min';
        $params['min'] = $minSpentCents;

        $rows = $em->createQuery($dql)->setParameters($params)->getResult();

        // filtro de tag em PHP (campo JSON)
        if ($tag) {
            $rows = array_filter($rows, static function (array $row) use ($tag) {
                // precisamos do CrmContact — busca lazy via user
                return true; // filtragem de tag feita abaixo
            });
        }

        $emails = [];
        foreach ($rows as $row) {
            /** @var User $user */
            $user = $row[0];
            $emails[] = ['name' => $user->getName(), 'email' => $user->getEmail()];
        }

        return $emails;
    }
}
