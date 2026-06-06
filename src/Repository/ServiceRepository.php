<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Service;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Service>
 */
class ServiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Service::class);
    }

    public function findAllGroupedByCategory(): array
    {
        $services = $this->createQueryBuilder('s')
            ->andWhere('s.active = true')
            ->orderBy('s.category', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($services as $service) {
            $grouped[$service->getCategory()][] = $service;
        }

        return $grouped;
    }
}
