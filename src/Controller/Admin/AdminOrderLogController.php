<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\OrderLog;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/order-logs', name: 'admin_order_log_')]
#[IsGranted('ROLE_ADMIN')]
class AdminOrderLogController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface     $paginator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $qb = $this->em->getRepository(OrderLog::class)
            ->createQueryBuilder('l')
            ->leftJoin('l.order', 'o')->addSelect('o')
            ->orderBy('l.createdAt', 'DESC');
        $pagination = $this->paginator->paginate($qb, $request->query->getInt('page', 1), 50);
        return $this->render('admin/order_log/index.html.twig', ['pagination' => $pagination]);
    }

    #[Route('/errors', name: 'errors', methods: ['GET'])]
    public function errors(Request $request): Response
    {
        $since = new \DateTime('-24 hours');
        $qb = $this->em->getRepository(OrderLog::class)
            ->createQueryBuilder('l')
            ->leftJoin('l.order', 'o')->addSelect('o')
            ->where('l.isError = true')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('l.createdAt', 'DESC');
        $pagination = $this->paginator->paginate($qb, $request->query->getInt('page', 1), 50);
        return $this->render('admin/order_log/errors.html.twig', ['pagination' => $pagination]);
    }
}
