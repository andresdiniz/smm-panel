<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ProviderCredential;
use App\Entity\Service;
use App\Entity\ServiceCategory;
use App\Repository\ProviderCredentialRepository;
use App\Repository\ServiceCategoryRepository;
use App\Repository\ServiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Importação de serviços via API dos provedores SMM.
 *
 * Fluxo:
 *   1. GET  /admin/service-import           → tela principal (selecionar provedor)
 *   2. POST /admin/service-import/fetch      → busca lista bruta da API do provedor (JSON)
 *   3. POST /admin/service-import/save       → salva os serviços selecionados no banco
 */
#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/service-import', name: 'admin_service_import')]
class ServiceImportController extends AbstractController
{
    public function __construct(
        private readonly HttpClientInterface          $httpClient,
        private readonly EntityManagerInterface       $em,
        private readonly ProviderCredentialRepository $credentialRepo,
        private readonly ServiceCategoryRepository    $categoryRepo,
        private readonly ServiceRepository            $serviceRepo,
    ) {}

    /** Tela principal: lista provedores disponíveis e categorias existentes */
    #[Route('', name: '', methods: ['GET'])]
    public function index(): Response
    {
        $providers  = $this->credentialRepo->findBy(['type' => ProviderCredential::TYPE_SMM], ['slug' => 'ASC']);
        $categories = $this->categoryRepo->findAllActive();

        return $this->render('admin/service_import/index.html.twig', [
            'providers'  => $providers,
            'categories' => $categories,
        ]);
    }

    /**
     * Busca serviços brutos da API do provedor selecionado.
     * Retorna JSON para o frontend processar (tabela de seleção).
     *
     * POST body: { provider_slug: string }
     */
    #[Route('/fetch', name: '_fetch', methods: ['POST'])]
    public function fetch(Request $request): JsonResponse
    {
        $slug       = $request->request->get('provider_slug', '');
        $credential = $this->credentialRepo->findOneBy(['slug' => $slug, 'type' => ProviderCredential::TYPE_SMM]);

        if (!$credential) {
            return $this->json(['error' => 'Provedor não encontrado.'], 404);
        }

        try {
            $services = $this->fetchServicesFromProvider($credential);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Erro ao buscar serviços: ' . $e->getMessage()], 500);
        }

        // Mapeia IDs já importados para marcar na UI
        $existing    = $this->serviceRepo->findBy(['providerSlug' => $slug]);
        $existingIds = array_map(fn(Service $s) => $s->getExternalServiceId(), $existing);

        return $this->json([
            'services'    => $services,
            'existingIds' => $existingIds,
        ]);
    }

    /**
     * Salva os serviços selecionados (upsert).
     *
     * POST body (JSON):
     * {
     *   provider_slug:          string,
     *   mode:                   "batch" | "individual",
     *   category_id:            int|null,
     *   default_markup_percent: int,
     *   services: [
     *     {
     *       external_id:      string,
     *       name:             string,
     *       provider_price:   float,
     *       min_qty:          int,
     *       max_qty:          int,
     *       category_id:      int|null,
     *       markup_percent:   int|null,
     *       custom_min_qty:   int|null,
     *       category_name:    string,   // nome de categoria vindo do provedor (fallback)
     *     }
     *   ]
     * }
     */
    #[Route('/save', name: '_save', methods: ['POST'])]
    public function save(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Payload inválido.'], 400);
        }

        $slug          = $data['provider_slug'] ?? '';
        $mode          = $data['mode'] ?? 'batch';
        $defaultCatId  = $data['category_id'] ?? null;
        $defaultMarkup = (int) ($data['default_markup_percent'] ?? 30);
        $servicesData  = $data['services'] ?? [];

        $defaultCategory = $defaultCatId ? $this->categoryRepo->find($defaultCatId) : null;

        $saved   = 0;
        $updated = 0;

        foreach ($servicesData as $item) {
            $externalId    = (string) ($item['external_id'] ?? '');
            $providerPrice = (float) ($item['provider_price'] ?? 0);
            $markup        = (int) ($item['markup_percent'] ?? $defaultMarkup);
            $catId         = $item['category_id'] ?? $defaultCatId;

            if (!$externalId || $providerPrice <= 0) {
                continue;
            }

            // Preço de venda em centavos = providerPrice * (1 + markup/100) * 100
            $sellPriceCents = (int) round($providerPrice * (1 + $markup / 100) * 100);

            $minQty = (int) ($item['custom_min_qty'] ?? $item['min_qty'] ?? 10);
            $maxQty = (int) ($item['max_qty'] ?? 100000);

            // Resolve categoria: local selecionada → nome da categoria local → nome vindo do provedor
            $categoryName = null;
            if ($catId) {
                $cat = $this->categoryRepo->find($catId);
                $categoryName = $cat instanceof ServiceCategory ? $cat->getName() : null;
            }
            if (!$categoryName && $defaultCategory instanceof ServiceCategory) {
                $categoryName = $defaultCategory->getName();
            }
            if (!$categoryName) {
                // fallback: usa o nome de categoria que veio do provedor
                $categoryName = (string) ($item['category_name'] ?? '') ?: null;
            }

            // Upsert: busca pelo externalId + providerSlug
            $service = $this->serviceRepo->findOneBy([
                'providerSlug'      => $slug,
                'externalServiceId' => $externalId,
            ]);

            if (!$service) {
                $service = new Service();
                $service->setProviderSlug($slug);
                $service->setExternalServiceId($externalId);
                $this->em->persist($service);
                $saved++;
            } else {
                $updated++;
            }

            $service->setName((string) ($item['name'] ?? 'Serviço ' . $externalId));
            $service->setPricePerThousandCents($sellPriceCents);
            $service->setMinQty($minQty);
            $service->setMaxQty($maxQty);
            $service->setActive(true);
            $service->setCategory($categoryName);
        }

        $this->em->flush();

        return $this->json([
            'saved'   => $saved,
            'updated' => $updated,
            'total'   => $saved + $updated,
        ]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Chama a API do provedor (formato SMM padrão: POST action=services).
     * Usa getBaseUrl() + getApiKey() da entity ProviderCredential.
     */
    private function fetchServicesFromProvider(ProviderCredential $credential): array
    {
        $apiUrl = rtrim($credential->getBaseUrl(), '/');
        $apiKey = $credential->getApiKey();

        $response = $this->httpClient->request('POST', $apiUrl, [
            'body' => ['key' => $apiKey, 'action' => 'services'],
        ]);

        $raw = $response->toArray();

        // Normaliza campos comuns entre diferentes provedores SMM
        return array_map(static function (array $item): array {
            return [
                'external_id'    => (string) ($item['service'] ?? $item['id'] ?? ''),
                'name'           => (string) ($item['name'] ?? ''),
                'category'       => (string) ($item['category'] ?? ''),
                'provider_price' => (float)  ($item['rate'] ?? $item['price'] ?? 0),
                'min_qty'        => (int)    ($item['min'] ?? $item['min_order'] ?? 10),
                'max_qty'        => (int)    ($item['max'] ?? $item['max_order'] ?? 100000),
            ];
        }, $raw);
    }
}
