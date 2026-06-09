<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SupportMessage;
use App\Entity\SupportTicket;
use App\Enum\SupportMessageSender;
use App\Enum\SupportTicketPriority;
use App\Repository\SupportMessageRepository;
use App\Repository\SupportTicketRepository;
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
        if ($request->isMethod('POST')) {
            $subject = trim((string) $request->request->get('subject', ''));
            $body    = trim((string) $request->request->get('body', ''));

            if ($subject === '' || $body === '') {
                $this->addFlash('error', 'Preencha o assunto e a mensagem.');
                return $this->redirectToRoute('support_new');
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

            $this->addFlash('success', 'Ticket #' . $ticket->getId() . ' aberto com sucesso.');
            return $this->redirectToRoute('support_show', ['id' => $ticket->getId()]);
        }

        return $this->render('support/new.html.twig');
    }

    #[Route('/{id}', name: 'show', methods: ['GET', 'POST'])]
    public function show(SupportTicket $ticket, Request $request): Response
    {
        if ($ticket->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        // Marca mensagens do admin como lidas pelo usuário
        $this->messageRepo->markAllReadByUser($ticket);

        if ($request->isMethod('POST') && $ticket->isOpen()) {
            $body = trim((string) $request->request->get('body', ''));
            if ($body !== '') {
                $message = (new SupportMessage())
                    ->setSender(SupportMessageSender::User)
                    ->setBody($body);

                $ticket->addMessage($message);
                $this->em->flush();

                $this->addFlash('success', 'Mensagem enviada.');
            }
            return $this->redirectToRoute('support_show', ['id' => $ticket->getId()]);
        }

        return $this->render('support/show.html.twig', [
            'ticket' => $ticket,
        ]);
    }
}
