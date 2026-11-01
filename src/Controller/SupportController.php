<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SupportMessage;
use App\Entity\Order;
use App\Entity\SupportTicket;
use App\Enum\SupportMessageSender;
use App\Enum\SupportTicketPriority;
use App\Repository\SupportMessageRepository;
use App\Repository\SupportTicketRepository;
use App\Service\BrowserPushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/suporte', name: 'support_')]
#[IsGranted('ROLE_USER')]
class SupportController extends AbstractController
{
    public function __construct(
        private readonly SupportTicketRepository  $ticketRepo,
        private readonly SupportMessageRepository $messageRepo,
        private readonly EntityManagerInterface   $em,
        private readonly BrowserPushNotificationService $browserPush,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tickets = $this->ticketRepo->findByUser($this->getUser());

        return $this->render('support/index.html.twig', [
            'tickets' => $tickets,
        ]);
    }

    #[Route('/novo', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $relatedOrder = $this->findRelatedOrder($request);
        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!is_string($token) || !$this->isCsrfTokenValid('support_new', $token)) {
                $this->addFlash('error', 'Sessão expirada. Recarregue o formulário e tente novamente.');

                return $this->redirectToRoute('support_new', $relatedOrder ? ['order' => $relatedOrder->getId()] : []);
            }

            $subject = $request->request->get('subject');
            $body = $request->request->get('body');
            if (!is_string($subject) || !is_string($body)) {
                $this->addFlash('error', 'Preencha o assunto e a mensagem.');

                return $this->redirectToRoute('support_new', $relatedOrder ? ['order' => $relatedOrder->getId()] : []);
            }
            $subject = trim($subject);
            $body = trim($body);

            if ($subject === '' || mb_strlen($subject) > 180 || $body === '' || mb_strlen($body) > 4000) {
                $this->addFlash('error', 'Preencha o assunto e a mensagem.');

                return $this->redirectToRoute('support_new', $relatedOrder ? ['order' => $relatedOrder->getId()] : []);
            }

            $ticket = (new SupportTicket())
                ->setUser($this->getUser())
                ->setSubject($subject)
                ->setPriority(SupportTicketPriority::Normal);

            $message = (new SupportMessage())
                ->setSender(SupportMessageSender::User)
                ->setBody($body);

            $ticket->addMessage($message);

            $this->em->persist($ticket);
            $this->em->flush();
            $this->browserPush->notifyAdmins(
                'support',
                'Novo ticket de suporte',
                sprintf('Um cliente abriu o ticket #%d.', $ticket->getId()),
                '/admin/suporte/'.$ticket->getId(),
            );

            $this->addFlash('success', 'Ticket #' . $ticket->getId() . ' aberto com sucesso.');
            return $this->redirectToRoute('support_show', ['id' => $ticket->getId()]);
        }

        return $this->render('support/new.html.twig', [
            'relatedOrder' => $relatedOrder,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET', 'POST'])]
    public function show(SupportTicket $ticket, Request $request): Response
    {
        if ($ticket->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!is_string($token) || !$this->isCsrfTokenValid('support_reply_'.$ticket->getId(), $token)) {
                $this->addFlash('error', 'Sessão expirada. Recarregue a página e tente novamente.');

                return $this->redirectToRoute('support_show', ['id' => $ticket->getId()]);
            }

            if (!$ticket->isOpen()) {
                $this->addFlash('error', 'Este ticket está fechado e não aceita novas mensagens.');

                return $this->redirectToRoute('support_show', ['id' => $ticket->getId()]);
            }

            $body = $request->request->get('body');
            $body = is_string($body) ? trim($body) : '';
            if ($body === '' || mb_strlen($body) > 4000) {
                $this->addFlash('error', 'A mensagem deve ter entre 1 e 4.000 caracteres.');

                return $this->redirectToRoute('support_show', ['id' => $ticket->getId()]);
            }

            $this->messageRepo->markAllReadByUser($ticket);
            $message = (new SupportMessage())
                ->setSender(SupportMessageSender::User)
                ->setBody($body);

            $ticket->addMessage($message);
            $this->em->flush();
            $this->browserPush->notifyAdmins(
                'support',
                'Nova mensagem de cliente',
                sprintf('O cliente enviou uma mensagem no ticket #%d.', $ticket->getId()),
                '/admin/suporte/'.$ticket->getId(),
            );

            $this->addFlash('success', 'Mensagem enviada.');
            return $this->redirectToRoute('support_show', ['id' => $ticket->getId()]);
        }

        $this->messageRepo->markAllReadByUser($ticket);

        return $this->render('support/show.html.twig', [
            'ticket' => $ticket,
        ]);
    }

    private function findRelatedOrder(Request $request): ?Order
    {
        $orderId = $request->query->get('order');
        if ($orderId === null || $orderId === '') {
            return null;
        }
        if (!is_string($orderId) || !ctype_digit($orderId)) {
            throw $this->createNotFoundException('Pedido não encontrado.');
        }

        $order = $this->em->getRepository(Order::class)->find((int) $orderId);
        $user = $this->getUser();
        if (!$order || !$user instanceof \App\Entity\User || $order->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Pedido não encontrado.');
        }

        return $order;
    }
}
