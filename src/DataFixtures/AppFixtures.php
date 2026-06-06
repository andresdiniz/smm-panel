<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Contact;
use App\Entity\CrmContact;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\ProviderCredential;
use App\Entity\Service;
use App\Entity\ServiceCategory;
use App\Entity\User;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    public function load(ObjectManager $em): void
    {
        // ── Categorias ───────────────────────────────────────────────────────
        $catIG = (new ServiceCategory())
            ->setName('Instagram')->setSlug('instagram')->setSortOrder(1)->setIsActive(true);
        $em->persist($catIG);

        $catTT = (new ServiceCategory())
            ->setName('TikTok')->setSlug('tiktok')->setSortOrder(2)->setIsActive(true);
        $em->persist($catTT);

        // ── Credenciais de Providers ─────────────────────────────────────────
        $credJAP = (new ProviderCredential())
            ->setProviderSlug('justanotherpanel')
            ->setLabel('JustAnotherPanel Principal')
            ->setApiKey('DEMO_KEY_JAP_000000')
            ->setBaseUrl('https://justanotherpanel.com/api/v2')
            ->setBalanceCents(0)
            ->setIsActive(true);
        $em->persist($credJAP);

        $credPEA = (new ProviderCredential())
            ->setProviderSlug('peakerr')
            ->setLabel('Peakerr Principal')
            ->setApiKey('DEMO_KEY_PEAKERR_000')
            ->setBaseUrl('https://peakerr.com/api/v2')
            ->setBalanceCents(0)
            ->setIsActive(true);
        $em->persist($credPEA);

        // ── Serviços ─────────────────────────────────────────────────────────
        $s1 = (new Service())
            ->setCategory($catIG)->setName('Seguidores Instagram – BR')
            ->setDescription('Seguidores brasileiros de alta qualidade.')
            ->setProviderSlug('justanotherpanel')->setExternalServiceId('1001')
            ->setPriceCents(150)->setMinQuantity(100)->setMaxQuantity(50000)->setIsActive(true);
        $em->persist($s1);

        $s2 = (new Service())
            ->setCategory($catIG)->setName('Curtidas Instagram')
            ->setDescription('Curtidas instantâneas.')
            ->setProviderSlug('justanotherpanel')->setExternalServiceId('1002')
            ->setPriceCents(50)->setMinQuantity(50)->setMaxQuantity(100000)->setIsActive(true);
        $em->persist($s2);

        $s3 = (new Service())
            ->setCategory($catTT)->setName('Seguidores TikTok')
            ->setDescription('Seguidores TikTok mundiais.')
            ->setProviderSlug('peakerr')->setExternalServiceId('2001')
            ->setPriceCents(200)->setMinQuantity(100)->setMaxQuantity(100000)->setIsActive(true);
        $em->persist($s3);

        $s4 = (new Service())
            ->setCategory($catTT)->setName('Views TikTok')
            ->setDescription('Visualizações rápidas.')
            ->setProviderSlug('peakerr')->setExternalServiceId('2002')
            ->setPriceCents(10)->setMinQuantity(1000)->setMaxQuantity(1000000)->setIsActive(true);
        $em->persist($s4);

        // ── Usuários ──────────────────────────────────────────────────────────
        $admin = new User();
        $admin->setEmail('admin@pulsesmm.com.br')
            ->setName('Administrador')
            ->setRoles(['ROLE_ADMIN', 'ROLE_USER'])
            ->setIsActive(true);
        $admin->setPassword($this->hasher->hashPassword($admin, 'Admin@1234'));
        $em->persist($admin);
        $this->createWallet($em, $admin, 50000_00);
        $this->createCrmContact($em, $admin, ['admin', 'new_user'], 'direct', null, null);

        $userData = [
            ['João Silva',    'joao@exemplo.com.br',  'User@1234', 15000_00, ['buyer', 'new_user'],        'google',    'smm_campaign_q1', 'cpc'],
            ['Maria Santos',  'maria@exemplo.com.br', 'User@1234',  8500_00, ['buyer', 'abandoned_cart'], 'instagram', null,               'social'],
            ['Pedro Costa',   'pedro@exemplo.com.br', 'User@1234',   500_00, ['new_user'],                null,        null,               null],
        ];

        $userEntities = [];
        foreach ($userData as [$name, $email, $pass, $balance, $tags, $src, $camp, $med]) {
            $u = new User();
            $u->setEmail($email)->setName($name)->setRoles(['ROLE_USER'])->setIsActive(true);
            $u->setPassword($this->hasher->hashPassword($u, $pass));
            $em->persist($u);
            $this->createWallet($em, $u, $balance);
            $this->createCrmContact($em, $u, $tags, $src, $camp, $med);
            $userEntities[] = $u;
        }

        $em->flush(); // flush para gerar IDs

        // ── Pedidos ───────────────────────────────────────────────────────────
        $ordersData = [
            [$userEntities[0], $s1, 'https://instagram.com/joaosilva',   1000,  'completed'],
            [$userEntities[0], $s2, 'https://instagram.com/p/abc123',     500,  'processing'],
            [$userEntities[1], $s1, 'https://instagram.com/mariasantos', 2000,  'completed'],
            [$userEntities[1], $s3, 'https://tiktok.com/@mariasantos',   1000,  'pending'],
            [$userEntities[2], $s4, 'https://tiktok.com/@pedrocosta',   10000,  'pending'],
            [$admin,           $s1, 'https://instagram.com/admintest',    500,  'completed'],
        ];

        foreach ($ordersData as [$user, $service, $link, $qty, $status]) {
            $order = new Order();
            $order->setUser($user)
                ->setService($service)
                ->setLink($link)
                ->setQuantity($qty)
                ->setAmountCents((int) ($service->getPriceCents() * $qty / 1000))
                ->setStatus($status)
                ->setProviderSlug($service->getProviderSlug())
                ->setExternalOrderId($status !== 'pending' ? 'EXT-' . random_int(10000, 99999) : null);
            $em->persist($order);
        }

        // ── Pagamentos ────────────────────────────────────────────────────────
        $now = new \DateTimeImmutable();
        $paymentsData = [
            [$userEntities[0], 'asaas', 'pix',          15000_00,  45_00, 'paid',    $now],
            [$userEntities[1], 'asaas', 'credit_card',   8500_00, 297_50, 'paid',    $now],
            [$userEntities[2], 'asaas', 'pix',            500_00,   1_50, 'pending', null],
        ];

        foreach ($paymentsData as [$user, $gw, $method, $amount, $fee, $status, $paidAt]) {
            $p = new Payment();
            $p->setUser($user)->setGateway($gw)->setMethod($method)
                ->setAmountCents($amount)->setFeeCents($fee)
                ->setStatus($status)->setExternalId('PAY-' . random_int(100000, 999999));
            if ($paidAt instanceof \DateTimeImmutable) {
                $p->setPaidAt($paidAt);
            }
            $em->persist($p);
        }

        // ── Ticket de suporte (entidade Contact = CRM lead/suporte) ───────────
        $ticket = new Contact();
        $ticket->setName($userEntities[0]->getName())
            ->setEmail($userEntities[0]->getEmail())
            ->setSource('painel')
            ->setStatus(Contact::STATUS_NEW)
            ->setNotes('Pedido não processado após 2 horas.');
        $em->persist($ticket);

        $em->flush();
    }

    private function createWallet(ObjectManager $em, User $user, int $balanceCents): void
    {
        $wallet = new Wallet();
        $wallet->setUser($user)->setBalanceCents($balanceCents);
        $em->persist($wallet);

        if ($balanceCents > 0) {
            $tx = new WalletTransaction();
            $tx->setWallet($wallet)
                ->setAmountCents($balanceCents)
                ->setType('credit')
                ->setDescription('Saldo inicial (seed)');
            $em->persist($tx);
        }
    }

    private function createCrmContact(
        ObjectManager $em,
        User $user,
        array $tags,
        ?string $src,
        ?string $camp,
        ?string $med,
    ): void {
        $c = new CrmContact($user);
        foreach ($tags as $tag) {
            $c->addTag($tag);
        }
        if ($src)  { $c->setUtmSource($src); }
        if ($camp) { $c->setUtmCampaign($camp); }
        if ($med)  { $c->setUtmMedium($med); }
        $c->addEvent('user.registered', ['seed' => true]);
        $em->persist($c);
    }
}
