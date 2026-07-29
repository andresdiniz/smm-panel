<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BlogTag;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[Route('/admin/blog/tags', name: 'admin_blog_tag_')]
#[IsGranted('ROLE_ADMIN')]
class AdminBlogTagController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/blog/tag/index.html.twig', [
            'tags' => $this->em->getRepository(BlogTag::class)->findAll(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $tag     = new BlogTag();
            $slugger = new AsciiSlugger();
            $name    = $request->request->get('name', '');
            $tag->setName($name);
            $tag->setSlug($slugger->slug($name)->lower()->toString());
            $this->em->persist($tag);
            $this->em->flush();
            $this->addFlash('success', 'Tag criada.');
            return $this->redirectToRoute('admin_blog_tag_index');
        }
        return $this->render('admin/blog/tag/edit.html.twig', ['tag' => null]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(BlogTag $tag, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $slugger = new AsciiSlugger();
            $name    = $request->request->get('name', $tag->getName());
            $tag->setName($name);
            $tag->setSlug($slugger->slug($name)->lower()->toString());
            $this->em->flush();
            $this->addFlash('success', 'Tag atualizada.');
            return $this->redirectToRoute('admin_blog_tag_index');
        }
        return $this->render('admin/blog/tag/edit.html.twig', ['tag' => $tag]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(BlogTag $tag, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete_blog_tag_'.$tag->getId(), $request->request->get('_token'))) {
            $this->em->remove($tag);
            $this->em->flush();
            $this->addFlash('success', 'Tag removida.');
        }
        return $this->redirectToRoute('admin_blog_tag_index');
    }
}
