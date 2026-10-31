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
            return $this->save($request, $credential);
        }

        return $this->render('admin/provider_credential/edit.html.twig', [
            'credential' => $credential,
            'apiKeyConfigured' => $credential->getApiKey() !== '',
            'csrfTokenId' => 'provider_credential_edit_'.$credential->getId(),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->save($request);
        }

        return $this->render('admin/provider_credential/edit.html.twig', [
            'credential' => null,
            'apiKeyConfigured' => false,
            'csrfTokenId' => 'provider_credential_new',
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'])]
    public function delete(ProviderCredential $credential, Request $request): Response
    {
        $token = $request->request->get('_token');
        if (is_string($token) && $this->isCsrfTokenValid('delete_prov_'.$credential->getId(), $token)) {
            $this->em->remove($credential);
            $this->em->flush();
            $this->addFlash('success', 'Provedor removido.');
        }
        return $this->redirectToRoute('admin_provider_credential_index');
    }

    private function save(Request $request, ?ProviderCredential $credential = null): Response
    {
        $tokenId = $credential === null
            ? 'provider_credential_new'
            : 'provider_credential_edit_'.$credential->getId();

        $csrfToken = $request->request->get('_token');
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid($tokenId, $csrfToken)) {
            $this->addFlash('error', 'Formulário expirado ou inválido. Tente novamente.');
            return $this->redirectToRoute(
                $credential === null ? 'admin_provider_credential_new' : 'admin_provider_credential_edit',
                $credential === null ? [] : ['id' => $credential->getId()],
            );
        }

        $type = $this->stringField($request, 'type');
        $slug = $this->stringField($request, 'slug');
        $baseUrl = $this->stringField($request, 'baseUrl');
        $apiKey = $this->stringField($request, 'apiKey');
        $secretToken = $this->stringField($request, 'secretToken');

        if (
            !in_array($type, [ProviderCredential::TYPE_SMM, ProviderCredential::TYPE_PAYMENT], true)
            || $slug === null
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,59}$/', strtolower($slug)) !== 1
            || $baseUrl === null
            || strlen($baseUrl) > 512
            || !$this->isValidProviderUrl($baseUrl)
            || $apiKey === null
            || strlen($apiKey) > 512
            || ($credential === null && $apiKey === '')
            || ($secretToken !== null && strlen($secretToken) > 512)
        ) {
            $this->addFlash('error', 'Dados inválidos. Informe tipo, slug, URL HTTPS pública e credenciais válidas.');
            return $this->redirectToRoute(
                $credential === null ? 'admin_provider_credential_new' : 'admin_provider_credential_edit',
                $credential === null ? [] : ['id' => $credential->getId()],
            );
        }

        $slug = strtolower($slug);
        $existing = $this->em->getRepository(ProviderCredential::class)->findOneBy([
            'type' => $type,
            'slug' => $slug,
        ]);
        if ($existing !== null && $existing !== $credential) {
            $this->addFlash('error', 'Já existe um provedor com esse tipo e slug.');
            return $this->redirectToRoute(
                $credential === null ? 'admin_provider_credential_new' : 'admin_provider_credential_edit',
                $credential === null ? [] : ['id' => $credential->getId()],
            );
        }

        $credential ??= new ProviderCredential();
        $credential
            ->setType($type)
            ->setSlug($slug)
            ->setBaseUrl($baseUrl)
            ->setActive($request->request->has('active'));

        if ($apiKey !== '') {
            $credential->setApiKey($apiKey);
        }
        if ($secretToken !== null && $secretToken !== '') {
            $credential->setSecretToken($secretToken);
        }

        $this->em->persist($credential);
        $this->em->flush();
        $this->addFlash('success', 'Provedor atualizado.');

        return $this->redirectToRoute('admin_provider_credential_index');
    }

    private function stringField(Request $request, string $name): ?string
    {
        $value = $request->request->get($name);

        return is_string($value) ? trim($value) : null;
    }

    private function isValidProviderUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $ip = trim($host, '[]');

        return $host !== ''
            && (filter_var($ip, FILTER_VALIDATE_IP) !== false || str_contains($host, '.'))
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && (!isset($parts['port']) || $parts['port'] === 443)
            && !in_array($host, ['localhost', 'localhost.localdomain'], true)
            && preg_match('/\.(local|internal|test|invalid|example)$/i', $host) !== 1
            && (filter_var($ip, FILTER_VALIDATE_IP) === false
                || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);
    }
}
