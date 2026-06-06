<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Message\ProcessOrderMessage;
use App\Repository\ServiceRepository;
use App\Repository\WalletRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/order', name: 'app_order_')]
class OrderController extends AbstractController
{
    public function __construct(
        private readonly ServiceRepository    $serviceRepository,
        private readonly WalletRepository     $walletRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface  $bus,
    ) {}

    /**
     * Exibe o formulário de novo pedido.
     */
    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('order/new.html.twig', [
            'servicesGrouped' => $this->serviceRepository->findAllGroupedByCategory(),
        ]);
    }

    /**
     * Processa o envio do pedido.
     * Valida CSRF, saldo, cria Order, debita Wallet e despacha mensagem assíncrona.
     */
    #[Route('/new', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('order_create', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido. Recarregue a página.');
            return $this->redirectToRoute('app_order_new');
        }

        $user      = $this->getUser();
        $serviceId = (int) $request->request->get('service_id');
        $quantity  = (int) $request->request->get('quantity');
        $targetUrl = trim($request->request->get('target_url', ''));

        // Validações básicas
        if (!$targetUrl || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            $this->addFlash('error', 'URL alvo inválida.');
            return $this->redirectToRoute('app_order_new');
        }

        $service = $this->serviceRepository->find($serviceId);
        if (!$service || !$service->isActive()) {
            $this->addFlash('error', 'Serviço não encontrado ou inativo.');
            return $this->redirectToRoute('app_order_new');
        }

        if ($quantity < $service->getMinQty() || $quantity > $service->getMaxQty()) {
            $this->addFlash('error', sprintf(
                'Quantidade inválida. Mínimo: %d | Máximo: %d.',
                $service->getMinQty(),
                $service->getMaxQty()
            ));
            return $this->redirectToRoute('app_order_new');
        }

        $amountCents = $service->calculatePriceCents($quantity);
        $wallet      = $this->walletRepository->findOneByUser($user);

        if (!$wallet || $wallet->getBalanceCents() < $amountCents) {
            $this->addFlash('error', 'Saldo insuficiente. Recarregue sua carteira.');
            return $this->redirectToRoute('app_order_new');
        }

        // Debita saldo e cria pedido
        $wallet->debit($amountCents);

        $order = new Order();
        $order->setUser($user);
        $order->setService($service);
        $order->setQuantity($quantity);
        $order->setTargetUrl($targetUrl);
        $order->setAmountCents($amountCents);
        $order->setStatus(Order::STATUS_PENDING);

        $this->em->persist($order);
        $this->em->persist($wallet);
        $this->em->flush();

        // Envia para fila assíncrona
        $this->bus->dispatch(new ProcessOrderMessage($order->getId()));

        $this->addFlash('success', sprintf(
            'Pedido #%d criado com sucesso! Acompanhe o status no painel.',
            $order->getId()
        ));

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * Detalhe de um pedido do usuário autenticado.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $order = $this->em->find(Order::class, $id);

        if (!$order || $order->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Pedido não encontrado.');
        }

        return $this->render('order/show.html.twig', ['order' => $order]);
    }
}
