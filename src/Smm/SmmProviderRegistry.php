<?php

declare(strict_types=1);

namespace App\Smm;

/**
 * Registro de providers SMM identificados por slug.
 * Injeção via factory (DynamicSmmProviderLoader::buildRegistry) em services.yaml.
 *
 * TODOS os slugs são normalizados para strtolower(trim()) na entrada,
 * garantindo que divergências de grafia (ex: "smmoffical" vs "smmoficial")
 * sejam detectadas de forma consistente.
 */
final class SmmProviderRegistry
{
    /** @var array<string, SmmProviderInterface> */
    private array $providers = [];

    /** @param iterable<string, SmmProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $slug => $provider) {
            $this->providers[self::normalize($slug)] = $provider;
        }
    }

    public function get(string $slug): SmmProviderInterface
    {
        $key = self::normalize($slug);
        return $this->providers[$key]
            ?? throw new \InvalidArgumentException(
                sprintf(
                    'Provider SMM desconhecido: "%s". Slugs registrados: [%s]',
                    $slug,
                    implode(', ', array_keys($this->providers))
                )
            );
    }

    public function has(string $slug): bool
    {
        return isset($this->providers[self::normalize($slug)]);
    }

    /** @return string[] */
    public function slugs(): array
    {
        return array_keys($this->providers);
    }

    private static function normalize(string $slug): string
    {
        return strtolower(trim($slug));
    }
}
