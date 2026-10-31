<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Service;
use App\Entity\ServiceCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/servicos/categorias', name: 'admin_service_category_')]
#[IsGranted('ROLE_ADMIN')]
class AdminServiceCategoryController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $categories = $this->em->getRepository(ServiceCategory::class)->findAll();
        $serviceCounts = $this->em->getRepository(Service::class)
            ->createQueryBuilder('s')
            ->select('s.category AS categoryName, COUNT(s.id) AS serviceCount')
            ->where('s.category IS NOT NULL')
            ->groupBy('s.category')
            ->getQuery()
            ->getArrayResult();
        $countsByName = [];
        foreach ($serviceCounts as $row) {
            $countsByName[mb_strtolower(trim((string) $row['categoryName']), 'UTF-8')] = (int) $row['serviceCount'];
        }

        $categoryServiceCounts = [];
        foreach ($categories as $category) {
            $key = mb_strtolower(trim($category->getName()), 'UTF-8');
            $categoryServiceCounts[$category->getId()] = $countsByName[$key] ?? 0;
        }

        return $this->render('admin/service_category/index.html.twig', [
            'categories' => $categories,
            'categoryServiceCounts' => $categoryServiceCounts,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $cat = new ServiceCategory();
            $cat->setName($request->request->get('name', ''));
            $this->em->persist($cat);
            $this->em->flush();
            $this->addFlash('success', 'Categoria criada.');
            return $this->redirectToRoute('admin_service_category_index');
        }
        return $this->render('admin/service_category/edit.html.twig', ['category' => null]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(ServiceCategory $category, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $category->setName($request->request->get('name', $category->getName()));
            $this->em->flush();
            $this->addFlash('success', 'Categoria atualizada.');
            return $this->redirectToRoute('admin_service_category_index');
        }
        return $this->render('admin/service_category/edit.html.twig', ['category' => $category]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(ServiceCategory $category, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete_svc_cat_'.$category->getId(), $request->request->get('_token'))) {
            $serviceCount = $this->em->getRepository(Service::class)->count([
                'category' => $category->getName(),
            ]);
            if ($serviceCount > 0) {
                $this->addFlash('error', 'Não é possível remover uma categoria que possui serviços vinculados.');
                return $this->redirectToRoute('admin_service_category_index');
            }

            $this->em->remove($category);
            $this->em->flush();
            $this->addFlash('success', 'Categoria removida.');
        }
        return $this->redirectToRoute('admin_service_category_index');
    }
}
