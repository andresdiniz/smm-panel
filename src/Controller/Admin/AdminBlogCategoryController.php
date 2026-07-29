<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BlogCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[Route('/admin/blog/categorias', name: 'admin_blog_category_')]
#[IsGranted('ROLE_ADMIN')]
class AdminBlogCategoryController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/blog/category/index.html.twig', [
            'categories' => $this->em->getRepository(BlogCategory::class)->findAll(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $cat     = new BlogCategory();
            $slugger = new AsciiSlugger();
            $name    = $request->request->get('name', '');
            $cat->setName($name);
            $cat->setSlug($slugger->slug($name)->lower()->toString());
            $this->em->persist($cat);
            $this->em->flush();
            $this->addFlash('success', 'Categoria criada.');
            return $this->redirectToRoute('admin_blog_category_index');
        }
        return $this->render('admin/blog/category/edit.html.twig', ['category' => null]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(BlogCategory $category, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $slugger = new AsciiSlugger();
            $name    = $request->request->get('name', $category->getName());
            $category->setName($name);
            $category->setSlug($slugger->slug($name)->lower()->toString());
            $this->em->flush();
            $this->addFlash('success', 'Categoria atualizada.');
            return $this->redirectToRoute('admin_blog_category_index');
        }
        return $this->render('admin/blog/category/edit.html.twig', ['category' => $category]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(BlogCategory $category, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete_blog_cat_'.$category->getId(), $request->request->get('_token'))) {
            $this->em->remove($category);
            $this->em->flush();
            $this->addFlash('success', 'Categoria removida.');
        }
        return $this->redirectToRoute('admin_blog_category_index');
    }
}
