<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\WalletTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/transacoes', name: 'admin_wallet_transaction_')]
#[IsGranted('ROLE_ADMIN')]
class AdminWalletTransactionController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface     $paginator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $qb = $this->em->getRepository(WalletTransaction::class)
            ->createQueryBuilder('t')
            ->leftJoin('t.wallet', 'w')->addSelect('w')
            ->orderBy('t.createdAt', 'DESC');
        $pagination = $this->paginator->paginate($qb, $request->query->getInt('page', 1), 50);
        return $this->render('admin/wallet_transaction/index.html.twig', ['pagination' => $pagination]);
    }
}
