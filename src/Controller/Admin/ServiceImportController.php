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
        private readonly HttpClientInterface          $httpClient,
        private readonly EntityManagerInterface       $em,
        private readonly ProviderCredentialRepository $credentialRepo,
        private readonly ServiceCategoryRepository    $categoryRepo,
        private readonly ServiceRepository            $serviceRepo,
    ) {}

    /** Tela principal */
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

    /** Busca serviços da API do provedor. Retorna JSON. */
    #[Route('/fetch', name: '_fetch', methods: ['POST'])]
    public function fetch(Request $request): JsonResponse
    {
        $slug       = $request->request->get('provider_slug', '');
        $credential = $this->credentialRepo->findOneBy(['slug' => $slug, 'type' => ProviderCredential::TYPE_SMM]);

        if (!$credential) {
            return $this->json(['error' => 'Provedor não encontrado: ' . $slug], 404);
        }

        try {
            $services = $this->fetchServicesFromProvider($credential);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Erro ao buscar serviços: ' . $e->getMessage()], 500);
        }

        $existing    = $this->serviceRepo->findBy(['providerSlug' => $slug]);
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
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Payload inválido.'], 400);
        }

        $slug          = $data['provider_slug'] ?? '';
        $defaultCatId  = $data['category_id'] ?? null;
        $defaultMarkup = (int) ($data['default_markup_percent'] ?? 30);
        $servicesData  = $data['services'] ?? [];

        $defaultCategory = $defaultCatId ? $this->categoryRepo->find($defaultCatId) : null;

        $saved   = 0;
        $updated = 0;

        foreach ($servicesData as $item) {
            $externalId    = (string) ($item['external_id'] ?? '');
            $providerPrice = (float)  ($item['provider_price'] ?? 0);
            $markup        = (int)    ($item['markup_percent'] ?? $defaultMarkup);
            $catId         = $item['category_id'] ?? $defaultCatId;

            if (!$externalId || $providerPrice <= 0) {
                continue;
            }

            $sellPriceCents = (int) round($providerPrice * (1 + $markup / 100) * 100);
            $minQty         = (int) ($item['custom_min_qty'] ?? $item['min_qty'] ?? 10);
            $maxQty         = (int) ($item['max_qty'] ?? 100000);

            $categoryName = null;
            if ($catId) {
                $cat = $this->categoryRepo->find($catId);
                $categoryName = $cat instanceof ServiceCategory ? $cat->getName() : null;
            }
            if (!$categoryName && $defaultCategory instanceof ServiceCategory) {
                $categoryName = $defaultCategory->getName();
            }
            if (!$categoryName) {
                $categoryName = (string) ($item['category_name'] ?? '') ?: null;
            }

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

    private function fetchServicesFromProvider(ProviderCredential $credential): array
    {
        $apiUrl = rtrim($credential->getBaseUrl(), '/');
        $apiKey = $credential->getApiKey();

        $response = $this->httpClient->request('POST', $apiUrl, [
            'body' => ['key' => $apiKey, 'action' => 'services'],
        ]);

        $raw = $response->toArray();

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
