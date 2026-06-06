<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\WalletTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/transactions', name: 'app_transaction')]
class TransactionController extends AbstractController
{
    #[Route('', name: '_index', methods: ['GET'])]
    public function index(EntityManagerInterface $em): Response
    {
        $user   = $this->getUser();
        $wallet = $user->getWallet();

        $transactions = [];
        if ($wallet) {
            $transactions = $em->getRepository(WalletTransaction::class)
                ->findBy(['wallet' => $wallet], ['createdAt' => 'DESC']);
        }

        return $this->render('transaction/index.html.twig', [
            'transactions' => $transactions,
            'wallet'       => $wallet,
        ]);
    }
}
