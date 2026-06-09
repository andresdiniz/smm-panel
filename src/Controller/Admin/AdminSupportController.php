<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SupportMessage;
use App\Entity\SupportTicket;
use App\Enum\SupportMessageSender;
use App\Enum\SupportTicketPriority;
use App\Enum\SupportTicketStatus;
use App\Repository\SupportMessageRepository;
use App\Repository\SupportTicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/suporte', name: 'admin_support_')]
#[IsGranted('ROLE_ADMIN')]
class AdminSupportController extends AbstractController
{
    public function __construct(
        private readonly SupportTicketRepository  $ticketRepo,
        private readonly SupportMessageRepository $messageRepo,
        private readonly EntityManagerInterface   $em,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/support/index.html.twig', [
            'tickets'      => $this->ticketRepo->findOpen(),
            'unreadCount'  => $this->ticketRepo->countUnreadForAdmin(),
            'priorities'   => SupportTicketPriority::cases(),
            'statuses'     => SupportTicketStatus::cases(),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET', 'POST'])]
    public function show(SupportTicket $ticket, Request $request): Response
    {
        // Marca mensagens do usuário como lidas pelo admin
        $this->messageRepo->markAllReadByAdmin($ticket);

        if ($request->isMethod('POST')) {
            $action = $request->request->get('action');

            if ($action === 'reply') {
                $body = trim((string) $request->request->get('body', ''));
                if ($body !== '' && $ticket->isOpen()) {
                    $message = (new SupportMessage())
                        ->setSender(SupportMessageSender::Admin)
                        ->setBody($body);

                    $ticket->addMessage($message);
                    $ticket->setStatus(SupportTicketStatus::WaitingUser);
                    $this->em->flush();
                    $this->addFlash('success', 'Resposta enviada.');
                }
            }

            if ($action === 'status') {
                $status = SupportTicketStatus::from($request->request->get('status'));
                $ticket->setStatus($status);
                $this->em->flush();
                $this->addFlash('success', 'Status atualizado para: ' . $status->label());
            }

            if ($action === 'priority') {
                $priority = SupportTicketPriority::from($request->request->get('priority'));
                $ticket->setPriority($priority);
                $this->em->flush();
                $this->addFlash('success', 'Prioridade atualizada para: ' . $priority->label());
            }

            return $this->redirectToRoute('admin_support_show', ['id' => $ticket->getId()]);
        }

        return $this->render('admin/support/show.html.twig', [
            'ticket'     => $ticket,
            'priorities' => SupportTicketPriority::cases(),
            'statuses'   => SupportTicketStatus::cases(),
        ]);
    }
}
