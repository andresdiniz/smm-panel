<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Service;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/servicos', name: 'admin_service_')]
#[IsGranted('ROLE_ADMIN')]
class AdminServiceController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface     $paginator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $search = $request->query->get('q');
        $qb = $this->em->getRepository(Service::class)
            ->createQueryBuilder('s')
            ->leftJoin('s.category', 'c')->addSelect('c')
            ->orderBy('s.id', 'ASC');
        if ($search) {
            $qb->andWhere('s.name LIKE :q OR s.externalServiceId LIKE :q')
               ->setParameter('q', '%'.$search.'%');
        }
        $pagination = $this->paginator->paginate($qb, $request->query->getInt('page', 1), 50);
        return $this->render('admin/service/index.html.twig', [
            'pagination' => $pagination,
            'search'     => $search,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Service $service, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $service->setName($request->request->get('name', $service->getName()));
            $service->setIsActive((bool)$request->request->get('isActive', $service->isActive()));
            $priceCents = $request->request->get('priceCents');
            if ($priceCents !== null) $service->setPriceCents((int)$priceCents);
            $this->em->flush();
            $this->addFlash('success', 'Serviço atualizado.');
            return $this->redirectToRoute('admin_service_index');
        }
        return $this->render('admin/service/edit.html.twig', ['service' => $service]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(Service $service, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete_service_'.$service->getId(), $request->request->get('_token'))) {
            $this->em->remove($service);
            $this->em->flush();
            $this->addFlash('success', 'Serviço removido.');
        }
        return $this->redirectToRoute('admin_service_index');
    }
}
