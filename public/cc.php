<?php
// Temporary cache purge helper — DELETE THIS FILE after use
// Access: https://acheireviews.com.br/cc.php

$dirs = [
    __DIR__ . '/../var/cache/dev/twig',
    __DIR__ . '/../var/cache/prod/twig',
];

$deleted = 0;
foreach ($dirs as $dir) {
    if (!is_dir($dir)) continue;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
        $deleted++;
    }
    rmdir($dir);
}

header('Content-Type: text/plain');
echo "OK: $deleted arquivos/pastas removidos do cache Twig.\n";
echo "Twig vai recompilar automaticamente na proxima requisicao.\n";
echo "DELETE este arquivo (public/cc.php) apos uso!\n";
