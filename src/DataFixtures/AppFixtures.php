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
use App\Enum\TransactionType;
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
        // ── Categorias ────────────────────────────────────────────────
        $catIG = (new ServiceCategory())
            ->setName('Instagram')->setSlug('instagram')->setSortOrder(1)->setIsActive(true);
        $em->persist($catIG);

        $catTT = (new ServiceCategory())
            ->setName('TikTok')->setSlug('tiktok')->setSortOrder(2)->setIsActive(true);
        $em->persist($catTT);

        // ── Providers ──────────────────────────────────────────────
        $credJAP = (new ProviderCredential())
            ->setType(ProviderCredential::TYPE_SMM)
            ->setSlug('justanotherpanel')
            ->setBaseUrl('https://justanotherpanel.com/api/v2')
            ->setApiKey('DEMO_KEY_JAP_000000')
            ->setActive(true);
        $em->persist($credJAP);

        $credPEA = (new ProviderCredential())
            ->setType(ProviderCredential::TYPE_SMM)
            ->setSlug('peakerr')
            ->setBaseUrl('https://peakerr.com/api/v2')
            ->setApiKey('DEMO_KEY_PEAKERR_000')
            ->setActive(true);
        $em->persist($credPEA);

        $credAsaas = (new ProviderCredential())
            ->setType(ProviderCredential::TYPE_PAYMENT)
            ->setSlug('asaas')
            ->setBaseUrl('https://sandbox.asaas.com/api/v3')
            ->setApiKey('DEMO_KEY_ASAAS_SANDBOX')
            ->setActive(true);
        $em->persist($credAsaas);

        // ── Serviços (category = string, getPricePerThousandCents) ───────────
        $s1 = (new Service())
            ->setCategory('Instagram')
            ->setName('Seguidores Instagram – BR')
            ->setDescription('Seguidores brasileiros de alta qualidade.')
            ->setProviderSlug('justanotherpanel')->setExternalServiceId('1001')
            ->setPricePerThousandCents(150)->setMinQty(100)->setMaxQty(50000)->setActive(true);
        $em->persist($s1);

        $s2 = (new Service())
            ->setCategory('Instagram')
            ->setName('Curtidas Instagram')
            ->setDescription('Curtidas instantâneas.')
            ->setProviderSlug('justanotherpanel')->setExternalServiceId('1002')
            ->setPricePerThousandCents(50)->setMinQty(50)->setMaxQty(100000)->setActive(true);
        $em->persist($s2);

        $s3 = (new Service())
            ->setCategory('TikTok')
            ->setName('Seguidores TikTok')
            ->setDescription('Seguidores TikTok mundiais.')
            ->setProviderSlug('peakerr')->setExternalServiceId('2001')
            ->setPricePerThousandCents(200)->setMinQty(100)->setMaxQty(100000)->setActive(true);
        $em->persist($s3);

        $s4 = (new Service())
            ->setCategory('TikTok')
            ->setName('Views TikTok')
            ->setDescription('Visualizações rápidas.')
            ->setProviderSlug('peakerr')->setExternalServiceId('2002')
            ->setPricePerThousandCents(10)->setMinQty(1000)->setMaxQty(1000000)->setActive(true);
        $em->persist($s4);

        // ── Usuários ────────────────────────────────────────────────
        $admin = new User();
        $admin->setEmail('admin@pulsesmm.com.br')
            ->setName('Administrador')
            ->setRoles(['ROLE_ADMIN', 'ROLE_USER']);
        $admin->setPassword($this->hasher->hashPassword($admin, 'Admin@1234'));
        $em->persist($admin);
        $this->createWallet($em, $admin, 50_000_00);
        $this->createCrmContact($em, $admin, ['admin'], 'direct', null, null);

        $userData = [
            ['João Silva',  'joao@exemplo.com.br',  'User@1234', 15_000_00, ['buyer','new_user'],        'google',    'smm_q1', 'cpc'],
            ['Maria Santos','maria@exemplo.com.br', 'User@1234',  8_500_00, ['buyer','abandoned_cart'], 'instagram', null,     'social'],
            ['Pedro Costa', 'pedro@exemplo.com.br', 'User@1234',    500_00, ['new_user'],               null,        null,     null],
        ];

        $users = [];
        foreach ($userData as [$name, $email, $pass, $balance, $tags, $src, $camp, $med]) {
            $u = new User();
            $u->setEmail($email)->setName($name)->setRoles(['ROLE_USER']);
            $u->setPassword($this->hasher->hashPassword($u, $pass));
            $em->persist($u);
            $this->createWallet($em, $u, $balance);
            $this->createCrmContact($em, $u, $tags, $src, $camp, $med);
            $users[] = $u;
        }

        $em->flush();

        // ── Pedidos ─────────────────────────────────────────────────────
        foreach ([
            [$users[0], $s1, 'https://instagram.com/joaosilva',    1000, Order::STATUS_COMPLETED],
            [$users[0], $s2, 'https://instagram.com/p/abc123',      500, Order::STATUS_PROCESSING],
            [$users[1], $s1, 'https://instagram.com/mariasantos',  2000, Order::STATUS_COMPLETED],
            [$users[1], $s3, 'https://tiktok.com/@mariasantos',    1000, Order::STATUS_PENDING],
            [$users[2], $s4, 'https://tiktok.com/@pedrocosta',    10000, Order::STATUS_PENDING],
            [$admin,    $s1, 'https://instagram.com/admintest',     500, Order::STATUS_COMPLETED],
        ] as [$user, $svc, $url, $qty, $status]) {
            $o = new Order();
            $o->setUser($user)->setService($svc)
                ->setTargetUrl($url)->setQuantity($qty)
                ->setAmountCents($svc->calculatePriceCents($qty))
                ->setStatus($status)
                ->setExternalOrderId($status !== Order::STATUS_PENDING ? 'EXT-'.random_int(10000,99999) : null);
            $em->persist($o);
        }

        // ── Pagamentos ───────────────────────────────────────────────────
        $now = new \DateTimeImmutable();
        foreach ([
            [$users[0], Payment::TYPE_DEPOSIT, Payment::METHOD_PIX,         15_000_00,    45_00, Payment::STATUS_APPROVED, $now],
            [$users[1], Payment::TYPE_DEPOSIT, Payment::METHOD_CREDIT_CARD,  8_500_00,   297_50, Payment::STATUS_APPROVED, $now],
            [$users[2], Payment::TYPE_DEPOSIT, Payment::METHOD_PIX,            500_00,     1_50, Payment::STATUS_PENDING,  null],
        ] as [$user, $type, $method, $amount, $fee, $status, $paidAt]) {
            $p = new Payment();
            $p->setUser($user)->setType($type)->setMethod($method)
                ->setAmountCents($amount)->setFeeCents($fee)
                ->setStatus($status)->setExternalId('PAY-'.random_int(100000,999999));
            if ($paidAt) { $p->setPaidAt($paidAt); }
            $em->persist($p);
        }

        // ── Lead/Contato CRM ────────────────────────────────────────────
        $ticket = (new Contact())
            ->setName($users[0]->getName())
            ->setEmail($users[0]->getEmail())
            ->setSource('painel')
            ->setStatus(Contact::STATUS_NEW)
            ->setNotes('Pedido não processado após 2 horas.');
        $em->persist($ticket);

        $em->flush();
    }

    private function createWallet(ObjectManager $em, User $user, int $balanceCents): void
    {
        $wallet = (new Wallet())->setUser($user)->setBalanceCents($balanceCents);
        $em->persist($wallet);

        if ($balanceCents > 0) {
            $tx = (new WalletTransaction())
                ->setWallet($wallet)
                ->setType(TransactionType::CREDIT)
                ->setAmountCents($balanceCents)
                ->setBalanceAfterCents($balanceCents)
                ->setDescription('Saldo inicial (seed)');
            $em->persist($tx);
        }
    }

    private function createCrmContact(
        ObjectManager $em, User $user, array $tags,
        ?string $src, ?string $camp, ?string $med,
    ): void {
        $c = new CrmContact($user);
        foreach ($tags as $tag) { $c->addTag($tag); }
        if ($src)  { $c->setUtmSource($src); }
        if ($camp) { $c->setUtmCampaign($camp); }
        if ($med)  { $c->setUtmMedium($med); }
        $c->addEvent('user.registered', ['seed' => true]);
        $em->persist($c);
    }
}
