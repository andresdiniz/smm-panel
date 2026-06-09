<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SupportMessage;
use App\Entity\SupportTicket;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupportMessage>
 */
class SupportMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupportMessage::class);
    }

    /** Marca todas as mensagens do usuário como lidas pelo admin */
    public function markAllReadByAdmin(SupportTicket $ticket): void
    {
        $this->createQueryBuilder('m')
            ->update()
            ->set('m.readByAdmin', 'true')
            ->where('m.ticket = :ticket')
            ->andWhere('m.sender = :sender')
            ->andWhere('m.readByAdmin = false')
            ->setParameter('ticket', $ticket)
            ->setParameter('sender', 'user')
            ->getQuery()
            ->execute();
    }

    /** Marca todas as mensagens do admin como lidas pelo usuário */
    public function markAllReadByUser(SupportTicket $ticket): void
    {
        $this->createQueryBuilder('m')
            ->update()
            ->set('m.readByUser', 'true')
            ->where('m.ticket = :ticket')
            ->andWhere('m.sender = :sender')
            ->andWhere('m.readByUser = false')
            ->setParameter('ticket', $ticket)
            ->setParameter('sender', 'admin')
            ->getQuery()
            ->execute();
    }
}
