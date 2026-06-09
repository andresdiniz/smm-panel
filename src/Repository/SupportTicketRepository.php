<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SupportTicket;
use App\Entity\User;
use App\Enum\SupportTicketStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupportTicket>
 */
class SupportTicketRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupportTicket::class);
    }

    /** Tickets de um usuário ordenados do mais recente */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.updatedAt', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /** Todos os tickets abertos (para o painel admin) */
    public function findOpen(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status != :closed')
            ->setParameter('closed', SupportTicketStatus::Closed)
            ->orderBy('t.priority', 'DESC')
            ->addOrderBy('t.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Tickets com mensagens não lidas pelo admin */
    public function findWithUnreadForAdmin(): array
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.messages', 'm')
            ->where('m.sender = :sender')
            ->andWhere('m.readByAdmin = false')
            ->setParameter('sender', 'user')
            ->orderBy('t.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Contagem de tickets não lidos para badge no menu admin */
    public function countUnreadForAdmin(): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->innerJoin('t.messages', 'm')
            ->where('m.sender = :sender')
            ->andWhere('m.readByAdmin = false')
            ->setParameter('sender', 'user')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
