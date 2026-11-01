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
use App\Service\BrowserPushNotificationService;
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
        private readonly BrowserPushNotificationService $browserPush,
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
        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!is_string($token) || !$this->isCsrfTokenValid('admin_support_'.$ticket->getId(), $token)) {
                $this->addFlash('error', 'Sessão expirada. Recarregue a página e tente novamente.');

                return $this->redirectToRoute('admin_support_show', ['id' => $ticket->getId()]);
            }

            $this->messageRepo->markAllReadByAdmin($ticket);
            $action = $request->request->get('action');

            if ($action === 'reply') {
                $body = $request->request->get('body');
                $body = is_string($body) ? trim($body) : '';
                if ($body !== '' && mb_strlen($body) <= 4000 && $ticket->isOpen()) {
                    $message = (new SupportMessage())
                        ->setSender(SupportMessageSender::Admin)
                        ->setBody($body);

                    $ticket->addMessage($message);
                    $ticket->setStatus(SupportTicketStatus::WaitingUser);
                    $this->em->flush();
                    $this->browserPush->notifyUser(
                        $ticket->getUser()->getId(),
                        'support',
                        'Resposta do suporte',
                        sprintf('A equipe respondeu ao ticket #%d.', $ticket->getId()),
                        '/suporte/'.$ticket->getId(),
                    );
                    $this->addFlash('success', 'Resposta enviada.');
                } elseif ($body === '' || mb_strlen($body) > 4000) {
                    $this->addFlash('error', 'A mensagem deve ter entre 1 e 4.000 caracteres.');
                }
            }

            if ($action === 'status') {
                $statusValue = $request->request->get('status');
                $status = is_string($statusValue) ? SupportTicketStatus::tryFrom($statusValue) : null;
                if ($status === null) {
                    $this->addFlash('error', 'Status inválido.');

                    return $this->redirectToRoute('admin_support_show', ['id' => $ticket->getId()]);
                }
                $ticket->setStatus($status);
                $this->em->flush();
                $this->addFlash('success', 'Status atualizado para: ' . $status->label());
            }

            if ($action === 'priority') {
                $priorityValue = $request->request->get('priority');
                $priority = is_string($priorityValue) ? SupportTicketPriority::tryFrom($priorityValue) : null;
                if ($priority === null) {
                    $this->addFlash('error', 'Prioridade inválida.');

                    return $this->redirectToRoute('admin_support_show', ['id' => $ticket->getId()]);
                }
                $ticket->setPriority($priority);
                $this->em->flush();
                $this->addFlash('success', 'Prioridade atualizada para: ' . $priority->label());
            }

            return $this->redirectToRoute('admin_support_show', ['id' => $ticket->getId()]);
        }

        $this->messageRepo->markAllReadByAdmin($ticket);

        return $this->render('admin/support/show.html.twig', [
            'ticket'     => $ticket,
            'priorities' => SupportTicketPriority::cases(),
            'statuses'   => SupportTicketStatus::cases(),
        ]);
    }
}
