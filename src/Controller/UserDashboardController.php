<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class UserDashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(EntityManagerInterface $em): Response
    {
        $user  = $this->getUser();
        $since = new \DateTimeImmutable('-30 days');

        $wallet = $em->getRepository(Wallet::class)->findOneBy(['user' => $user])
            ?? (new Wallet())->setBalanceCents(0);

        $orders = $em->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.service', 's')
            ->where('o.user = :user')
            ->andWhere('o.createdAt >= :since')
            ->setParameter('user', $user)
            ->setParameter('since', $since)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        $ordersCount    = count($orders);
        $completedCount = 0;
        $totalSpent     = 0;

        foreach ($orders as $order) {
            if ($order->getStatus() === Order::STATUS_COMPLETED) {
                $completedCount++;
            }
            $totalSpent += $order->getAmountCents();
        }

        return $this->render('dashboard/index.html.twig', [
            'wallet'          => $wallet,
            'recentOrders'    => $orders,
            'ordersCount'     => $ordersCount,
            'completedCount'  => $completedCount,
            'totalSpentCents' => $totalSpent,
        ]);
    }
}
