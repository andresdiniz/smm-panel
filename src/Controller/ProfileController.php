<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/profile', name: 'app_profile')]
class ProfileController extends AbstractController
{
    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!is_string($token) || !$this->isCsrfTokenValid('profile_edit', $token)) {
                throw $this->createAccessDeniedException('Token inválido.');
            }

            $name = trim($request->request->getString('name'));
            if ($name !== '') {
                $user->setName($name);
            }

            $user->setMarketingConsent($request->request->getBoolean('marketing_consent'));
            $em->flush();
            $this->addFlash('success', 'Perfil atualizado com sucesso.');

            return $this->redirectToRoute('app_profile');
        }

        return $this->render('profile/index.html.twig', [
            'user' => $user,
        ]);
    }
}
