<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/pedidos', name: 'admin_order_')]
#[IsGranted('ROLE_ADMIN')]
class AdminOrderController extends AbstractController
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

        $qb = $this->em->getRepository(Order::class)
            ->createQueryBuilder('o')
            ->leftJoin('o.user', 'u')
            ->leftJoin('o.service', 's')
            ->addSelect('u', 's')
            ->orderBy('o.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }
        if ($search) {
            $qb->andWhere('o.id = :id OR u.email LIKE :q OR u.name LIKE :q OR o.externalOrderId LIKE :q')
               ->setParameter('id', is_numeric($search) ? (int)$search : -1)
               ->setParameter('q', '%'.$search.'%');
        }

        $pagination = $this->paginator->paginate($qb, $request->query->getInt('page', 1), 30);

        return $this->render('admin/order/index.html.twig', [
            'pagination' => $pagination,
            'status'     => $status,
            'search'     => $search,
            'statuses'   => [
                Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_IN_PROGRESS,
                Order::STATUS_COMPLETED, Order::STATUS_PARTIAL, Order::STATUS_CANCELLED, Order::STATUS_REFUNDED,
            ],
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Order $order): Response
    {
        return $this->render('admin/order/show.html.twig', ['order' => $order]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Order $order, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $order->setStatus($request->request->get('status', $order->getStatus()));
            $extId = $request->request->get('externalOrderId');
            if ($extId !== null) $order->setExternalOrderId($extId ?: null);
            $remains = $request->request->get('remains');
            if ($remains !== null && $remains !== '') $order->setRemains((int)$remains);
            $this->em->flush();
            $this->addFlash('success', 'Pedido #'.$order->getId().' atualizado.');
            return $this->redirectToRoute('admin_order_show', ['id' => $order->getId()]);
        }
        return $this->render('admin/order/edit.html.twig', [
            'order'    => $order,
            'statuses' => [
                Order::STATUS_PENDING, Order::STATUS_PROCESSING, Order::STATUS_IN_PROGRESS,
                Order::STATUS_COMPLETED, Order::STATUS_PARTIAL, Order::STATUS_CANCELLED, Order::STATUS_REFUNDED,
            ],
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Order $order, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete_order_'.$order->getId(), $request->request->get('_token'))) {
            $this->em->remove($order);
            $this->em->flush();
            $this->addFlash('success', 'Pedido removido.');
        }
        return $this->redirectToRoute('admin_order_index');
    }
}
