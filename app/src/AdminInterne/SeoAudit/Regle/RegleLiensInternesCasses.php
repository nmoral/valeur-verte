<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit\Regle;

use App\AdminInterne\SeoAudit\Constat;

/**
 * Liens internes cassés **dans le périmètre crawlé** (#345). Un lien qui pointe vers
 * une page que l'audit a bien récupérée mais qui répond en erreur (4xx/5xx) est un
 * lien mort avéré → erreur. On ne juge que les cibles **présentes dans le périmètre**
 * (URL crawlée) : un lien vers une page non crawlée ou un domaine externe n'est pas
 * vérifiable ici et n'est pas signalé (bornage volontaire, idempotence).
 */
final class RegleLiensInternesCasses implements RegleTransverse
{
    public function code(): string
    {
        return 'liens-internes';
    }

    public function evaluer(array $pages): array
    {
        $codeParUrl = [];
        foreach ($pages as $page) {
            $codeParUrl[$this->normaliser($page->url)] = $page->codeHttp;
        }

        $casses = [];
        foreach ($pages as $page) {
            foreach ($page->dom()->filter('a')->links() as $lien) {
                $cible = $this->normaliser($lien->getUri());
                $code = $codeParUrl[$cible] ?? null;
                if (null !== $code && $code >= 400) {
                    $casses[$cible] = $code;
                }
            }
        }

        if ([] === $casses) {
            return [Constat::ok($this->code(), 'Aucun lien interne cassé dans le périmètre.')];
        }

        $details = [];
        foreach ($casses as $url => $code) {
            $details[] = \sprintf('%s (%d)', $url, $code);
        }

        return [Constat::erreur($this->code(), 'Lien(s) interne(s) cassé(s) : '.implode(', ', $details).'.')];
    }

    /**
     * Ôte le fragment (#ancre) : la même page avec deux ancres n'est pas deux cibles.
     */
    private function normaliser(string $url): string
    {
        $position = strpos($url, '#');

        return false === $position ? $url : substr($url, 0, $position);
    }
}
