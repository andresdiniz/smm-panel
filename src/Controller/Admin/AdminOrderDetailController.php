<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Repository\OrderLogRepository;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/orders', name: 'admin_order_')]
class AdminOrderDetailController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository    $orderRepo,
        private readonly OrderLogRepository $logRepo,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Página de detalhe do pedido com todos os logs de provider inline.
     */
    #[Route('/{id}', name: 'detail', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        $order = $this->orderRepo->find($id);

        if (!$order) {
            throw $this->createNotFoundException("Pedido #$id não encontrado.");
        }

        $logs = $this->logRepo->findByOrder($id);

        return $this->render('admin/order_detail.html.twig', [
            'order' => $order,
            'logs'  => $logs,
        ]);
    }

    /**
     * Listagem de pedidos com painel de erros recentes de provider.
     */
    #[Route('/logs/errors', name: 'logs_errors', methods: ['GET'])]
    public function recentErrors(): Response
    {
        $logs = $this->logRepo->findRecentErrors(24);

        return $this->render('admin/order_logs_errors.html.twig', [
            'logs' => $logs,
        ]);
    }
}
