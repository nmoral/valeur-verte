<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * La page publique du pro courant (#38, épic #6), et son adresse.
 *
 * Un pro **a** une page : elle naît avec son compte ({@see PagePubliqueParDefaut}).
 * Les comptes antérieurs à #38 n'en ont pas — plutôt qu'un écran vide et un bouton
 * « créer ma page », la leur est ouverte à la première visite. Il n'y a rien à
 * demander au pro pour cela : le jeton est tiré, le slug est un habillage.
 */
final readonly class PagePubliqueDuPro
{
    public function __construct(
        private PagePubliqueRepository $pages,
        private GenerateurJetonPagePublique $jetons,
        private string $appDomaine,
    ) {
    }

    public function courante(int $tenantId): PagePublique
    {
        $page = $this->pages->courante();
        if (null !== $page) {
            return $page;
        }

        $page = PagePublique::creer($tenantId, $this->jetons->nouveau(), PagePubliqueParDefaut::SLUG_PAR_DEFAUT);
        $this->pages->enregistrer($page);

        return $page;
    }

    /**
     * Adresse partagée par le pro. Le slug ne sert qu'à l'habillage — c'est le
     * jeton qui résout la page (#40) — mais il fait partie du lien affiché.
     */
    public function lien(PagePublique $page): string
    {
        return \sprintf('https://widget.%s/%s/%s', $this->appDomaine, $page->token(), $page->slug());
    }
}
