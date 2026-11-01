<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\WalletRepository;
use App\Repository\WalletTransactionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/transactions', name: 'app_transaction')]
class TransactionController extends AbstractController
{
    private const PAGE_SIZE = 50;

    public function __construct(
        private readonly WalletTransactionRepository $walletTxRepo,
        private readonly WalletRepository $walletRepo,
    ) {}

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $userId = $user->getId();
        if ($userId === null) {
            throw new \LogicException('A conta autenticada não possui um identificador persistido.');
        }

        $page = max(1, min(100_000, $request->query->getInt('page', 1)));
        $rows = $this->walletTxRepo->findUnifiedFeed(
            $userId,
            ($page - 1) * self::PAGE_SIZE,
            self::PAGE_SIZE + 1,
        );
        $hasNextPage = count($rows) > self::PAGE_SIZE;

        return $this->render('transaction/index.html.twig', [
            'feed' => array_slice($rows, 0, self::PAGE_SIZE),
            'wallet' => $this->walletRepo->findOneByUser($user),
            'page' => $page,
            'hasNextPage' => $hasNextPage,
        ]);
    }
}
