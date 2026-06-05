<?php

declare(strict_types=1);

namespace App\Smm\Provider;

final class SmmProviderRegistry
{
    /** @var array<string, SmmProviderInterface> */
    private array $providers = [];

    /** Tagged service injection via DI */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->getSlug()] = $provider;
        }
    }

    public function get(string $slug): SmmProviderInterface
    {
        return $this->providers[$slug]
            ?? throw new \InvalidArgumentException("SMM Provider '{$slug}' not registered.");
    }

    public function all(): array
    {
        return $this->providers;
    }

    public function slugs(): array
    {
        return array_keys($this->providers);
    }
}
