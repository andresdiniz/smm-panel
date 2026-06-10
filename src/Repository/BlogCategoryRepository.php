<?php

namespace App\Repository;

use App\Entity\BlogCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BlogCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogCategory::class);
    }

    public function findBySlug(string $slug): ?BlogCategory
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return BlogCategory[] */
    public function findAllWithPostCount(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.posts', 'p')
            ->addSelect('COUNT(p.id) AS HIDDEN postCount')
            ->groupBy('c.id')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
