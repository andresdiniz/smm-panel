<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Forca o Symfony a usar var/cache RELATIVO ao clone ativo da Hostinger.
     *
     * Por padrao, kernel.project_dir aponta para public_html (o repositorio
     * principal), mas a Hostinger serve cada deploy de um clone isolado em
     * .git_clones/git_clone_XXXXX/. Isso faz o Twig ler o .twig do clone novo
     * mas executar o cache PHP compilado do public_html antigo.
     *
     * Usando dirname(__DIR__) o cache fica dentro do proprio clone,
     * garantindo que cada deploy tenha seu proprio cache Twig compilado.
     */
    public function getCacheDir(): string
    {
        return dirname(__DIR__) . '/var/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__) . '/var/log';
    }
}
