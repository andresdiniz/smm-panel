<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Payment;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/pagamentos', name: 'admin_payment_')]
#[IsGranted('ROLE_ADMIN')]
class AdminPaymentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface     $paginator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $status = $request->query->get('status');
        $search = $request->query->get('q');

        $qb = $this->em->getRepository(Payment::class)
            ->createQueryBuilder('p')
            ->leftJoin('p.user', 'u')->addSelect('u')
            ->orderBy('p.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }
        if ($search) {
            $qb->andWhere('u.email LIKE :q OR u.name LIKE :q OR p.externalId LIKE :q')
               ->setParameter('q', '%'.$search.'%');
        }

        $pagination = $this->paginator->paginate($qb, $request->query->getInt('page', 1), 30);

        return $this->render('admin/payment/index.html.twig', [
            'pagination' => $pagination,
            'status'     => $status,
            'search'     => $search,
            'statuses'   => [
                Payment::STATUS_PENDING, Payment::STATUS_APPROVED,
                Payment::STATUS_FAILED, Payment::STATUS_CANCELLED, Payment::STATUS_REFUNDED,
            ],
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Payment $payment): Response
    {
        return $this->render('admin/payment/show.html.twig', ['payment' => $payment]);
    }
}
