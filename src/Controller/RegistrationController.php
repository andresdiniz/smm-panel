<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\Wallet;
use App\Form\RegistrationFormType;
use App\Message\SendWelcomeEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(
        Request                     $request,
        UserPasswordHasherInterface $hasher,
        EntityManagerInterface      $em,
        MessageBusInterface         $bus,
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword(
                $hasher->hashPassword($user, $form->get('plainPassword')->getData())
            );
            $user->setRoles(['ROLE_USER']);
            $user->setActive(true);

            $em->persist($user);

            $wallet = new Wallet();
            $wallet->setUser($user);
            $wallet->setBalanceCents(0);
            $em->persist($wallet);

            $em->flush();

            // Dispara e-mail de boas-vindas de forma assíncrona
            $bus->dispatch(new SendWelcomeEmailMessage($user->getId()));

            $this->addFlash('success', 'Conta criada com sucesso! Faça login para continuar.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }
}
