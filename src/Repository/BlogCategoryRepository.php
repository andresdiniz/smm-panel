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

    /** @return list<BlogCategory> */
    public function findAllWithPostCount(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->leftJoin('c.posts', 'p')
            ->addSelect('COUNT(p.id) AS postCount')
            ->groupBy('c.id')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        $categories = [];
        foreach ($rows as $row) {
            /** @var BlogCategory $category */
            $category = $row[0];
            $category->setPostCount((int) $row['postCount']);
            $categories[] = $category;
        }

        return $categories;
    }
}
