<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PasswordResetRequest;
use App\Repository\PasswordResetRequestRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Mime\Address;

final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly PasswordResetRequestRepository $resetRequests,
        private readonly MailerInterface $mailer,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.password_reset_request_ip')]
        private readonly RateLimiterFactory $requestIpLimiter,
        #[Autowire(service: 'limiter.password_reset_request_email')]
        private readonly RateLimiterFactory $requestEmailLimiter,
        #[Autowire(service: 'limiter.password_reset_submit_ip')]
        private readonly RateLimiterFactory $submitIpLimiter,
        #[Autowire('%kernel.secret%')]
        private readonly string $appSecret,
    ) {}

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function requestReset(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->render('security/forgot_password.html.twig');
        }

        $csrfToken = $request->request->get('_token');
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid('forgot_password', $csrfToken)) {
            return $this->render('security/forgot_password.html.twig', [
                'formError' => 'A solicitação expirou. Atualize a página e tente novamente.',
            ], new Response(status: Response::HTTP_BAD_REQUEST));
        }

        $ipKey = $this->rateLimitKey('request-ip', $request->getClientIp() ?? 'unknown');
        if (!$this->requestIpLimiter->create($ipKey)->consume()->isAccepted()) {
            $this->addFlash('success', $this->genericRequestMessage());
            return $this->redirectToRoute('app_forgot_password');
        }

        $submittedEmail = $request->request->get('email', '');
        $email = is_string($submittedEmail) ? mb_strtolower(trim($submittedEmail), 'UTF-8') : '';

        if ($email !== '' && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emailKey = $this->rateLimitKey('request-email', $email);
            if ($this->requestEmailLimiter->create($emailKey)->consume()->isAccepted()) {
                $user = $this->users->findOneByNormalizedEmail($email);
                if ($user !== null && $user->isActive()) {
                    $this->createAndSendResetRequest($user);
                }
            }
        }

        $this->addFlash('success', $this->genericRequestMessage());
        return $this->redirectToRoute('app_forgot_password');
    }

    #[Route(
        '/reset-password/{selector}/{token}',
        name: 'app_reset_password',
        requirements: ['selector' => '[a-f0-9]{32}', 'token' => '[A-Za-z0-9_-]{43}'],
        methods: ['GET', 'POST'],
    )]
    public function resetPassword(string $selector, string $token, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $ipKey = $this->rateLimitKey('submit-ip', $request->getClientIp() ?? 'unknown');
            if (!$this->submitIpLimiter->create($ipKey)->consume()->isAccepted()) {
                return $this->render('security/reset_password.html.twig', [
                    'selector' => $selector,
                    'token' => $token,
                    'formError' => 'Muitas tentativas. Aguarde antes de tentar novamente.',
                ], new Response(status: Response::HTTP_TOO_MANY_REQUESTS));
            }

            $csrfToken = $request->request->get('_token');
            if (!is_string($csrfToken) || !$this->isCsrfTokenValid('reset_password_'.$selector, $csrfToken)) {
                return $this->render('security/reset_password.html.twig', [
                    'selector' => $selector,
                    'token' => $token,
                    'formError' => 'A solicitação expirou. Atualize a página e tente novamente.',
                ], new Response(status: Response::HTTP_BAD_REQUEST));
            }
        }

        $resetRequest = $this->findUsableRequest($selector, $token);
        if ($resetRequest === null) {
            throw $this->createNotFoundException('O link de recuperação é inválido ou expirou.');
        }

        if ($request->isMethod('POST')) {
            $password = $request->request->get('password', '');
            $passwordConfirmation = $request->request->get('password_confirmation', '');

            if (!is_string($password) || !is_string($passwordConfirmation)
                || mb_strlen($password, 'UTF-8') < 8
                || mb_strlen($password, 'UTF-8') > 4096
            ) {
                return $this->render('security/reset_password.html.twig', [
                    'selector' => $selector,
                    'token' => $token,
                    'formError' => 'A senha deve ter entre 8 e 4096 caracteres.',
                ]);
            }

            if (!hash_equals($password, $passwordConfirmation)) {
                return $this->render('security/reset_password.html.twig', [
                    'selector' => $selector,
                    'token' => $token,
                    'formError' => 'As senhas não coincidem.',
                ]);
            }

            try {
                $this->em->wrapInTransaction(function () use ($selector, $token, $password): void {
                    $lockedRequest = $this->resetRequests->findOneBy(['selector' => $selector]);
                    if ($lockedRequest === null) {
                        throw new \DomainException('Token de recuperação inválido.');
                    }

                    $this->em->refresh($lockedRequest, LockMode::PESSIMISTIC_WRITE);
                    $now = new \DateTimeImmutable();
                    if (!$lockedRequest->isUsable($token, $now)) {
                        throw new \DomainException('Token de recuperação inválido ou expirado.');
                    }

                    $user = $lockedRequest->getUser();
                    $user->setPassword($this->passwordHasher->hashPassword($user, $password));
                    $lockedRequest->markUsed($now);

                    $this->em->createQuery(
                        'UPDATE App\Entity\PasswordResetRequest r
                         SET r.usedAt = :usedAt
                         WHERE r.user = :user AND r.usedAt IS NULL'
                    )
                        ->setParameter('usedAt', $now)
                        ->setParameter('user', $user)
                        ->execute();
                });
            } catch (\DomainException) {
                throw $this->createNotFoundException('O link de recuperação é inválido ou expirou.');
            }

            $this->addFlash('success', 'Senha alterada. Entre usando sua nova senha.');
            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', [
            'selector' => $selector,
            'token' => $token,
        ]);
    }

    private function createAndSendResetRequest(\App\Entity\User $user): void
    {
        $now = new \DateTimeImmutable();
        $rawToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $selector = bin2hex(random_bytes(16));

        $this->em->createQuery(
            'DELETE FROM App\Entity\PasswordResetRequest r WHERE r.expiresAt < :expiredBefore'
        )
            ->setParameter('expiredBefore', $now)
            ->execute();

        $this->em->createQuery(
            'UPDATE App\Entity\PasswordResetRequest r
             SET r.usedAt = :usedAt
             WHERE r.user = :user AND r.usedAt IS NULL'
        )
            ->setParameter('usedAt', $now)
            ->setParameter('user', $user)
            ->execute();

        $resetRequest = new PasswordResetRequest(
            $user,
            $selector,
            hash('sha256', $rawToken),
            $now,
            $now->modify('+1 hour'),
        );
        $this->em->persist($resetRequest);
        $this->em->flush();

        $email = (new TemplatedEmail())
            ->to(new Address($user->getEmail(), $user->getName()))
            ->subject('Recuperação de senha — PulseSMM')
            ->htmlTemplate('emails/password_reset.html.twig')
            ->context([
                'user' => $user,
                'resetUrl' => $this->generateUrl(
                    'app_reset_password',
                    ['selector' => $selector, 'token' => $rawToken],
                    \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ]);

        try {
            $this->mailer->send($email);
            $this->logger->info('Password reset email submitted to the configured mailer transport.');
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Não foi possível enviar o e-mail de recuperação de senha.', [
                'exception' => $exception::class,
            ]);
        }
    }

    private function findUsableRequest(string $selector, string $token): ?PasswordResetRequest
    {
        $resetRequest = $this->resetRequests->findOneBy(['selector' => $selector]);
        if ($resetRequest === null || !$resetRequest->isUsable($token, new \DateTimeImmutable())) {
            return null;
        }

        return $resetRequest;
    }

    private function rateLimitKey(string $scope, string $value): string
    {
        return hash_hmac('sha256', $scope.':'.$value, $this->appSecret);
    }

    private function genericRequestMessage(): string
    {
        return 'Se o e-mail estiver cadastrado, enviaremos as instruções de recuperação.';
    }
}
