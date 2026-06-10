<?php

namespace App\Repository;

use App\Entity\BlogCategory;
use App\Entity\BlogPost;
use App\Entity\BlogTag;
use App\Enum\BlogPostStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

class BlogPostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BlogPost::class);
    }

    public function findBySlug(string $slug): ?BlogPost
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Pagina posts publicados com join em category e tags.
     */
    public function findPublishedPaginated(int $page = 1, int $limit = 10): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')
            ->leftJoin('p.tags', 't')
            ->addSelect('c', 't')
            ->where('p.status = :status')
            ->setParameter('status', BlogPostStatus::Published)
            ->orderBy('p.publishedAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
    }

    /**
     * Posts publicados de uma categoria.
     */
    public function findByCategory(BlogCategory $category, int $page = 1, int $limit = 10): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')
            ->leftJoin('p.tags', 't')
            ->addSelect('c', 't')
            ->where('p.status = :status')
            ->andWhere('p.category = :category')
            ->setParameter('status', BlogPostStatus::Published)
            ->setParameter('category', $category)
            ->orderBy('p.publishedAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
    }

    /**
     * Posts publicados por tag.
     */
    public function findByTag(BlogTag $tag, int $page = 1, int $limit = 10): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')
            ->leftJoin('p.tags', 't')
            ->addSelect('c', 't')
            ->where('p.status = :status')
            ->andWhere(':tag MEMBER OF p.tags')
            ->setParameter('status', BlogPostStatus::Published)
            ->setParameter('tag', $tag)
            ->orderBy('p.publishedAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
    }

    /** @return BlogPost[] Posts recentes para sidebar */
    public function findRecent(int $limit = 5): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.status = :status')
            ->setParameter('status', BlogPostStatus::Published)
            ->orderBy('p.publishedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function search(string $query, int $page = 1, int $limit = 10): Paginator
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')
            ->addSelect('c')
            ->where('p.status = :status')
            ->andWhere('p.title LIKE :q OR p.excerpt LIKE :q OR p.content LIKE :q')
            ->setParameter('status', BlogPostStatus::Published)
            ->setParameter('q', '%' . $query . '%')
            ->orderBy('p.publishedAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        return new Paginator($qb);
    }
}
