<?php

declare(strict_types=1);

namespace App\Tests\Widget\Double;

use App\Widget\AdresseSuggeree;
use App\Widget\RechercheAdresse;

/**
 * Double d'attente de la recherche d'adresse (#287) : suggestions définies par le
 * test, et compteur d'appels pour constater qu'une saisie trop courte n'atteint
 * jamais la source. Aucun appel réseau.
 */
final class RechercheAdresseEnMemoire implements RechercheAdresse
{
    /** @var list<AdresseSuggeree> */
    private array $suggestions = [];

    private int $appels = 0;

    public function definir(AdresseSuggeree ...$suggestions): void
    {
        $this->suggestions = array_values($suggestions);
    }

    public function suggerer(string $requete): array
    {
        ++$this->appels;

        return $this->suggestions;
    }

    public function appels(): int
    {
        return $this->appels;
    }
}
