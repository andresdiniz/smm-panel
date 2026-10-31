<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/settings', name: 'app_settings')]
class SettingsController extends AbstractController
{
    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher
    ): Response {
        $user = $this->getUser();
        $error = null;

        if ($request->isMethod('POST')) {
            $this->isCsrfTokenValid('settings_password', $request->request->get('_token'))
                ?: throw $this->createAccessDeniedException('Token inválido.');

            $current  = $request->request->getString('current_password');
            $new      = $request->request->getString('new_password');
            $confirm  = $request->request->getString('confirm_password');

            if (!$hasher->isPasswordValid($user, $current)) {
                $error = 'Senha atual incorreta.';
            } elseif (strlen($new) < 8) {
                $error = 'A nova senha deve ter pelo menos 8 caracteres.';
            } elseif ($new !== $confirm) {
                $error = 'As senhas não coincidem.';
            } else {
                $user->setPassword($hasher->hashPassword($user, $new));
                $em->flush();
                $this->addFlash('success', 'Senha alterada com sucesso.');
                return $this->redirectToRoute('app_settings');
            }
        }

        return $this->render('settings/index.html.twig', [
            'error' => $error,
        ]);
    }
}
