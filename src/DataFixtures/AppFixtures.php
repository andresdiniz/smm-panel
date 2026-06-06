<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ProviderCredential;
use App\Entity\User;
use App\Entity\Wallet;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {}

    public function load(ObjectManager $manager): void
    {
        // ── Admin ──────────────────────────────────────────────────────────
        $admin = new User();
        $admin->setName('Administrador');
        $admin->setEmail('admin@pulsesmm.com.br');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword($this->hasher->hashPassword($admin, 'Admin@1234'));
        $admin->setActive(true);
        $manager->persist($admin);

        $adminWallet = new Wallet();
        $adminWallet->setUser($admin);
        $adminWallet->setBalanceCents(0);
        $manager->persist($adminWallet);

        // ── Usuário demo ───────────────────────────────────────────────────
        $demo = new User();
        $demo->setName('Demo User');
        $demo->setEmail('demo@pulsesmm.com.br');
        $demo->setRoles(['ROLE_USER']);
        $demo->setPassword($this->hasher->hashPassword($demo, 'Demo@1234'));
        $demo->setActive(true);
        $manager->persist($demo);

        $demoWallet = new Wallet();
        $demoWallet->setUser($demo);
        $demoWallet->setBalanceCents(5000_00); // R$ 5.000,00 para testes
        $manager->persist($demoWallet);

        // ── Gateway: Asaas Sandbox ─────────────────────────────────────────
        $asaas = new ProviderCredential();
        $asaas->setType(ProviderCredential::TYPE_PAYMENT);
        $asaas->setSlug('asaas');
        $asaas->setBaseUrl('https://sandbox.asaas.com/api/v3');
        $asaas->setApiKey('$aact_SANDBOX_TROQUE_PELA_SUA_CHAVE');
        $asaas->setSecretToken('webhook-secret-sandbox-troque');
        $asaas->setActive(true);
        $manager->persist($asaas);

        // ── Gateway: MercadoPago Sandbox ───────────────────────────────────
        $mp = new ProviderCredential();
        $mp->setType(ProviderCredential::TYPE_PAYMENT);
        $mp->setSlug('mercadopago');
        $mp->setBaseUrl('https://api.mercadopago.com');
        $mp->setApiKey('TEST-TROQUE-PELO-SEU-ACCESS-TOKEN');
        $mp->setSecretToken('webhook-secret-mp-troque');
        $mp->setActive(false); // desativado até configurar
        $manager->persist($mp);

        // ── Gateway: PagBank Sandbox ───────────────────────────────────────
        $pagbank = new ProviderCredential();
        $pagbank->setType(ProviderCredential::TYPE_PAYMENT);
        $pagbank->setSlug('pagbank');
        $pagbank->setBaseUrl('https://sandbox.api.pagseguro.com');
        $pagbank->setApiKey('TROQUE-PELO-SEU-TOKEN-PAGBANK');
        $pagbank->setSecretToken(null);
        $pagbank->setActive(false);
        $manager->persist($pagbank);

        // ── SMM Provider: JustAnotherPanel (demo) ──────────────────────────
        $jap = new ProviderCredential();
        $jap->setType(ProviderCredential::TYPE_SMM);
        $jap->setSlug('justanother');
        $jap->setBaseUrl('https://justanotherpanel.com/api/v2');
        $jap->setApiKey('SUA_CHAVE_JAP_AQUI');
        $jap->setSecretToken(null);
        $jap->setActive(true);
        $manager->persist($jap);

        // ── SMM Provider: SMMKings (desativado até configurar) ─────────────
        $smmkings = new ProviderCredential();
        $smmkings->setType(ProviderCredential::TYPE_SMM);
        $smmkings->setSlug('smmkings');
        $smmkings->setBaseUrl('https://smmkings.com/api/v2');
        $smmkings->setApiKey('SUA_CHAVE_SMMKINGS_AQUI');
        $smmkings->setSecretToken(null);
        $smmkings->setActive(false);
        $manager->persist($smmkings);

        $manager->flush();
    }
}
