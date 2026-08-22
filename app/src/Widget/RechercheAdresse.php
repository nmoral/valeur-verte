<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * Port : propose des adresses correspondant à une saisie, chacune avec le code
 * INSEE de sa commune (#287).
 *
 * La source est publique et faillible : l'implémentation ne lève jamais, elle
 * renvoie une liste vide. Le parcours doit survivre à une BAN indisponible —
 * l'autocomplétion est une aide, pas une porte.
 */
interface RechercheAdresse
{
    /**
     * @return list<AdresseSuggeree>
     */
    public function suggerer(string $requete): array;
}
