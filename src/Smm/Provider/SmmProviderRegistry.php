<?php

declare(strict_types=1);

namespace App\Smm\Provider;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class SmmProviderRegistry
{
    /** @var array<string, SmmProviderInterface> */
    private array $map = [];

    public function __construct(
        #[AutowireIterator('smm.provider')]
        iterable $providers,
    ) {
        foreach ($providers as $provider) {
            $this->map[$provider->getSlug()] = $provider;
        }
    }

    public function get(string $slug): SmmProviderInterface
    {
        return $this->map[$slug]
            ?? throw new \InvalidArgumentException("SMM Provider '{$slug}' not found.");
    }

    /** @return array<string, SmmProviderInterface> */
    public function all(): array
    {
        return $this->map;
    }

    /** @return string[] */
    public function slugs(): array
    {
        return array_keys($this->map);
    }

    public function has(string $slug): bool
    {
        return isset($this->map[$slug]);
    }
}
