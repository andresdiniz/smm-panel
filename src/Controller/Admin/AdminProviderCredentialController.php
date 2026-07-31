<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ProviderCredential;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/provedores', name: 'admin_provider_credential_')]
#[IsGranted('ROLE_ADMIN')]
class AdminProviderCredentialController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/provider_credential/index.html.twig', [
            'credentials' => $this->em->getRepository(ProviderCredential::class)->findAll(),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(ProviderCredential $credential, Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $credential->setType($request->request->get('type', $credential->getType()));
            $credential->setSlug($request->request->get('slug', $credential->getSlug()));
            $credential->setBaseUrl($request->request->get('baseUrl', $credential->getBaseUrl()));
            $credential->setApiKey($request->request->get('apiKey', $credential->getApiKey()));
            $credential->setSecretToken($request->request->get('secretToken') ?: null);
            $credential->setActive((bool) $request->request->get('active', false));
            $this->em->flush();
            $this->addFlash('success', 'Provedor atualizado.');
            return $this->redirectToRoute('admin_provider_credential_index');
        }
        return $this->render('admin/provider_credential/edit.html.twig', ['credential' => $credential]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $credential = new ProviderCredential();
            $credential->setType($request->request->get('type', ProviderCredential::TYPE_SMM));
            $credential->setSlug($request->request->get('slug', ''));
            $credential->setBaseUrl($request->request->get('baseUrl', ''));
            $credential->setApiKey($request->request->get('apiKey', ''));
            $credential->setSecretToken($request->request->get('secretToken') ?: null);
            $credential->setActive((bool) $request->request->get('active', true));
            $this->em->persist($credential);
            $this->em->flush();
            $this->addFlash('success', 'Provedor criado.');
            return $this->redirectToRoute('admin_provider_credential_index');
        }
        return $this->render('admin/provider_credential/edit.html.twig', ['credential' => null]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(ProviderCredential $credential, Request $request): Response
    {
        if ($this->isCsrfTokenValid('delete_prov_'.$credential->getId(), $request->request->get('_token'))) {
            $this->em->remove($credential);
            $this->em->flush();
            $this->addFlash('success', 'Provedor removido.');
        }
        return $this->redirectToRoute('admin_provider_credential_index');
    }
}
