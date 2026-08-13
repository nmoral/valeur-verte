<?php

declare(strict_types=1);

namespace App\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;

/**
 * Cache-busting par hash de contenu : « asset('css/x.css') » devient
 * « /css/x.css?v=<hash> ». Le hash ne change que si le fichier change, donc le
 * cache navigateur reste optimal tout en se rafraîchissant à chaque modification.
 * Aucun pipeline de build requis : le hash est calculé depuis le fichier servi.
 */
final class VersionParContenu implements VersionStrategyInterface
{
    /**
     * @var array<string, string>
     */
    private array $versions = [];

    public function __construct(
        private readonly string $repertoirePublic,
    ) {
    }

    public function getVersion(string $path): string
    {
        return $this->versions[$path] ??= $this->hacher($path);
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);
        if ('' === $version) {
            return $path;
        }

        $separateur = str_contains($path, '?') ? '&' : '?';

        return $path.$separateur.'v='.$version;
    }

    private function hacher(string $path): string
    {
        $chemin = explode('?', $path, 2)[0];
        $fichier = $this->repertoirePublic.'/'.ltrim($chemin, '/');
        if (!is_file($fichier)) {
            return '';
        }

        $hash = hash_file('xxh128', $fichier);

        return false === $hash ? '' : substr($hash, 0, 10);
    }
}
