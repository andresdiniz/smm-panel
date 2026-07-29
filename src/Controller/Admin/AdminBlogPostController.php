<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\BlogPost;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[Route('/admin/blog/posts', name: 'admin_blog_post_')]
#[IsGranted('ROLE_ADMIN')]
class AdminBlogPostController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaginatorInterface     $paginator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $qb = $this->em->getRepository(BlogPost::class)
            ->createQueryBuilder('p')
            ->orderBy('p.createdAt', 'DESC');
        $pagination = $this->paginator->paginate($qb, $request->query->getInt('page', 1), 20);
        return $this->render('admin/blog/post/index.html.twig', ['pagination' => $pagination]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $post = new BlogPost();
            $this->fillFromRequest($post, $request);
            $this->em->persist($post);
            $this->em->flush();
            $this->addFlash('success', 'Post criado.');
            return $this->redirectToRoute('admin_blog_post_index');
        }
        return $this->render('admin/blog/post/edit.html.twig', [
            'post'       => null,
            'categories' => $this->em->getRepository(\App\Entity\BlogCategory::class)->findAll(),
            'tags'       => $this->em->getRepository(\App\Entity\BlogTag::class)->findAll(),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(BlogPost $post, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->fillFromRequest($post, $request);
            $this->em->flush();
            $this->addFlash('success', 'Post atualizado.');
            return $this->redirectToRoute('admin_blog_post_index');
        }
        return $this->render('admin/blog/post/edit.html.twig', [
            'post'       => $post,
            'categories' => $this->em->getRepository(\App\Entity\BlogCategory::class)->findAll(),
            'tags'       => $this->em->getRepository(\App\Entity\BlogTag::class)->findAll(),
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(BlogPost $post, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete_blog_post_'.$post->getId(), $request->request->get('_token'))) {
            $this->em->remove($post);
            $this->em->flush();
            $this->addFlash('success', 'Post removido.');
        }
        return $this->redirectToRoute('admin_blog_post_index');
    }

    private function fillFromRequest(BlogPost $post, Request $request): void
    {
        $slugger = new AsciiSlugger();
        $title   = $request->request->get('title', '');
        $post->setTitle($title);
        $post->setSlug($slugger->slug($title)->lower()->toString());
        $post->setContent($request->request->get('content', ''));
        $post->setSummary($request->request->get('summary', ''));
        $post->setIsPublished((bool)$request->request->get('isPublished', false));

        $catId = $request->request->get('category');
        if ($catId) {
            $cat = $this->em->find(\App\Entity\BlogCategory::class, (int)$catId);
            if ($cat) $post->setCategory($cat);
        }

        $post->getTags()->clear();
        foreach ($request->request->all('tags') as $tagId) {
            $tag = $this->em->find(\App\Entity\BlogTag::class, (int)$tagId);
            if ($tag) $post->addTag($tag);
        }
    }
}
