<?php

namespace App\Controller;

use App\Repository\BlogCategoryRepository;
use App\Repository\BlogPostRepository;
use App\Repository\BlogTagRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/blog', name: 'blog_')]
class BlogController extends AbstractController
{
    private const PER_PAGE = 9;

    public function __construct(
        private readonly BlogPostRepository     $postRepo,
        private readonly BlogCategoryRepository $categoryRepo,
        private readonly BlogTagRepository      $tagRepo,
    ) {}

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $page  = max(1, (int) $request->query->get('page', 1));
        $posts = $this->postRepo->findPublishedPaginated($page, self::PER_PAGE);
        $total = count($posts);

        return $this->render('blog/index.html.twig', [
            'posts'      => $posts,
            'categories' => $this->categoryRepo->findAllWithPostCount(),
            'recent'     => $this->postRepo->findRecent(5),
            'page'       => $page,
            'totalPages' => (int) ceil($total / self::PER_PAGE),
            'total'      => $total,
        ]);
    }

    #[Route('/busca', name: 'search')]
    public function search(Request $request): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $page  = max(1, (int) $request->query->get('page', 1));

        $posts = $query
            ? $this->postRepo->search($query, $page, self::PER_PAGE)
            : $this->postRepo->findPublishedPaginated($page, self::PER_PAGE);

        $total = count($posts);

        return $this->render('blog/index.html.twig', [
            'posts'      => $posts,
            'categories' => $this->categoryRepo->findAllWithPostCount(),
            'recent'     => $this->postRepo->findRecent(5),
            'page'       => $page,
            'totalPages' => (int) ceil($total / self::PER_PAGE),
            'total'      => $total,
            'searchQuery'=> $query,
        ]);
    }

    #[Route('/categoria/{slug}', name: 'category')]
    public function category(string $slug, Request $request): Response
    {
        $category = $this->categoryRepo->findBySlug($slug);
        if (!$category) { throw $this->createNotFoundException('Categoria não encontrada.'); }

        $page  = max(1, (int) $request->query->get('page', 1));
        $posts = $this->postRepo->findByCategory($category, $page, self::PER_PAGE);
        $total = count($posts);

        return $this->render('blog/category.html.twig', [
            'category'   => $category,
            'posts'      => $posts,
            'categories' => $this->categoryRepo->findAllWithPostCount(),
            'recent'     => $this->postRepo->findRecent(5),
            'page'       => $page,
            'totalPages' => (int) ceil($total / self::PER_PAGE),
            'total'      => $total,
        ]);
    }

    #[Route('/tag/{slug}', name: 'tag')]
    public function tag(string $slug, Request $request): Response
    {
        $tag = $this->tagRepo->findBySlug($slug);
        if (!$tag) { throw $this->createNotFoundException('Tag não encontrada.'); }

        $page  = max(1, (int) $request->query->get('page', 1));
        $posts = $this->postRepo->findByTag($tag, $page, self::PER_PAGE);
        $total = count($posts);

        return $this->render('blog/tag.html.twig', [
            'tag'        => $tag,
            'posts'      => $posts,
            'categories' => $this->categoryRepo->findAllWithPostCount(),
            'recent'     => $this->postRepo->findRecent(5),
            'page'       => $page,
            'totalPages' => (int) ceil($total / self::PER_PAGE),
            'total'      => $total,
        ]);
    }

    #[Route('/{slug}', name: 'show', priority: -1)]
    public function show(string $slug): Response
    {
        $post = $this->postRepo->findBySlug($slug);

        if (!$post || !$post->isPublished()) {
            throw $this->createNotFoundException('Post não encontrado.');
        }

        $post->incrementViews();
        $this->postRepo->getEntityManager()->flush();

        return $this->render('blog/show.html.twig', [
            'post'       => $post,
            'categories' => $this->categoryRepo->findAllWithPostCount(),
            'recent'     => $this->postRepo->findRecent(5),
        ]);
    }
}
