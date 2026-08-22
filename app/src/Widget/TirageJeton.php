<?php

declare(strict_types=1);

namespace App\Widget;

/**
 * Tirage d'un jeton de page publique (#38). Abstraction du seul geste aléatoire
 * de la génération, pour que l'unicité — elle, déterministe — s'éprouve sans
 * dépendre du hasard.
 */
interface TirageJeton
{
    public function tirer(): string;
}
