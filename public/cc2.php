<?php
// DELETE THIS FILE after use — https://acheireviews.com.br/cc2.php

$targets = [
    // Cache PHP compilado antigo do index.html.twig
    __DIR__ . '/../var/cache/dev/twig/fa/fa926bafca22670125ebebb006390160.php',
    __DIR__ . '/../var/cache/prod/twig/fa/fa926bafca22670125ebebb006390160.php',
    // Cache do layout pai
    __DIR__ . '/../var/cache/dev/twig/37/37650c9b3bcf07b9c1489c210ef457c0.php',
    __DIR__ . '/../var/cache/prod/twig/37/37650c9b3bcf07b9c1489c210ef457c0.php',
];

// Apaga todos os arquivos .php do cache Twig recursivamente
$cacheRoots = [
    __DIR__ . '/../var/cache/dev/twig',
    __DIR__ . '/../var/cache/prod/twig',
];

$deleted = [];
$errors  = [];

foreach ($cacheRoots as $root) {
    if (!is_dir($root)) continue;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $path = $f->getRealPath();
        if ($f->isFile()) {
            if (@unlink($path)) { $deleted[] = basename($path); }
            else { $errors[] = $path; }
        }
    }
    // Apaga diretorios vazios
    $it2 = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it2 as $f) {
        if ($f->isDir()) @rmdir($f->getRealPath());
    }
    @rmdir($root);
}

header('Content-Type: text/plain; charset=utf-8');
echo 'DELETED: ' . count($deleted) . " arquivos\n";
if ($errors) {
    echo 'ERRORS (' . count($errors) . "):\n";
    foreach ($errors as $e) echo "  $e\n";
}
echo "\nProximo request recompila tudo. DELETE este arquivo!\n";
// Auto-deleta este script apos execucao
@unlink(__FILE__);
echo "cc2.php auto-deletado.\n";
