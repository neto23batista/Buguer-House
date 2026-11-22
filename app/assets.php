<?php

declare(strict_types=1);

/** Content-addressed URLs keep a fresh page paired with its CSS and JavaScript. */
function assetUrl(string $path, string $prefix = ''): string
{
    if (!preg_match('/\Aassets\/[a-zA-Z0-9_\/.\-]+\.(?:css|js)\z/', $path)
        || array_intersect(explode('/', $path), ['.', '..'])
        || !in_array($prefix, ['', '../'], true)) {
        throw new InvalidArgumentException('Caminho de asset inválido.');
    }
    static $versions = [];
    if (!isset($versions[$path])) {
        $digest = hash_file('sha256', __DIR__ . '/../public/' . $path);
        if ($digest === false) {
            throw new RuntimeException('Asset ausente: ' . $path);
        }
        $versions[$path] = substr($digest, 0, 16);
    }
    return $prefix . $path . '?v=' . $versions[$path];
}
