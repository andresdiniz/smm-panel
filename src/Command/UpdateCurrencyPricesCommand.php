<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ProviderCredential;
use App\Service\ServicePriceSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:currency:update-prices',
    description: 'Atualiza cotações via API e recalcula os preços de todos os serviços usando o lucro individual.',
)]
final class UpdateCurrencyPricesCommand extends Command
{
    private const RATES_URL = 'https://open.er-api.com/v6/latest/BRL';

    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly HttpClientInterface     $httpClient,
        private readonly ServicePriceSyncService $priceSync,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $credentials = $this->em->getRepository(ProviderCredential::class)->findBy([
            'type' => ProviderCredential::TYPE_SMM,
            'active' => true,
        ]);

        if (!$credentials) {
            $io->warning('Nenhum provedor SMM ativo encontrado.');
            return Command::SUCCESS;
        }

        try {
            $response = $this->httpClient->request('GET', self::RATES_URL);
            $data = $response->toArray();
        } catch (\Throwable $e) {
            $io->error('Não foi possível obter as cotações: '.$e->getMessage());
            return Command::FAILURE;
        }

        $rates = $data['rates'] ?? [];
        $updatedCurrencies = 0;

        foreach ($credentials as $credential) {
            $currency = $credential->getCurrency();

            if ($currency === 'BRL') {
                $credential->setExchangeRate(1.0);
                continue;
            }

            $brlPerUnit = isset($rates[$currency]) && $rates[$currency] > 0
                ? 1 / (float) $rates[$currency]
                : null;

            if ($brlPerUnit === null) {
                $io->warning("Cotação não encontrada para {$currency}.");
                continue;
            }

            $credential->setExchangeRate($brlPerUnit);
            $updatedCurrencies++;
            $io->text(sprintf('%s: 1 %s = R$ %s', $credential->getSlug(), $currency, number_format($brlPerUnit, 4, ',', '.')));
        }

        $this->em->flush();

        $services = $this->em->createQuery(
            'SELECT s FROM App\\Entity\\Service s
             WHERE s.providerSlug IS NOT NULL
               AND s.externalServiceId IS NOT NULL'
        )->getResult();

        $updatedServices = 0;
        $errors = 0;

        foreach ($services as $service) {
            try {
                $result = $this->priceSync->syncOne((int) $service->getId());
                $updatedServices++;
                $io->text(sprintf(
                    'Serviço #%d atualizado: R$ %s (lucro %s%%)',
                    $service->getId(),
                    number_format($result['new_price_cents'] / 100, 2, ',', '.'),
                    number_format($result['profit_percent'], 2, ',', '.')
                ));
            } catch (\Throwable $e) {
                $errors++;
                $io->warning(sprintf('Erro no serviço #%d: %s', $service->getId(), $e->getMessage()));
            }
        }

        $io->success(sprintf(
            'Cotações atualizadas: %d. Serviços atualizados: %d. Erros: %d.',
            $updatedCurrencies,
            $updatedServices,
            $errors
        ));

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
