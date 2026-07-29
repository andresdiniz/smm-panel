<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/contatos', name: 'admin_contact_')]
#[IsGranted('ROLE_ADMIN')]
class AdminContactController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface     $paginator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $qb = $this->em->getRepository(Contact::class)
            ->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'DESC');
        $pagination = $this->paginator->paginate($qb, $request->query->getInt('page', 1), 30);
        return $this->render('admin/contact/index.html.twig', ['pagination' => $pagination]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Contact $contact): Response
    {
        return $this->render('admin/contact/show.html.twig', ['contact' => $contact]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Contact $contact, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete_contact_'.$contact->getId(), $request->request->get('_token'))) {
            $this->em->remove($contact);
            $this->em->flush();
            $this->addFlash('success', 'Contato removido.');
        }
        return $this->redirectToRoute('admin_contact_index');
    }
}
