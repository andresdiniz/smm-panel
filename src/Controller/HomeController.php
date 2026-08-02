<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'title' => 'SMM Panel profissional para vender mais com automação',
            'description' => 'Plataforma completa para gerenciar serviços SMM, automação de pedidos, pagamentos e crescimento com foco em conversão e SEO.',
        ]);
    }

    #[Route('/politica-de-privacidade', name: 'privacy')]
    public function privacy(): Response
    {
        return $this->render('home/privacy.html.twig');
    }

    #[Route('/politica-de-cookies', name: 'cookies')]
    public function cookies(): Response
    {
        return $this->render('home/cookies.html.twig');
    }
}
