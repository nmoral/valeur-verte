<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * Slug d'une page publique (#38) : l'habillage lisible de l'URL, et rien d'autre.
 *
 * Il n'entre pas dans la résolution de la page — c'est le jeton qui l'identifie
 * (#40) — donc **aucune unicité** ne lui est imposée : deux agences homonymes
 * peuvent porter le même. Mais il voyage dans une URL et tient dans une colonne :
 * la saisie du pro est rendue lisible (minuscules, accents dépliés, tirets) et
 * bornée, plutôt que recopiée telle quelle.
 */
final readonly class SlugPage
{
    private function __construct(private string $valeur)
    {
    }

    /**
     * @throws \InvalidArgumentException si la saisie ne laisse aucun slug ou dépasse la borne
     */
    public static function depuisSaisie(string $saisie): self
    {
        $slug = self::normaliser($saisie);

        if ('' === $slug) {
            throw new \InvalidArgumentException('Le slug ne peut pas être vide : il habille votre lien public.');
        }

        if (mb_strlen($slug) > PagePublique::LONGUEUR_MAX_SLUG) {
            throw new \InvalidArgumentException(\sprintf('Le slug est limité à %d caractères.', PagePublique::LONGUEUR_MAX_SLUG));
        }

        return new self($slug);
    }

    public function valeur(): string
    {
        return $this->valeur;
    }

    /**
     * Déplie les accents, met en minuscules, et réduit tout le reste à des tirets
     * simples — « Agence Durand » devient « agence-durand ».
     */
    private static function normaliser(string $saisie): string
    {
        $translitere = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $saisie);
        if (!\is_string($translitere)) {
            $translitere = mb_strtolower($saisie);
        }

        $slug = preg_replace('/[^a-z0-9]+/', '-', $translitere) ?? '';

        return trim($slug, '-');
    }
}
