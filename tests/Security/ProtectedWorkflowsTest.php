<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Payment;
use App\Entity\PaymentRefundRequest;
use App\Entity\User;
use App\Repository\PaymentRefundRequestRepository;
use App\Repository\PaymentRepository;
use App\Repository\WalletRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class ProtectedWorkflowsTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return \App\Kernel::class;
    }

    public function testWalletAndAdminRoutesRequireAuthentication(): void
    {
        $client = $this->createIsolatedClient();

        foreach (['/wallet', '/admin/crm', '/admin/estornos'] as $path) {
            $client->request('GET', $path);

            self::assertSame(302, $client->getResponse()->getStatusCode(), $path.' '.$client->getResponse()->getContent());
            self::assertSame('/login', parse_url($client->getResponse()->headers->get('Location'), PHP_URL_PATH));
        }
    }

    public function testRegularUserCannotAccessAdminCrmOrRefundRoutes(): void
    {
        foreach (['/admin/crm', '/admin/estornos'] as $path) {
            $client = $this->createIsolatedClient();
            $this->authenticate($client, $this->createUser());
            $client->request('GET', $path);

            self::assertSame(403, $client->getResponse()->getStatusCode(), $path.' '.self::responseTitle($client));
        }
    }

    public function testUserCannotRequestRefundForAnotherUsersPayment(): void
    {
        $client = $this->createIsolatedClient();
        $currentUser = $this->createUser();
        $paymentOwner = $this->createUser('owner@example.test');
        $this->authenticate($client, $currentUser);

        $payments = $this->createMock(PaymentRepository::class);
        $payments->expects(self::once())
            ->method('find')
            ->with(17)
            ->willReturn((new Payment())->setUser($paymentOwner));
        static::getContainer()->set(PaymentRepository::class, $payments);

        $client->request('POST', '/wallet/payments/17/refund-request');

        self::assertSame(404, $client->getResponse()->getStatusCode(), self::responseTitle($client));
    }

    public function testUserCannotCancelAnotherUsersPix(): void
    {
        $client = $this->createIsolatedClient();
        $currentUser = $this->createUser();
        $paymentOwner = $this->createUser('owner@example.test');
        $this->authenticate($client, $currentUser);

        $payment = (new Payment())
            ->setUser($paymentOwner)
            ->setType(Payment::TYPE_DEPOSIT)
            ->setMethod(Payment::METHOD_PIX);
        $payments = $this->createMock(PaymentRepository::class);
        $payments->expects(self::once())->method('find')->with(18)->willReturn($payment);
        static::getContainer()->set(PaymentRepository::class, $payments);

        $client->request('POST', '/wallet/pix/18/cancel');

        self::assertSame(404, $client->getResponse()->getStatusCode(), self::responseTitle($client));
    }

    public function testWalletDepositRejectsMissingCsrfTokenBeforeGatewayUse(): void
    {
        $client = $this->createIsolatedClient();
        $this->authenticate($client, $this->createUser());

        $wallets = $this->createMock(WalletRepository::class);
        $wallets->expects(self::once())->method('findOneByUser')->willReturn(null);
        static::getContainer()->set(WalletRepository::class, $wallets);

        $client->request('POST', '/wallet/deposit', ['amount' => '25.00', 'method' => 'pix']);

        self::assertRedirectPath('/wallet/deposit', $client);
    }

    public function testRefundApprovalRejectsInvalidCsrfTokenBeforeFinancialChanges(): void
    {
        $client = $this->createIsolatedClient();
        $this->authenticate($client, $this->createUser('admin@example.test', ['ROLE_ADMIN']));

        $refund = new PaymentRefundRequest(
            (new Payment())->setUser($this->createUser('customer@example.test')),
            'Test reason',
        );
        $refunds = $this->createMock(PaymentRefundRequestRepository::class);
        $refunds->expects(self::once())->method('find')->with(23)->willReturn($refund);
        static::getContainer()->set(PaymentRefundRequestRepository::class, $refunds);

        $client->request('POST', '/admin/estornos/23/approve');

        self::assertRedirectPath('/admin/estornos', $client);
    }

    public function testCrmCampaignRejectsInvalidCsrfToken(): void
    {
        $client = $this->createIsolatedClient();
        $this->authenticate($client, $this->createUser('admin@example.test', ['ROLE_ADMIN']));

        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');
        static::getContainer()->set(MailerInterface::class, $mailer);

        $client->request('POST', '/admin/crm/send-marketing', [
            'subject' => 'Test',
            'body' => 'Test body',
            'preview_only' => '0',
        ]);
        self::assertRedirectPath('/admin/crm', $client);
        self::assertRedirectPath('/admin/crm', $client);
    }

    private function createIsolatedClient(): AbstractBrowser
    {
        static::ensureKernelShutdown();

        $databaseUrl = 'mysql://unavailable:unavailable@127.0.0.1:1/security_tests';
        $_ENV['DATABASE_URL'] = $databaseUrl;
        $_SERVER['DATABASE_URL'] = $databaseUrl;
        $_ENV['APP_SECRET'] = $_SERVER['APP_SECRET'] = 'security-test-only-secret';
        $_ENV['AFFILIATE_DEFAULT_RATE'] = $_SERVER['AFFILIATE_DEFAULT_RATE'] = '0.10';

        return static::createClient();
    }

    private function authenticate(AbstractBrowser $client, User $user): void
    {
        static::getContainer()->set(
            'security.user.provider.concrete.app_user_provider',
            new SecurityTestUserProvider($user),
        );
        $client->loginUser($user);
    }

    /**
     * @param string[] $roles
     */
    private function createUser(string $email = 'user@example.test', array $roles = []): User
    {
        return (new User())
            ->setEmail($email)
            ->setName('Security Test')
            ->setPassword('not-used')
            ->setRoles($roles);
    }

    private static function assertRedirectPath(string $path, AbstractBrowser $client): void
    {
        self::assertSame(302, $client->getResponse()->getStatusCode(), self::responseTitle($client));
        self::assertSame($path, parse_url($client->getResponse()->headers->get('Location'), PHP_URL_PATH));
    }

    private static function responseTitle(AbstractBrowser $client): string
    {
        preg_match('/<title>(.*?)<\/title>/s', $client->getResponse()->getContent(), $matches);

        return html_entity_decode(strip_tags($matches[1] ?? ''), ENT_QUOTES | ENT_HTML5);
    }

}

final class SecurityTestUserProvider implements UserProviderInterface
{
    public function __construct(private readonly User $user)
    {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if ($identifier !== $this->user->getUserIdentifier()) {
            throw new \Symfony\Component\Security\Core\Exception\UserNotFoundException();
        }

        return $this->user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$this->supportsClass($user::class)) {
            throw new UnsupportedUserException();
        }

        return $this->user;
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, User::class, true);
    }
}
