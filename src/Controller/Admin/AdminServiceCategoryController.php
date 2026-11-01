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
use Symfony\Component\String\Slugger\AsciiSlugger;

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
            $name = $request->request->get('name');
            if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 100) {
                $this->addFlash('error', 'Informe um nome de categoria com até 100 caracteres.');

                return $this->render('admin/service_category/edit.html.twig', [
                    'category' => null,
                    'name' => is_string($name) ? trim($name) : '',
                ]);
            }

            $cat = new ServiceCategory();
            $cat->setName(trim($name));
            $cat->setSlug($this->makeUniqueSlug($name));
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
            $name = $request->request->get('name', $category->getName());
            $slug = $request->request->get('slug', '');
            if (!is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 100 || !is_string($slug)) {
                $this->addFlash('error', 'Informe um nome válido para a categoria.');

                return $this->render('admin/service_category/edit.html.twig', ['category' => $category]);
            }

            $category->setName(trim($name));
            $category->setSlug($this->makeUniqueSlug(trim($slug) !== '' ? $slug : $name, $category));
            $this->em->flush();
            $this->addFlash('success', 'Categoria atualizada.');
            return $this->redirectToRoute('admin_service_category_index');
        }
        return $this->render('admin/service_category/edit.html.twig', ['category' => $category]);
    }

    private function makeUniqueSlug(string $value, ?ServiceCategory $currentCategory = null): string
    {
        $slugger = new AsciiSlugger();
        $baseSlug = $slugger->slug(trim($value))->lower()->toString();
        if ($baseSlug === '') {
            $baseSlug = 'categoria';
        }

        $baseSlug = mb_substr($baseSlug, 0, 120);
        $slug = $baseSlug;
        $suffix = 2;
        $repository = $this->em->getRepository(ServiceCategory::class);
        $existing = $repository->findOneBy(['slug' => $slug]);

        while ($existing !== null && ($currentCategory === null || $existing->getId() !== $currentCategory->getId())) {
            $suffixText = '-'.$suffix++;
            $slug = mb_substr($baseSlug, 0, 120 - mb_strlen($suffixText)).$suffixText;
            $existing = $repository->findOneBy(['slug' => $slug]);
        }

        return $slug;
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
