<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ProviderCredential;
use App\Entity\Service;
use App\Entity\ServiceCategory;
use App\Repository\ProviderCredentialRepository;
use App\Repository\ServiceCategoryRepository;
use App\Repository\ServiceRepository;
use App\Smm\DynamicSmmProviderLoader;
use App\Smm\Exception\ProviderTechnicalException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Importação de serviços via API dos provedores SMM.
 *
 * Rotas fora do prefixo /admin para evitar conflito com EasyAdmin:
 *   GET  /admin/imports/services        → index (tela principal)
 *   POST /admin/imports/services/fetch  → busca API do provedor
 *   POST /admin/imports/services/save   → salva no banco
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/imports/services', name: 'admin_service_import')]
class ServiceImportController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface       $em,
        private readonly ProviderCredentialRepository $credentialRepo,
        private readonly ServiceCategoryRepository    $categoryRepo,
        private readonly ServiceRepository            $serviceRepo,
        private readonly DynamicSmmProviderLoader     $providerLoader,
        private readonly LoggerInterface              $logger,
    ) {}

    /** Tela principal */
    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $providers  = $this->credentialRepo->findBy(
            ['type' => ProviderCredential::TYPE_SMM, 'active' => true],
            ['slug' => 'ASC'],
        );
        $categories = $this->categoryRepo->findAllActive();

        return $this->render('admin/service_import/index.html.twig', [
            'providers'  => $providers,
            'categories' => $categories,
        ]);
    }

    /** Busca serviços da API do provedor. Retorna JSON. */
    #[Route('/fetch', name: '_fetch', methods: ['POST'])]
    public function fetch(Request $request): JsonResponse
    {
        $csrfToken = $request->request->get('_token');
        if (!is_string($csrfToken) || !$this->isCsrfTokenValid('provider_services_fetch', $csrfToken)) {
            return $this->json(['error' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $slug = $request->request->get('provider_slug');
        if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9_-]{0,59}$/i', $slug) !== 1) {
            return $this->json(['error' => 'Provedor inválido.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $provider = $this->providerLoader->loadBySlug($slug);
            if ($provider === null) {
                return $this->json(['error' => 'Provedor ativo não encontrado.'], Response::HTTP_NOT_FOUND);
            }
            $services = array_map(static fn(array $service): array => [
                'external_id' => $service['service'],
                'name' => $service['name'],
                'category' => $service['category'],
                'provider_price' => (float) $service['rate'],
                'min_qty' => (int) $service['min'],
                'max_qty' => (int) $service['max'],
            ], $provider->getServices());
        } catch (ProviderTechnicalException $e) {
            $this->logger->warning('Falha ao buscar serviços SMM.', [
                'provider' => strtolower($slug),
                'exception' => $e::class,
            ]);
            return $this->json(['error' => 'Não foi possível consultar os serviços do provedor.'], Response::HTTP_BAD_GATEWAY);
        }

        $existing = $this->serviceRepo->findBy(['providerSlug' => strtolower($slug)]);
        $existingIds = array_map(fn(Service $s) => $s->getExternalServiceId(), $existing);

        return $this->json([
            'services'    => $services,
            'existingIds' => $existingIds,
        ]);
    }

    /** Salva os serviços selecionados (upsert). */
    #[Route('/save', name: '_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $content = $request->getContent();
        if (strlen($content) > 2_097_152) {
            return $this->json(['error' => 'Payload excede o limite permitido.'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(['error' => 'Payload JSON inválido.'], Response::HTTP_BAD_REQUEST);
        }

        $csrfToken = is_array($data) ? ($data['_token'] ?? null) : null;
        if (!is_array($data) || !is_string($csrfToken) || !$this->isCsrfTokenValid('provider_services_save', $csrfToken)) {
            return $this->json(['error' => 'Token inválido.'], Response::HTTP_FORBIDDEN);
        }

        $slug = $data['provider_slug'] ?? null;
        $defaultCatId = $data['category_id'] ?? null;
        $defaultMarkup = $data['default_markup_percent'] ?? 30;
        $servicesData = $data['services'] ?? null;

        if (
            !is_string($slug)
            || preg_match('/^[a-z0-9][a-z0-9_-]{0,59}$/i', $slug) !== 1
            || (!is_int($defaultCatId) && $defaultCatId !== null)
            || !is_int($defaultMarkup)
            || $defaultMarkup < 0
            || $defaultMarkup > 1000
            || !is_array($servicesData)
            || $servicesData === []
            || count($servicesData) > 500
        ) {
            return $this->json(['error' => 'Dados de importação inválidos.'], Response::HTTP_BAD_REQUEST);
        }

        $slug = strtolower($slug);
        if ($this->providerLoader->loadBySlug($slug) === null) {
            return $this->json(['error' => 'Provedor ativo não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $defaultCategory = $defaultCatId === null ? null : $this->categoryRepo->find($defaultCatId);
        if ($defaultCatId !== null && !$defaultCategory instanceof ServiceCategory) {
            return $this->json(['error' => 'Categoria padrão inválida.'], Response::HTTP_BAD_REQUEST);
        }

        $categoriesById = [];
        foreach ($this->categoryRepo->findAllActive() as $category) {
            $categoriesById[$category->getId()] = $category;
        }

        $normalized = [];
        $seenIds = [];
        foreach ($servicesData as $item) {
            if (!is_array($item)) {
                return $this->json(['error' => 'Serviço inválido no payload.'], Response::HTTP_BAD_REQUEST);
            }

            $externalId = $item['external_id'] ?? null;
            $name = $item['name'] ?? null;
            $price = $item['provider_price'] ?? null;
            $markup = $item['markup_percent'] ?? $defaultMarkup;
            $minQty = $item['custom_min_qty'] ?? $item['min_qty'] ?? 10;
            $maxQty = $item['max_qty'] ?? 100000;
            $categoryId = $item['category_id'] ?? $defaultCatId;

            if (
                !is_string($externalId)
                || $externalId === ''
                || strlen($externalId) > 100
                || isset($seenIds[$externalId])
                || !is_string($name)
                || $name === ''
                || mb_strlen($name) > 200
                || (!is_int($markup) || $markup < 0 || $markup > 1000)
                || (!is_int($minQty) || $minQty < 1 || $minQty > 2_147_483_647)
                || (!is_int($maxQty) || $maxQty < $minQty || $maxQty > 2_147_483_647)
                || (!is_int($categoryId) && $categoryId !== null)
                || (!is_numeric($price) || !is_finite((float) $price) || (float) $price <= 0)
            ) {
                return $this->json(['error' => 'Um ou mais serviços possuem dados inválidos.'], Response::HTTP_BAD_REQUEST);
            }

            $sellPrice = (float) $price * (1 + $markup / 100) * 100;
            if (!is_finite($sellPrice) || $sellPrice > PHP_INT_MAX) {
                return $this->json(['error' => 'Preço do serviço fora do intervalo permitido.'], Response::HTTP_BAD_REQUEST);
            }

            $category = $categoryId === null ? null : ($categoriesById[$categoryId] ?? null);
            if ($categoryId !== null && !$category instanceof ServiceCategory) {
                return $this->json(['error' => 'Categoria de serviço inválida.'], Response::HTTP_BAD_REQUEST);
            }

            $categoryName = $category?->getName()
                ?? ($defaultCategory instanceof ServiceCategory ? $defaultCategory->getName() : null)
                ?? (is_string($item['category_name'] ?? null) ? mb_substr($item['category_name'], 0, 100) : null);

            $normalized[] = [
                'externalId' => $externalId,
                'name' => $name,
                'priceCents' => (int) round($sellPrice),
                'minQty' => $minQty,
                'maxQty' => $maxQty,
                'categoryName' => $categoryName,
            ];
            $seenIds[$externalId] = true;
        }

        $existing = $this->serviceRepo->findBy(['providerSlug' => $slug]);
        $existingById = [];
        foreach ($existing as $service) {
            $existingById[$service->getExternalServiceId()] = $service;
        }

        $saved = 0;
        $updated = 0;
        $this->em->wrapInTransaction(function () use ($normalized, $existingById, $slug, &$saved, &$updated): void {
            foreach ($normalized as $item) {
                $service = $existingById[$item['externalId']] ?? null;
                if ($service === null) {
                    $service = (new Service())
                        ->setProviderSlug($slug)
                        ->setExternalServiceId($item['externalId']);
                    $this->em->persist($service);
                    $saved++;
                } else {
                    $updated++;
                }

                $service
                    ->setName($item['name'])
                    ->setPricePerThousandCents($item['priceCents'])
                    ->setMinQty($item['minQty'])
                    ->setMaxQty($item['maxQty'])
                    ->setActive(true)
                    ->setCategory($item['categoryName']);
            }
        });

        return $this->json([
            'saved' => $saved,
            'updated' => $updated,
            'total' => $saved + $updated,
        ]);
    }
}
