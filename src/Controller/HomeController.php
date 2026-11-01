<?php

namespace App\Controller;

use App\Repository\ServiceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    #[Route('/', name: 'home')]
    public function index(): Response
    {
        return $this->render('home/index.html.twig', [
            'title' => 'PulseSMM — serviços para suas redes sociais',
            'description' => 'Conheça os serviços disponíveis, consulte preços e acompanhe seus pedidos em um só lugar.',
        ]);
    }

    #[Route('/servicos', name: 'app_catalog', methods: ['GET'])]
    public function catalog(ServiceRepository $services): Response
    {
        return $this->render('catalog/index.html.twig', [
            'title' => 'Serviços de SMM para redes sociais | PulseSMM',
            'description' => 'Explore serviços para redes sociais, compare opções e acompanhe seus pedidos em uma plataforma de SMM.',
            'services' => $services->findActiveForPublicCatalog(),
        ]);
    }

    #[Route('/como-funciona', name: 'app_how_it_works', methods: ['GET'])]
    public function howItWorks(): Response
    {
        return $this->render('home/how_it_works.html.twig', [
            'title' => 'Como funciona a PulseSMM | Pedidos e acompanhamento',
            'description' => 'Veja como escolher um serviço, criar pedidos e acompanhar o status e o saldo na PulseSMM.',
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
