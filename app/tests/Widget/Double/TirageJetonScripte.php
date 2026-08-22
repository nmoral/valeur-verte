<?php

declare(strict_types=1);

namespace App\Tests\Widget\Double;

use App\Widget\TirageJeton;

/**
 * Tirage scripté : rend les jetons dans l'ordre convenu, pour éprouver la
 * réaction du générateur à une collision sans dépendre du hasard réel.
 */
final class TirageJetonScripte implements TirageJeton
{
    /** @var list<string> */
    private array $restants;

    /**
     * @param list<string> $jetons
     */
    public function __construct(array $jetons)
    {
        $this->restants = $jetons;
    }

    public function tirer(): string
    {
        $jeton = array_shift($this->restants);
        if (null === $jeton) {
            throw new \LogicException('Le tirage scripté est épuisé : le générateur a tiré plus que prévu.');
        }

        return $jeton;
    }
}
