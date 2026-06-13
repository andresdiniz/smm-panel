<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LegalController extends AbstractController
{
    #[Route('/termos', name: 'app_terms')]
    public function terms(): Response
    {
        return $this->render('legal/terms.html.twig');
    }

    #[Route('/privacidade', name: 'app_privacy')]
    public function privacy(): Response
    {
        return $this->render('legal/privacy.html.twig');
    }
}
