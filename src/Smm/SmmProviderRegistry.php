<?php

declare(strict_types=1);

namespace App\Smm;

/**
 * Registro de providers SMM identificados por slug.
 * Injeção via tagged_iterator em services.yaml.
 */
final class SmmProviderRegistry
{
    /** @var array<string, SmmProviderInterface> */
    private array $providers = [];

    /** @param iterable<SmmProviderInterface> $providers */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $slug => $provider) {
            $this->providers[$slug] = $provider;
        }
    }

    public function get(string $slug): SmmProviderInterface
    {
        return $this->providers[$slug]
            ?? throw new \InvalidArgumentException("Provider SMM desconhecido: {$slug}");
    }

    public function has(string $slug): bool
    {
        return isset($this->providers[$slug]);
    }

    /** @return string[] */
    public function slugs(): array
    {
        return array_keys($this->providers);
    }
}
