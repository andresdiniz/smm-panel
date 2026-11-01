<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\BlogPostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]
    public function index(Request $request, BlogPostRepository $posts): Response
    {
        $siteUrl = rtrim($request->getSchemeAndHttpHost(), '/');

        $response = $this->render('seo/sitemap.xml.twig', [
            'site_url' => $siteUrl,
            'posts' => $posts->findPublishedForSitemap(),
        ]);

        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        $response->setPublic();
        $response->setMaxAge(3600);
        $response->setSharedMaxAge(3600);

        return $response;
    }
}
